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

	public static function get_user_api_key( int $user_id, string $provider ): string {
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

		$billing = [];
		if ( class_exists( 'PDX_Billing', false ) && $user_id ) {
			$billing = [
				'plan'         => PDX_Billing::get_plan_for_user( $user_id ),
				'subscription' => PDX_Billing::get_subscription( $user_id ),
				'credits'      => PDX_Billing::credit_balance( $user_id ),
			];
		}

		return [
			'profile' => [
				'id'           => $user_id,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'verified'     => PDX_Auth::is_email_verified( $user_id ),
			],
			'api_keys'     => self::get_api_keys_masked( $user_id ),
			'integrations' => self::integration_status( $user_id ),
			'license'      => self::license_info( $user_id ),
			'billing'      => $billing,
		];
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
