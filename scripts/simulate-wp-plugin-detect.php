<?php
/**
 * Simulate WordPress get_plugins() discovery on a release ZIP.
 * Run: php scripts/simulate-wp-plugin-detect.php releases/paxdesign-toolbar-x.y.z.zip
 */
declare( strict_types=1 );

/**
 * @param string $tmp
 */
function cleanup_tmp( string $tmp ): void {
	if ( ! is_dir( $tmp ) ) {
		return;
	}
	if ( PHP_OS_FAMILY === 'Windows' ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $tmp, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $file ) {
			$path = $file->getPathname();
			if ( $file->isDir() ) {
				rmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $tmp );
		return;
	}
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
}

$zip = $argv[1] ?? '';
if ( '' === $zip || ! is_readable( $zip ) ) {
	fwrite( STDERR, "Usage: php simulate-wp-plugin-detect.php <zip>\n" );
	exit( 1 );
}

$tmp = sys_get_temp_dir() . '/pdx-wp-detect-' . bin2hex( random_bytes( 4 ) );
if ( ! mkdir( $tmp ) && ! is_dir( $tmp ) ) {
	fwrite( STDERR, "Could not create temp dir\n" );
	exit( 1 );
}

$extracted = false;
if ( class_exists( 'ZipArchive' ) ) {
	$za = new ZipArchive();
	if ( true === $za->open( $zip ) ) {
		$extracted = true;
		for ( $i = 0; $i < $za->numFiles; $i++ ) {
			$name = str_replace( '\\', '/', (string) $za->getNameIndex( $i ) );
			if ( '' === $name || str_ends_with( $name, '/' ) ) {
				$dir = rtrim( $tmp . '/' . $name, '/' );
				if ( '' !== $dir && ! is_dir( $dir ) ) {
					mkdir( $dir, 0777, true );
				}
				continue;
			}
			$target = $tmp . '/' . $name;
			$parent = dirname( $target );
			if ( ! is_dir( $parent ) ) {
				mkdir( $parent, 0777, true );
			}
			$contents = $za->getFromIndex( $i );
			if ( false === $contents || false === file_put_contents( $target, $contents ) ) {
				$extracted = false;
				break;
			}
		}
		$za->close();
	}
}
if ( ! $extracted ) {
	$zip_esc = escapeshellarg( $zip );
	$tmp_esc = escapeshellarg( $tmp );
	exec( "unzip -q {$zip_esc} -d {$tmp_esc}", $out, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "FAIL: could not extract ZIP for plugin detection test\n" );
		cleanup_tmp( $tmp );
		exit( 1 );
	}
}

$plugins_dir = $tmp;
$main        = $plugins_dir . '/paxdesign-toolbar/paxdesign-toolbar.php';
if ( ! is_readable( $main ) ) {
	fwrite( STDERR, "FAIL: expected {$main} after extract\n" );
	cleanup_tmp( $tmp );
	exit( 1 );
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	/**
	 * @return array<string, string>
	 */
	function get_plugin_data( $file, $markup = true, $translate = true ) { // phpcs:ignore
		$headers = [
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'TextDomain'  => 'Text Domain',
		];
		$data    = [];
		$content = file_get_contents( $file );
		foreach ( $headers as $key => $header ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $content, $m ) ) {
				$data[ $key ] = trim( $m[1] );
			} else {
				$data[ $key ] = '';
			}
		}
		return $data;
	}
}

$data     = get_plugin_data( $main );
$basename = 'paxdesign-toolbar/paxdesign-toolbar.php';

if ( '' === ( $data['Name'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: WordPress would not detect plugin — empty Name header\n" );
	cleanup_tmp( $tmp );
	exit( 1 );
}

echo "OK: WordPress would register [{$basename}] as \"{$data['Name']}\" v{$data['Version']}\n";

cleanup_tmp( $tmp );
exit( 0 );
