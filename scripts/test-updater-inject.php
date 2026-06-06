<?php
/**
 * Standalone smoke test for PDX_Updater::inject_update logic (minimal WP stubs).
 * Run: php scripts/test-updater-inject.php
 */

define( 'ABSPATH', __DIR__ . '/../.wp-stub/' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/../.wp-stub/wp-content/plugins' );
define( 'WP_CONTENT_DIR', __DIR__ . '/../.wp-stub/wp-content' );
define( 'PDX_DIR', WP_PLUGIN_DIR . '/paxdesign-toolbar/' );
define( 'PDX_VERSION', '8.4.1' );
define( 'PDX_SLUG', 'paxdesign-toolbar' );

// Minimal WP function stubs (must exist before PDX_Updater boots).
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function delete_site_transient( $key ) {}
function wp_cache_delete( ...$args ) {}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

require_once __DIR__ . '/../paxdesign-toolbar/includes/class-pdx-updater.php';

// Additional stubs used during inject_update.
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		$file = str_replace( '\\', '/', $file );
		$plugins = str_replace( '\\', '/', WP_PLUGIN_DIR );
		if ( str_starts_with( $file, $plugins ) ) {
			$file = substr( $file, strlen( $plugins ) + 1 );
		}
		return $file;
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $path ) {
		return rtrim( $path, '/\\' );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['pdx_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl ) {
		$GLOBALS['pdx_test_transients'][ $key ] = $value;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['pdx_test_transients'][ $key ] );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['pdx_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['pdx_test_options'][ $key ] = $value;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show ) {
		return '6.7';
	}
}
if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( $file, $headers, $context = '' ) {
		$c = file_get_contents( $file );
		$out = [];
		foreach ( $headers as $key => $header ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $c, $m ) ) {
				$out[ $key ] = trim( $m[1] );
			} else {
				$out[ $key ] = '';
			}
		}
		return $out;
	}
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( $file, $markup = true, $translate = true ) {
		$d = get_file_data( $file, [ 'Name' => 'Plugin Name', 'TextDomain' => 'Text Domain' ], 'plugin' );
		return [
			'Name'       => $d['Name'] ?? '',
			'TextDomain' => $d['TextDomain'] ?? '',
		];
	}
}

$GLOBALS['pdx_test_transients'] = [];
$GLOBALS['pdx_test_options']    = [];

$plugin_dir = WP_PLUGIN_DIR . '/paxdesign-toolbar';
if ( ! is_dir( $plugin_dir ) ) {
	mkdir( $plugin_dir, 0777, true );
}
$main = $plugin_dir . '/paxdesign-toolbar.php';
file_put_contents(
	$main,
	"<?php\n/**\n * Plugin Name: PaxDesign Utility Dock\n * Version: 8.4.1\n * Text Domain: paxdesign-toolbar\n */\ndefine('PDX_VERSION','8.4.1');\n"
);

$updater = PDX_Updater::instance();

// Mock fetch_release via reflection — inject a fake newer release.
$ref = new ReflectionClass( $updater );
$fetch = $ref->getMethod( 'fetch_release' );
$fetch->setAccessible( true );

$mock_release = [
	'version' => '8.4.3',
	'package' => 'https://example.com/paxdesign-toolbar-8.4.3.zip',
	'url'     => 'https://github.com/Black10998/paxdesign-toolbar/releases/tag/v8.4.3',
	'name'    => 'PaxDesign Utility Dock',
	'notes'   => '',
	'error'   => '',
];

// Closure override not easy — test inject_update directly by setting transient cache.
set_transient( 'pdx_github_release', $mock_release, 3600 );

$transient = (object) [
	'response'  => [],
	'no_update' => [],
];

$inject = $ref->getMethod( 'inject_update' );
$inject->setAccessible( true );
$out    = $inject->invoke( $updater, $transient );

$basename = $updater->plugin_basename();
$ok       = isset( $out->response[ $basename ] )
	&& '8.4.3' === $out->response[ $basename ]->new_version
	&& ! isset( $out->no_update[ $basename ] );

if ( ! $ok ) {
	fwrite( STDERR, "FAIL: expected update in response for {$basename}\n" );
	exit( 1 );
}

// Up-to-date path: bump installed version in file.
file_put_contents(
	$main,
	"<?php\n/**\n * Plugin Name: PaxDesign Utility Dock\n * Version: 8.4.3\n * Text Domain: paxdesign-toolbar\n */\ndefine('PDX_VERSION','8.4.3');\n"
);

$out2 = $inject->invoke( $updater, (object) [ 'response' => [ $basename => (object) [ 'new_version' => '8.4.3' ] ], 'no_update' => [] ] );
$ok2  = ! isset( $out2->response[ $basename ] ) && isset( $out2->no_update[ $basename ] );

if ( ! $ok2 ) {
	fwrite( STDERR, "FAIL: expected no_update when installed matches latest\n" );
	exit( 1 );
}

// is_our_plugin must not match null hook_extra.
$is_our = $ref->getMethod( 'is_our_plugin' );
$is_our->setAccessible( true );
if ( $is_our->invoke( $updater, null ) ) {
	fwrite( STDERR, "FAIL: is_our_plugin(null) must be false\n" );
	exit( 1 );
}

echo "OK: updater inject + is_our_plugin guards\n";

// Stale no_update row (old new_version) must not block wp-admin upgrade.
file_put_contents(
	$main,
	"<?php\n/**\n * Plugin Name: PaxDesign Utility Dock\n * Version: 8.6.8\n * Text Domain: paxdesign-toolbar\n */\ndefine('PDX_VERSION','8.6.8');\n"
);

$stale_release = [
	'version' => '8.7.1',
	'package' => 'https://example.com/paxdesign-toolbar-8.7.1.zip',
	'url'     => 'https://github.com/Black10998/paxdesign-toolbar/releases/tag/v8.7.1',
	'name'    => 'PaxDesign Utility Dock',
	'notes'   => '',
	'error'   => '',
];
set_transient( 'pdx_github_release', $stale_release, 3600 );

$stale_transient = (object) [
	'response'  => [],
	'no_update' => [
		$basename => (object) [
			'id'          => 'https://github.com/Black10998/paxdesign-toolbar',
			'slug'        => PDX_SLUG,
			'plugin'      => $basename,
			'new_version' => '8.6.8',
			'url'         => $stale_release['url'],
			'package'     => 'https://example.com/paxdesign-toolbar-8.6.8.zip',
		],
	],
];

$finalize = $ref->getMethod( 'finalize_update_transient' );
$finalize->setAccessible( true );
$out3     = $finalize->invoke( $updater, $stale_transient );

$ok3 = isset( $out3->response[ $basename ] )
	&& '8.7.1' === $out3->response[ $basename ]->new_version
	&& ! isset( $out3->no_update[ $basename ] );

if ( ! $ok3 ) {
	fwrite( STDERR, "FAIL: stale no_update row must move to response with latest GitHub version\n" );
	exit( 1 );
}

echo "OK: stale no_update transient repaired from GitHub release\n";
