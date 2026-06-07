<?php
/**
 * Run the PDX live integration audit from CLI.
 *
 * Usage (WordPress install with plugin active):
 *   wp eval-file scripts/run-platform-audit.php
 *
 * Or with explicit wp-load.php:
 *   php scripts/run-platform-audit.php /path/to/wp-load.php
 */

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = $argv[1] ?? getenv( 'WP_LOAD' ) ?: '';
	if ( ! $wp_load ) {
		$candidates = [
			$root . '/../../../wp-load.php',
			$root . '/../../wp-load.php',
			$root . '/../wp-load.php',
		];
		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				$wp_load = $candidate;
				break;
			}
		}
	}
	if ( ! $wp_load || ! is_file( $wp_load ) ) {
		fwrite( STDERR, "WordPress not loaded. Pass wp-load.php path or run via: wp eval-file scripts/run-platform-audit.php\n" );
		exit( 2 );
	}
	require $wp_load;
}

if ( ! class_exists( 'PDX_Integration_Audit', false ) ) {
	fwrite( STDERR, "PDX plugin is not active or integration audit class is missing.\n" );
	exit( 3 );
}

$intel    = pdx_settings() ? new PDX_Intelligence( pdx_settings() ) : null;
$settings = pdx_settings();

if ( ! $intel || ! $settings ) {
	fwrite( STDERR, "PDX bootstrap incomplete.\n" );
	exit( 4 );
}

$audit  = new PDX_Integration_Audit( $intel, $settings );
$result = $audit->run_full();

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

$errors = (int) ( $result['summary']['error'] ?? 0 );
exit( $errors > 0 ? 1 : 0 );
