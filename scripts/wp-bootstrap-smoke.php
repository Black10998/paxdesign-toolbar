<?php
/**
 * Minimal WordPress stubs to verify the plugin bootstrap parses and loads (CLI smoke test).
 * Usage: php scripts/wp-bootstrap-smoke.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/.smoke-wp/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0777, true );
}

$GLOBALS['wp_version'] = '6.7.2';

function plugin_dir_path( $file ) {
	return trailingslashit( dirname( $file ) );
}

function plugin_dir_url( $file ) {
	return 'http://localhost/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function get_option( $key, $default = false ) {
	return $default;
}
function delete_option( $key ) {}
function delete_transient( $key ) {}
function get_transient( $key ) {
	return false;
}
function set_transient( $key, $value, $ttl = 0 ) {}
function wp_cache_delete( ...$args ) {}
function delete_site_transient( $key ) {}
function is_admin() {
	return false;
}
function wp_next_scheduled( $hook ) {
	return false;
}
function wp_schedule_event( ...$args ) {}
function wp_clear_scheduled_hook( $hook ) {}
function current_user_can( $cap ) {
	return true;
}

$plugin_main = dirname( __DIR__ ) . '/paxdesign-toolbar/paxdesign-toolbar.php';
if ( ! is_file( $plugin_main ) ) {
	fwrite( STDERR, "Plugin main file not found: {$plugin_main}\n" );
	exit( 1 );
}

require $plugin_main;

echo "OK: Plugin bootstrap loaded without fatal/parse errors.\n";
exit( 0 );
