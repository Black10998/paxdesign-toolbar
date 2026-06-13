<?php
/**
 * PDX_Account — user profile, per-user API keys, and license/subscription scaffolding.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Account {

	const META_API_KEYS    = 'pdx_user_api_keys';
	const META_LICENSE     = 'pdx_license';
	const META_SUBSCRIPTION = 'pdx_subscription';

	public static function allowed_providers(): array {
		return [ 'openai', 'virustotal', 'shodan', 'hunter', 'nvd', 'abuseipdb', 'abusech', 'ipapi' ];
	}

	/** Site administrators only — API keys, integrations, license admin tools. */
	public static function can_manage_technical( ?int $user_id = null ): bool {
		return PDX_Auth::is_site_admin( $user_id );
	}

	public static function get_user_api_key( int $user_id, string $provider ): string {
		if ( ! self::can_manage_technical( $user_id ) ) {
			return '';
		}
		if ( ! $user_id || ! in_array( $provider, self::allowed_providers(), true ) ) {
			return '';
		}
		$keys = get_user_meta( $user_id, self::META_API_KEYS, true );
		if ( ! is_array( $keys ) || empty( $keys[ $provider ] ) ) {
			return '';
		}
		return (string) $keys[ $provider ];
	}

	public static function get_api_keys_masked( int $user_id ): array {
		if ( ! self::can_manage_technical( $user_id ) ) {
			return [];
		}
		$keys    = get_user_meta( $user_id, self::META_API_KEYS, true );
		$keys    = is_array( $keys ) ? $keys : [];
		$result  = [];
		$labels  = self::provider_labels();

		foreach ( self::allowed_providers() as $provider ) {
			$value = (string) ( $keys[ $provider ] ?? '' );
			$result[] = [
				'provider'  => $provider,
				'label'     => $labels[ $provider ] ?? ucfirst( $provider ),
				'has_key'   => '' !== $value,
				'masked'    => $value ? self::mask_key( $value ) : '',
				'status'    => $value ? 'active' : 'disconnected',
			];
		}
		return $result;
	}

	public static function update_api_key( int $user_id, string $provider, string $value ): array {
		if ( ! self::can_manage_technical( $user_id ) ) {
			return [ 'success' => false, 'message' => 'Access denied.' ];
		}
		if ( ! in_array( $provider, self::allowed_providers(), true ) ) {
			return [ 'success' => false, 'message' => 'Unknown provider.' ];
		}
		$keys = get_user_meta( $user_id, self::META_API_KEYS, true );
		$keys = is_array( $keys ) ? $keys : [];
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			unset( $keys[ $provider ] );
		} else {
			$keys[ $provider ] = $value;
		}
		update_user_meta( $user_id, self::META_API_KEYS, $keys );
		PDX_Audit::log( 'account', 'api_key_updated', [ 'user_id' => $user_id, 'provider' => $provider ] );
		return [ 'success' => true, 'message' => 'API key saved.', 'status' => '' !== $value ? 'active' : 'disconnected' ];
	}

	public static function validate_api_key( int $user_id, string $provider ): array {
		if ( ! self::can_manage_technical( $user_id ) ) {
			return [ 'success' => false, 'status' => 'forbidden', 'message' => 'Access denied.' ];
		}
		$key = self::get_user_api_key( $user_id, $provider );
		if ( '' === $key ) {
			return [ 'success' => false, 'status' => 'disconnected', 'message' => 'No key configured.' ];
		}

		$ok = self::test_provider( $provider, $key );
		return [
			'success' => $ok['valid'],
			'status'  => $ok['valid'] ? 'connected' : 'error',
			'message' => $ok['message'],
		];
	}

	public static function dashboard( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}

		$is_admin = self::can_manage_technical( $user_id );

		$payload = [
			'is_admin' => $is_admin,
			'profile'  => [
				'id'           => $user_id,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'verified'     => PDX_Auth::is_email_verified( $user_id ),
			],
		];

		if ( $is_admin ) {
			$billing = [];
			if ( class_exists( 'PDX_Billing', false ) ) {
				$billing = [
					'plan'         => PDX_Billing::get_plan_for_user( $user_id ),
					'subscription' => PDX_Billing::get_subscription( $user_id ),
					'credits'      => PDX_Billing::credit_balance( $user_id ),
				];
			}

			return array_merge( $payload, [
				'api_keys'     => self::get_api_keys_masked( $user_id ),
				'integrations' => self::integration_status( $user_id ),
				'license'      => self::license_info( $user_id ),
				'billing'      => $billing,
			] );
		}

		return array_merge( $payload, [
			'purchases'    => self::customer_purchases( $user_id ),
			'orders'       => self::customer_orders( $user_id ),
			'subscription' => self::customer_subscription( $user_id ),
		] );
	}

	/** @return list<array<string,mixed>> */
	public static function customer_purchases( int $user_id ): array {
		$rows    = PDX_Access::get_user_orders( $user_id );
		$items   = [];
		$seen    = [];

		foreach ( $rows as $row ) {
			if ( 'active' !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			$module_id = (string) ( $row['module_id'] ?? '' );
			if ( isset( $seen[ $module_id ] ) ) {
				continue;
			}
			$seen[ $module_id ] = true;
			$items[]            = [
				'module_id'   => $module_id,
				'label'       => self::module_label( $module_id ),
				'status'      => 'active',
				'purchased_at'=> $row['created_at'] ?? '',
				'expires_at'  => $row['expires_at'] ?? null,
				'amount'      => (float) ( $row['amount'] ?? 0 ),
				'currency'    => (string) ( $row['currency'] ?? 'USD' ),
			];
		}

		return $items;
	}

	/** @return list<array<string,mixed>> */
	public static function customer_orders( int $user_id ): array {
		$rows   = PDX_Access::get_user_orders( $user_id );
		$orders = [];

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? 'pending' );
			$orders[] = [
				'id'               => (int) ( $row['id'] ?? 0 ),
				'order_id'         => (string) ( $row['paypal_order'] ?: 'PDX-' . ( $row['id'] ?? 0 ) ),
				'transaction_id'   => (string) ( $row['paypal_order'] ?? '' ),
				'paid_at'          => (string) ( $row['created_at'] ?? '' ),
				'payment_status'   => self::payment_status_label( $status ),
				'amount'           => (float) ( $row['amount'] ?? 0 ),
				'currency'         => (string) ( $row['currency'] ?? 'USD' ),
				'product'          => self::module_label( (string) ( $row['module_id'] ?? '' ) ),
				'module_id'        => (string) ( $row['module_id'] ?? '' ),
				'access_status'    => $status,
				'expires_at'       => $row['expires_at'] ?? null,
				'invoice_available'=> 'active' === $status && ! empty( $row['paypal_order'] ),
			];
		}

		return $orders;
	}

	/** @return array<string,mixed> */
	public static function customer_subscription( int $user_id ): array {
		$plan   = 'free';
		$status = 'inactive';
		$renew  = null;

		if ( class_exists( 'PDX_Billing', false ) ) {
			$sub    = PDX_Billing::get_subscription( $user_id );
			$plan   = (string) ( $sub['plan_id'] ?? 'free' );
			$status = (string) ( $sub['status'] ?? 'inactive' );
			$renew  = $sub['current_period_end'] ?? null;
		}

		$active_modules = array_values( array_filter(
			PDX_Access::get_user_access( $user_id ),
			static fn( $row ) => 'active' === ( $row['status'] ?? '' )
		) );

		return [
			'plan'            => $plan,
			'status'          => $status,
			'renewal_at'      => $renew,
			'active_modules'  => array_map(
				static fn( $row ) => [
					'module_id' => (string) ( $row['module_id'] ?? '' ),
					'label'     => self::module_label( (string) ( $row['module_id'] ?? '' ) ),
					'expires_at'=> $row['expires_at'] ?? null,
				],
				$active_modules
			),
		];
	}

	/**
	 * @return array{success:bool,html?:string,filename?:string,message?:string}
	 */
	public static function invoice_document( int $user_id, string $order_ref ): array {
		$record = PDX_Access::get_order_for_user( $user_id, $order_ref );
		if ( ! $record ) {
			return [ 'success' => false, 'message' => 'Invoice not found.' ];
		}
		if ( 'active' !== ( $record['status'] ?? '' ) ) {
			return [ 'success' => false, 'message' => 'Invoice is only available for completed payments.' ];
		}

		$user  = get_userdata( $user_id );
		$site  = get_bloginfo( 'name' );
		$order = (string) ( $record['paypal_order'] ?: 'PDX-' . ( $record['id'] ?? 0 ) );

		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice ' . esc_html( $order ) . '</title>' .
			'<style>body{font-family:sans-serif;padding:32px;color:#111}h1{font-size:20px}table{width:100%;border-collapse:collapse;margin-top:20px}td,th{padding:8px;border-bottom:1px solid #ddd;text-align:left}th{width:180px;color:#555}</style></head><body>' .
			'<h1>' . esc_html( $site ) . ' — Invoice</h1>' .
			'<table>' .
			'<tr><th>Order ID</th><td>' . esc_html( $order ) . '</td></tr>' .
			'<tr><th>Transaction ID</th><td>' . esc_html( (string) ( $record['paypal_order'] ?? '' ) ) . '</td></tr>' .
			'<tr><th>Customer</th><td>' . esc_html( $user ? $user->user_email : '' ) . '</td></tr>' .
			'<tr><th>Product</th><td>' . esc_html( self::module_label( (string) ( $record['module_id'] ?? '' ) ) ) . '</td></tr>' .
			'<tr><th>Amount</th><td>' . esc_html( (string) ( $record['currency'] ?? 'USD' ) . ' ' . number_format( (float) ( $record['amount'] ?? 0 ), 2 ) ) . '</td></tr>' .
			'<tr><th>Payment status</th><td>Paid</td></tr>' .
			'<tr><th>Date</th><td>' . esc_html( (string) ( $record['created_at'] ?? '' ) ) . '</td></tr>' .
			'</table></body></html>';

		return [
			'success'  => true,
			'html'     => $html,
			'filename' => 'invoice-' . sanitize_file_name( $order ) . '.html',
		];
	}

	private static function payment_status_label( string $status ): string {
		return match ( $status ) {
			'active'   => 'Paid',
			'pending'  => 'Pending',
			'refunded' => 'Refunded',
			'expired'  => 'Failed',
			default    => ucfirst( $status ),
		};
	}

	private static function module_label( string $module_id ): string {
		if ( '' === $module_id ) {
			return 'Unknown';
		}
		if ( function_exists( 'pdx' ) ) {
			$registry = pdx( 'modules' );
			if ( $registry instanceof PDX_Module_Registry ) {
				$mod = $registry->get( $module_id );
				if ( is_array( $mod ) && ! empty( $mod['label'] ) ) {
					return (string) $mod['label'];
				}
			}
		}
		return ucwords( str_replace( [ '-', '_' ], ' ', $module_id ) );
	}

	/**
	 * @return array{success:bool,message?:string}
	 */
	public static function update_profile( int $user_id, array $data ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [ 'success' => false, 'message' => 'User not found.' ];
		}

		$updates = [ 'ID' => $user_id ];

		if ( isset( $data['display_name'] ) ) {
			$name = sanitize_text_field( (string) $data['display_name'] );
			if ( '' !== $name ) {
				$updates['display_name'] = $name;
				$updates['first_name']   = $name;
			}
		}

		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( (string) $data['email'] );
			if ( ! is_email( $email ) ) {
				return [ 'success' => false, 'message' => 'Invalid email address.' ];
			}
			if ( $email !== $user->user_email && email_exists( $email ) ) {
				return [ 'success' => false, 'message' => 'Email already in use.' ];
			}
			if ( $email !== $user->user_email ) {
				$updates['user_email'] = $email;
				update_user_meta( $user_id, PDX_Auth::META_VERIFIED, 0 );
				PDX_Auth::resend_verification( $user_id );
			}
		}

		if ( isset( $data['current_password'], $data['new_password'] ) && '' !== (string) $data['new_password'] ) {
			$current = (string) $data['current_password'];
			$new     = (string) $data['new_password'];
			if ( strlen( $new ) < 8 ) {
				return [ 'success' => false, 'message' => 'New password must be at least 8 characters.' ];
			}
			if ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
				return [ 'success' => false, 'message' => 'Current password is incorrect.' ];
			}
			wp_set_password( $new, $user_id );
			wp_set_auth_cookie( $user_id, true, is_ssl() );
		}

		$result = wp_update_user( $updates );
		if ( is_wp_error( $result ) ) {
			return [ 'success' => false, 'message' => $result->get_error_message() ];
		}

		PDX_Audit::log( 'account', 'profile_updated', [ 'user_id' => $user_id ] );
		return [ 'success' => true, 'message' => 'Profile updated.', 'user' => PDX_Auth::user_payload( $user_id ) ];
	}

	private static function mask_key( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', min( 12, $len - 8 ) ) . substr( $key, -4 );
	}

	private static function provider_labels(): array {
		return [
			'openai'     => 'OpenAI',
			'virustotal' => 'VirusTotal',
			'shodan'     => 'Shodan',
			'hunter'     => 'Hunter.io',
			'nvd'        => 'NVD',
			'abuseipdb'  => 'AbuseIPDB',
			'abusech'    => 'abuse.ch',
			'ipapi'      => 'IP-API',
		];
	}

	private static function integration_status( int $user_id ): array {
		$keys = get_user_meta( $user_id, self::META_API_KEYS, true );
		$keys = is_array( $keys ) ? $keys : [];
		$site = pdx_settings()->get( 'api_keys' );
		$site = is_array( $site ) ? $site : [];

		$integrations = [];
		foreach ( self::allowed_providers() as $provider ) {
			$user_key = (string) ( $keys[ $provider ] ?? '' );
			$site_key = (string) ( $site[ $provider ] ?? '' );
			$active   = '' !== $user_key || '' !== $site_key;
			$integrations[] = [
				'provider' => $provider,
				'label'    => self::provider_labels()[ $provider ] ?? ucfirst( $provider ),
				'status'   => $active ? ( $user_key ? 'connected' : 'site_default' ) : 'disconnected',
				'source'   => $user_key ? 'user' : ( $site_key ? 'site' : 'none' ),
			];
		}
		return $integrations;
	}

	private static function license_info( int $user_id ): array {
		$license = get_user_meta( $user_id, self::META_LICENSE, true );
		$sub     = get_user_meta( $user_id, self::META_SUBSCRIPTION, true );

		return [
			'plan'       => is_array( $sub ) ? ( $sub['plan'] ?? 'free' ) : 'free',
			'status'     => is_array( $sub ) ? ( $sub['status'] ?? 'inactive' ) : 'inactive',
			'license_key'=> is_array( $license ) ? ( $license['key'] ?? '' ) : '',
			'expires_at' => is_array( $license ) ? ( $license['expires_at'] ?? null ) : null,
			'modules'    => is_array( $license ) ? ( $license['modules'] ?? [] ) : [],
		];
	}

	/** @return array{valid:bool,message:string} */
	private static function test_provider( string $provider, string $key ): array {
		switch ( $provider ) {
			case 'openai':
				$resp = wp_remote_get( 'https://api.openai.com/v1/models', [
					'headers' => [ 'Authorization' => 'Bearer ' . $key ],
					'timeout' => 10,
				] );
				$code = wp_remote_retrieve_response_code( $resp );
				return [
					'valid'   => $code >= 200 && $code < 300,
					'message' => $code >= 200 && $code < 300 ? 'Connected' : 'Authentication failed (HTTP ' . $code . ')',
				];
			case 'virustotal':
				$resp = wp_remote_get( 'https://www.virustotal.com/api/v3/domains/google.com', [
					'headers' => [ 'x-apikey' => $key ],
					'timeout' => 10,
				] );
				$code = wp_remote_retrieve_response_code( $resp );
				return [
					'valid'   => $code >= 200 && $code < 300,
					'message' => $code >= 200 && $code < 300 ? 'Connected' : 'Authentication failed (HTTP ' . $code . ')',
				];
			case 'shodan':
				$resp = wp_remote_get( 'https://api.shodan.io/api-info?key=' . rawurlencode( $key ), [ 'timeout' => 10 ] );
				$code = wp_remote_retrieve_response_code( $resp );
				return [
					'valid'   => $code >= 200 && $code < 300,
					'message' => $code >= 200 && $code < 300 ? 'Connected' : 'Authentication failed (HTTP ' . $code . ')',
				];
			default:
				return [
					'valid'   => strlen( $key ) >= 8,
					'message' => strlen( $key ) >= 8 ? 'Key format valid' : 'Key too short',
				];
		}
	}
}
