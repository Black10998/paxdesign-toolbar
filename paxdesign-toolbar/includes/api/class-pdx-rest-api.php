<?php
/**
 * REST API — internal endpoints consumed by the JS dock.
 *
 * Base: /wp-json/pdx/v1/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_REST_API {

	public function __construct( private PDX_Settings $settings ) {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$ns = 'pdx/v1';

		// Trust check proxy
		register_rest_route( $ns, '/trust', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'trust_check' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'domain' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/i', $v ),
				],
			],
		] );

		// Analytics event ingestion
		register_rest_route( $ns, '/event', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'log_event' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'module' => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'action' => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'meta'   => [ 'required' => false, 'default' => [] ],
			],
		] );

		// Settings read (admin only)
		register_rest_route( $ns, '/settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_settings' ],
			'permission_callback' => static fn() => current_user_can( PDX_CAP ),
		] );

		// Settings write (admin only)
		register_rest_route( $ns, '/settings', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'update_settings' ],
			'permission_callback' => static fn() => current_user_can( PDX_CAP ),
		] );
	}

	/** Proxy RDAP + SSL Labs + Google Safe Browsing */
	public function trust_check( WP_REST_Request $req ): WP_REST_Response {
		$domain = strtolower( $req->get_param( 'domain' ) );

		$rdap = $this->fetch_rdap( $domain );
		$ssl  = $this->fetch_ssl( $domain );

		return new WP_REST_Response( [
			'domain' => $domain,
			'rdap'   => $rdap,
			'ssl'    => $ssl,
		], 200 );
	}

	private function fetch_rdap( string $domain ): ?array {
		$resp = wp_remote_get(
			'https://rdap.org/domain/' . rawurlencode( $domain ),
			[ 'timeout' => 8, 'sslverify' => true ]
		);
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : null;
	}

	private function fetch_ssl( string $domain ): ?array {
		$url  = 'https://api.ssllabs.com/api/v3/analyze?host=' . rawurlencode( $domain ) . '&fromCache=on&maxAge=24&all=done';
		$resp = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => true ] );
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : null;
	}

	/** Log interaction event if analytics enabled */
	public function log_event( WP_REST_Request $req ): WP_REST_Response {
		if ( ! $this->settings->get( 'analytics_enabled' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'analytics_disabled' ], 200 );
		}

		if ( ! $this->settings->get( 'log_interactions' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'logging_disabled' ], 200 );
		}

		$log = get_option( 'pdx_event_log', [] );
		$log[] = [
			'ts'     => time(),
			'module' => $req->get_param( 'module' ),
			'action' => $req->get_param( 'action' ),
			'meta'   => (array) $req->get_param( 'meta' ),
			'ip'     => $this->settings->get( 'gdpr_mode' ) ? null : sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
		];

		// Enforce retention
		$days  = (int) $this->settings->get( 'data_retention_days', 30 );
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$log   = array_filter( $log, static fn( $e ) => $e['ts'] >= $cutoff );

		update_option( 'pdx_event_log', array_values( $log ) );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->all(), 200 );
	}

	public function update_settings( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}
		$this->settings->save( $body );
		return new WP_REST_Response( [ 'ok' => true, 'settings' => $this->settings->all() ], 200 );
	}
}
