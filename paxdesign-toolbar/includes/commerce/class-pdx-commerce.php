<?php
/**
 * PDX_Commerce — PayPal integration, order creation, capture, and webhook handling.
 *
 * Uses PayPal Orders API v2 (REST).
 * Credentials stored in pdx_settings['paypal'].
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Commerce {

	/* PayPal sandbox and live base URLs */
	const PP_SANDBOX = 'https://api-m.sandbox.paypal.com';
	const PP_LIVE    = 'https://api-m.paypal.com';

	private PDX_Settings $settings;

	public function __construct( PDX_Settings $settings ) {
		$this->settings = $settings;
	}

	/* ── Config helpers ─────────────────────────────────── */

	public function is_live(): bool {
		return $this->settings->get( 'paypal.mode', 'sandbox' ) === 'live';
	}

	public function base_url(): string {
		return $this->is_live() ? self::PP_LIVE : self::PP_SANDBOX;
	}

	public function client_id(): string {
		$key = $this->is_live() ? 'paypal.live_client_id' : 'paypal.sandbox_client_id';
		return (string) $this->settings->get( $key, '' );
	}

	public function secret(): string {
		$key = $this->is_live() ? 'paypal.live_secret' : 'paypal.sandbox_secret';
		return (string) $this->settings->get( $key, '' );
	}

	public function is_configured(): bool {
		return $this->client_id() !== '' && $this->secret() !== '';
	}

	/* ── OAuth token ────────────────────────────────────── */

	public function get_access_token(): string|WP_Error {
		$transient = 'pdx_pp_token_' . ( $this->is_live() ? 'live' : 'sandbox' );
		$cached    = get_transient( $transient );
		if ( $cached ) return $cached;

		$resp = wp_remote_post( $this->base_url() . '/v1/oauth2/token', [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $this->client_id() . ':' . $this->secret() ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => 'grant_type=client_credentials',
		] );

		if ( is_wp_error( $resp ) ) return $resp;

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'pp_auth', 'PayPal authentication failed: ' . ( $body['error_description'] ?? 'unknown' ) );
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 );
		set_transient( $transient, $body['access_token'], $ttl );
		return $body['access_token'];
	}

	/* ── Create order ───────────────────────────────────── */

	/**
	 * @param string $module_id   e.g. 'osint'
	 * @param float  $amount      e.g. 9.99
	 * @param string $currency    e.g. 'USD'
	 * @param string $description Human-readable line item
	 * @param string $return_url  After PayPal approval
	 * @param string $cancel_url  If user cancels
	 */
	public function create_order(
		string $module_id,
		float  $amount,
		string $currency,
		string $description,
		string $return_url,
		string $cancel_url
	): array|WP_Error {

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) return $token;

		$payload = [
			'intent'         => 'CAPTURE',
			'purchase_units' => [ [
				'reference_id' => $module_id . '_' . time(),
				'description'  => $description,
				'amount'       => [
					'currency_code' => strtoupper( $currency ),
					'value'         => number_format( $amount, 2, '.', '' ),
				],
				'custom_id'    => $module_id,
			] ],
			'application_context' => [
				'return_url'          => $return_url,
				'cancel_url'          => $cancel_url,
				'brand_name'          => get_bloginfo( 'name' ),
				'user_action'         => 'PAY_NOW',
				'shipping_preference' => 'NO_SHIPPING',
			],
		];

		$resp = wp_remote_post( $this->base_url() . '/v2/checkout/orders', [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $resp ) ) return $resp;

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['id'] ) ) {
			return new WP_Error( 'pp_order', 'PayPal order creation failed', $body );
		}

		return $body;
	}

	/* ── Capture order ──────────────────────────────────── */

	public function capture_order( string $order_id ): array|WP_Error {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) return $token;

		$resp = wp_remote_post(
			$this->base_url() . '/v2/checkout/orders/' . $order_id . '/capture',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body' => '{}',
			]
		);

		if ( is_wp_error( $resp ) ) return $resp;

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return $body;
	}

	/* ── Get order details ──────────────────────────────── */

	public function get_order( string $order_id ): array|WP_Error {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) return $token;

		$resp = wp_remote_get(
			$this->base_url() . '/v2/checkout/orders/' . $order_id,
			[
				'timeout' => 10,
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			]
		);

		if ( is_wp_error( $resp ) ) return $resp;
		return json_decode( wp_remote_retrieve_body( $resp ), true );
	}

	/* ── Currency helper ────────────────────────────────── */

	public static function supported_currencies(): array {
		return [ 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK' ];
	}
}
