<?php
/**
 * PHP 8.1+ updater deprecation guard — simulates WP core strpos/str_replace on update metadata.
 * Run: php scripts/test-updater-php81-deprecations.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/../.wp-stub/' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/../.wp-stub/wp-content/plugins' );
define( 'WP_CONTENT_DIR', __DIR__ . '/../.wp-stub/wp-content' );
define( 'PDX_DIR', WP_PLUGIN_DIR . '/paxdesign-toolbar/' );
define( 'PDX_VERSION', '8.5.1' );
define( 'PDX_SLUG', 'paxdesign-toolbar' );

$deprecations = [];

set_error_handler(
	static function ( int $severity, string $message ) use ( &$deprecations ): bool {
		if ( E_DEPRECATED === $severity || E_USER_DEPRECATED === $severity ) {
			$deprecations[] = $message;
			return true;
		}
		return false;
	}
);

function add_filter( ...$args ): void {}
function add_action( ...$args ): void {}
function delete_site_transient( string $key ): void {
	unset( $GLOBALS['pdx_site_transients'][ $key ] );
}
function get_site_transient( string $key ) {
	return $GLOBALS['pdx_site_transients'][ $key ] ?? false;
}
function set_site_transient( string $key, $value ): void {
	$GLOBALS['pdx_site_transients'][ $key ] = $value;
}
function wp_cache_delete( ...$args ): void {}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

require_once __DIR__ . '/../paxdesign-toolbar/includes/class-pdx-updater.php';

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		$file    = str_replace( '\\', '/', $file );
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

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		$parts = parse_url( (string) $url );
		if ( false === $parts ) {
			return -1 === $component ? false : null;
		}
		if ( -1 === $component ) {
			return $parts;
		}
		return $parts[ $component ] ?? null;
	}
}

if ( ! function_exists( 'wp_is_stream' ) ) {
	function wp_is_stream( $path ) {
		return is_string( $path ) && str_contains( $path, '://' );
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
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return is_string( $data ) ? $data : '';
	}
}
if ( ! function_exists( 'get_file_data' ) ) {
	function get_file_data( $file, $headers, $context = '' ) {
		$c   = file_get_contents( $file );
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

/**
 * Mimics common WordPress core uses of update row fields (PHP 8.1 deprecates null).
 */
function simulate_wp_core_update_row_handling( object $row ): void {
	$fields = [ 'url', 'package', 'slug', 'plugin', 'new_version', 'tested', 'requires', 'id' ];
	foreach ( $fields as $field ) {
		$value = $row->$field ?? null;
		strpos( $value, 'http' );
		str_replace( '.php', '', $value );
	}
	if ( isset( $row->icons ) && is_array( $row->icons ) ) {
		foreach ( $row->icons as $icon ) {
			strpos( $icon, 'png' );
		}
	}
}

/** esc_url() / wp_normalize_path() paths used when rendering update rows. */
function simulate_wp_core_url_handling( object $row ): void {
	$url = $row->url ?? null;
	if ( function_exists( 'wp_is_stream' ) ) {
		wp_is_stream( $url );
	}
	str_replace( ' ', '%20', ltrim( (string) $url ) );
	if ( isset( $row->package ) ) {
		strpos( (string) $row->package, '.zip' );
	}
}

$GLOBALS['pdx_test_transients']  = [];
$GLOBALS['pdx_test_options']     = [];
$GLOBALS['pdx_site_transients']  = [];

$plugin_dir = WP_PLUGIN_DIR . '/paxdesign-toolbar';
if ( ! is_dir( $plugin_dir ) ) {
	mkdir( $plugin_dir, 0777, true );
}
$main = $plugin_dir . '/paxdesign-toolbar.php';
file_put_contents(
	$main,
	"<?php\n/**\n * Plugin Name: PaxDesign Utility Dock\n * Version: 8.5.1\n * Text Domain: paxdesign-toolbar\n */\ndefine('PDX_VERSION','8.5.1');\n"
);

$updater = PDX_Updater::instance();
$ref     = new ReflectionClass( $updater );
$inject  = $ref->getMethod( 'inject_update' );
$inject->setAccessible( true );
$sanitize = $ref->getMethod( 'sanitize_stored_update_transient' );
$sanitize->setAccessible( true );

$canonical = $updater->canonical_plugin_basename();
$orphan        = 'paxdesign-toolbar-8.4.3/paxdesign-toolbar.php';
$orphan_nested = 'paxdesign-toolbar-8.6.4/paxdesign-toolbar/paxdesign-toolbar.php';

$corrupt = (object) [
	'url'         => null,
	'package'     => null,
	'slug'        => null,
	'plugin'      => null,
	'new_version' => null,
	'tested'      => null,
	'requires'    => null,
	'id'          => null,
	'icons'       => [ 'default' => null ],
];

$transient = (object) [
	'response'  => [
		$canonical      => clone $corrupt,
		$orphan         => clone $corrupt,
		$orphan_nested  => clone $corrupt,
	],
	'no_update' => [
		$orphan        => clone $corrupt,
		$orphan_nested => clone $corrupt,
	],
];

// 1) sanitize_stored_update_transient must not trigger deprecations and must drop orphan keys.
$sanitize->invoke( $updater, $transient );

if ( isset( $transient->response[ $canonical ] ) ) {
	simulate_wp_core_update_row_handling( $transient->response[ $canonical ] );
	simulate_wp_core_url_handling( $transient->response[ $canonical ] );
}
if ( isset( $transient->no_update[ $canonical ] ) ) {
	simulate_wp_core_update_row_handling( $transient->no_update[ $canonical ] );
}

if ( isset( $transient->response[ $orphan ] ) || isset( $transient->no_update[ $orphan ] )
	|| isset( $transient->response[ $orphan_nested ] ) || isset( $transient->no_update[ $orphan_nested ] ) ) {
	fwrite( STDERR, "FAIL: orphan transient keys were not removed\n" );
	exit( 1 );
}

// Rows that remain must have only string metadata (null coerced).
$check_buckets = [];
if ( isset( $transient->response[ $canonical ] ) ) {
	$check_buckets[] = $transient->response[ $canonical ];
}
if ( isset( $transient->no_update[ $canonical ] ) ) {
	$check_buckets[] = $transient->no_update[ $canonical ];
}
foreach ( $check_buckets as $row ) {
	foreach ( [ 'url', 'package', 'slug', 'plugin', 'new_version', 'tested', 'requires', 'id' ] as $field ) {
		if ( ! property_exists( $row, $field ) || ! is_string( $row->$field ) ) {
			fwrite( STDERR, "FAIL: {$field} is not a string after sanitize\n" );
			exit( 1 );
		}
	}
}

// 2) inject_update with normalized mock release.
set_transient(
	'pdx_github_release',
	[
		'version' => '8.5.2',
		'package' => 'https://github.com/Black10998/paxdesign-toolbar/releases/download/v8.5.2/paxdesign-toolbar-8.5.2.zip',
		'url'     => 'https://github.com/Black10998/paxdesign-toolbar/releases/tag/v8.5.2',
		'name'    => 'PaxDesign Utility Dock',
		'notes'   => '',
		'error'   => null,
	],
	3600
);

$out = $inject->invoke( $updater, (object) [ 'response' => [], 'no_update' => [] ] );
if ( ! isset( $out->response[ $canonical ] ) ) {
	fwrite( STDERR, "FAIL: inject_update did not register update response\n" );
	exit( 1 );
}
simulate_wp_core_update_row_handling( $out->response[ $canonical ] );
simulate_wp_core_url_handling( $out->response[ $canonical ] );

// 2b) inject_update on fetch failure must not leave corrupt no_update rows.
set_transient(
	'pdx_github_release',
	[
		'version' => '',
		'package' => '',
		'url'     => null,
		'name'    => null,
		'notes'   => null,
		'error'   => 'Simulated GitHub failure',
	],
	3600
);
$fail_out = $inject->invoke(
	$updater,
	(object) [
		'response'  => [ $canonical => clone $corrupt ],
		'no_update' => [ $canonical => clone $corrupt ],
	]
);
if ( isset( $fail_out->response[ $canonical ] ) || isset( $fail_out->no_update[ $canonical ] ) ) {
	fwrite( STDERR, "FAIL: inject_update left PDX rows after fetch failure\n" );
	exit( 1 );
}

// 2c) update_plugins_github.com filter returns normalized payload.
set_transient(
	'pdx_github_release',
	[
		'version' => '8.6.1',
		'package' => 'https://github.com/Black10998/paxdesign-toolbar/releases/download/v8.6.1/paxdesign-toolbar-8.6.1.zip',
		'url'     => 'https://github.com/Black10998/paxdesign-toolbar/releases/tag/v8.6.1',
		'name'    => 'PaxDesign Utility Dock',
		'notes'   => '',
		'error'   => '',
	],
	3600
);
$uri_filter = $ref->getMethod( 'filter_update_plugins_uri' );
$uri_filter->setAccessible( true );
$uri_payload = $uri_filter->invoke(
	$updater,
	false,
	[
		'UpdateURI' => 'https://github.com/Black10998/paxdesign-toolbar',
		'Version'   => '8.6.0',
	],
	$canonical,
	[]
);
if ( ! is_array( $uri_payload ) || '' === ( $uri_payload['package'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: filter_update_plugins_uri did not return package URL\n" );
	exit( 1 );
}
if ( '' === ( $uri_payload['new_version'] ?? '' ) || '8.6.1' !== $uri_payload['new_version'] ) {
	fwrite( STDERR, "FAIL: filter_update_plugins_uri must return new_version (not version)\n" );
	exit( 1 );
}
if ( $canonical !== ( $uri_payload['plugin'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: filter_update_plugins_uri missing canonical plugin basename\n" );
	exit( 1 );
}
if ( isset( $uri_payload['version'] ) && '' !== (string) $uri_payload['version'] && empty( $uri_payload['new_version'] ) ) {
	fwrite( STDERR, "FAIL: URI payload must not use version without new_version\n" );
	exit( 1 );
}

// 2d) Malformed version-only transient rows are promoted to new_version on scrub.
$malformed_uri_row = (object) [
	'slug'    => PDX_SLUG,
	'version' => '8.6.1',
	'url'     => 'https://github.com/Black10998/paxdesign-toolbar/releases/tag/v8.6.1',
	'package' => 'https://github.com/Black10998/paxdesign-toolbar/releases/download/v8.6.1/paxdesign-toolbar-8.6.1.zip',
];
$sanitize_obj = $ref->getMethod( 'sanitize_update_object' );
$sanitize_obj->setAccessible( true );
$fixed_row = $sanitize_obj->invoke( $updater, $malformed_uri_row );
if ( '8.6.1' !== ( $fixed_row->new_version ?? '' ) || '' === ( $fixed_row->plugin ?? '' ) ) {
	fwrite( STDERR, "FAIL: sanitize_update_object did not promote version to new_version\n" );
	exit( 1 );
}

// 3) repair_stored_update_transient persists scrubbed site transient.
$GLOBALS['pdx_site_transients']['update_plugins'] = (object) [
	'response'  => [ $orphan => clone $corrupt ],
	'no_update' => [],
];
$updater->repair_stored_update_transient();
$stored = get_site_transient( 'update_plugins' );
if ( is_object( $stored ) && isset( $stored->response[ $orphan ] ) ) {
	fwrite( STDERR, "FAIL: repair_stored_update_transient left orphan corrupt row\n" );
	exit( 1 );
}

// 4) Cached release with null error must be rewritten without deprecation.
set_transient(
	'pdx_github_release',
	[
		'version' => '8.5.2',
		'package' => 'https://example.com/paxdesign-toolbar-8.5.2.zip',
		'url'     => null,
		'name'    => null,
		'notes'   => null,
		'error'   => null,
	],
	3600
);
$fetch = $ref->getMethod( 'fetch_release' );
$fetch->setAccessible( true );
$release = $fetch->invoke( $updater, false );
if ( ! is_string( $release['url'] ) || '' === $release['url'] ) {
	fwrite( STDERR, "FAIL: fetch_release did not normalize null url\n" );
	exit( 1 );
}

// 5) plugins_api must return JSON-safe plugin information (REST/thickbox paths).
$plugins_api = $ref->getMethod( 'plugins_api' );
$plugins_api->setAccessible( true );
$info = $plugins_api->invoke(
	$updater,
	false,
	'plugin_information',
	(object) [ 'slug' => PDX_SLUG ],
);
if ( ! is_object( $info ) ) {
	fwrite( STDERR, "FAIL: plugins_api did not return object\n" );
	exit( 1 );
}
foreach ( [ 'name', 'slug', 'version', 'homepage', 'download_link', 'tested', 'requires', 'requires_php' ] as $field ) {
	if ( property_exists( $info, $field ) && ! is_string( $info->$field ) ) {
		fwrite( STDERR, "FAIL: plugins_api {$field} is not a string\n" );
		exit( 1 );
	}
	strpos( $info->$field ?? '', 'http' );
	str_replace( '.', '', $info->$field ?? '' );
}
if ( isset( $info->sections ) && is_array( $info->sections ) ) {
	foreach ( $info->sections as $section ) {
		strpos( (string) $section, 'PaxDesign' );
	}
}
$info_json = wp_json_encode( $info );
if ( ! is_string( $info_json ) || '' === $info_json || 'null' === $info_json ) {
	fwrite( STDERR, "FAIL: plugins_api object is not JSON-encodable\n" );
	exit( 1 );
}
$decoded = json_decode( $info_json, true );
if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
	fwrite( STDERR, "FAIL: plugins_api JSON decode error: " . json_last_error_msg() . "\n" );
	exit( 1 );
}

// 6) Full update_plugins transient must round-trip as valid JSON (fixes block editor invalid JSON).
$GLOBALS['pdx_site_transients']['update_plugins'] = $out;
$finalized = $ref->getMethod( 'finalize_update_transient' );
$finalized->setAccessible( true );
$final_transient = $finalized->invoke( $updater, clone $out );
$transient_json    = wp_json_encode( $final_transient );
if ( ! is_string( $transient_json ) || '' === $transient_json ) {
	fwrite( STDERR, "FAIL: update_plugins transient is not JSON-encodable\n" );
	exit( 1 );
}
if ( null === json_decode( $transient_json ) ) {
	fwrite( STDERR, "FAIL: update_plugins transient JSON is invalid\n" );
	exit( 1 );
}

restore_error_handler();

if ( $deprecations ) {
	fwrite( STDERR, "FAIL: PHP deprecations emitted:\n" . implode( "\n", $deprecations ) . "\n" );
	exit( 1 );
}

echo "OK: zero PHP 8.1 deprecations in updater transient scrub/inject paths\n";
