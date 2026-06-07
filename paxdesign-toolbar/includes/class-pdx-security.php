<?php
/**
 * PDX_Security — shared actor identity, resource ownership, and outbound URL policy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Security {

	/**
	 * @return array{user_id:int,session_id:string}
	 */
	public static function current_actor(): array {
		return [
			'user_id'    => is_user_logged_in() ? (int) get_current_user_id() : 0,
			'session_id' => sanitize_text_field( $_COOKIE['pdx_guest'] ?? '' ),
		];
	}

	public static function is_platform_admin(): bool {
		return current_user_can( PDX_CAP );
	}

	/**
	 * @param array{user_id?:int|string,session_id?:string|null} $row
	 */
	public static function actor_owns_row( array $row ): bool {
		if ( self::is_platform_admin() ) {
			return true;
		}

		$actor = self::current_actor();

		if ( $actor['user_id'] && (int) ( $row['user_id'] ?? 0 ) === $actor['user_id'] ) {
			return true;
		}

		if ( ! $actor['user_id'] && $actor['session_id'] && ! empty( $row['session_id'] ) ) {
			return hash_equals( (string) $row['session_id'], $actor['session_id'] );
		}

		return false;
	}

	public static function require_actor(): bool {
		$actor = self::current_actor();
		return $actor['user_id'] > 0 || '' !== $actor['session_id'];
	}

	/**
	 * Block SSRF to internal/private networks for user-supplied outbound URLs.
	 */
	public static function validate_outbound_url( string $url ): ?string {
		$url = esc_url_raw( trim( $url ) );
		if ( ! $url ) {
			return 'Invalid URL.';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return 'Only HTTP(S) URLs are allowed.';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return 'URL host is required.';
		}

		$blocked = [ 'localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]' ];
		if ( in_array( $host, $blocked, true ) ) {
			return 'Internal addresses are not allowed.';
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host ) ? null : 'Private or reserved addresses are not allowed.';
		}

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A + DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $rec ) {
					$ip = (string) ( $rec['ip'] ?? $rec['ipv6'] ?? '' );
					if ( $ip && ! self::is_public_ip( $ip ) ) {
						return 'URL resolves to a private or reserved address.';
					}
				}
			}
		}

		return null;
	}

	private static function is_public_ip( string $ip ): bool {
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	public static function register_hooks(): void {
		add_filter( 'determine_current_user', [ self::class, 'authenticate_bearer_dev_token' ], 20 );
		add_filter( 'rest_authentication_errors', [ self::class, 'rest_authentication_errors' ], 20 );
		add_action( 'rest_api_init', [ self::class, 'ensure_guest_session' ], 0 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'ensure_guest_session' ], 0 );
	}

	/**
	 * Issue a stable guest session cookie before workspace/queue/memory operations.
	 */
	public static function ensure_guest_session(): string {
		if ( is_user_logged_in() ) {
			return '';
		}
		$guest = sanitize_text_field( $_COOKIE['pdx_guest'] ?? '' );
		if ( $guest && preg_match( '/^[a-f0-9]{32,64}$/i', $guest ) ) {
			return $guest;
		}
		$guest = wp_generate_password( 32, false );
		if ( ! headers_sent() ) {
			setcookie( 'pdx_guest', $guest, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE['pdx_guest'] = $guest;
		return $guest;
	}

	/**
	 * Authenticate REST requests using Authorization: Bearer pdx_<token>.
	 */
	public static function authenticate_bearer_dev_token( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		$plain = self::parse_bearer_dev_token();
		if ( ! $plain ) {
			return $user_id;
		}
		$match = self::lookup_dev_token_user( $plain );
		return $match ? (int) $match : $user_id;
	}

	/**
	 * Reject malformed bearer tokens that look like dev tokens but do not match.
	 *
	 * @param WP_Error|null|true $result
	 * @return WP_Error|null|true
	 */
	public static function rest_authentication_errors( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}
		$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
		if ( ! preg_match( '/^Bearer\s+(pdx_[a-f0-9]+)$/i', $header, $m ) ) {
			return $result;
		}
		if ( self::lookup_dev_token_user( $m[1] ) ) {
			return $result;
		}
		return new WP_Error( 'pdx_invalid_dev_token', 'Invalid development token.', [ 'status' => 401 ] );
	}

	private static function parse_bearer_dev_token(): string {
		$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
		if ( preg_match( '/^Bearer\s+(pdx_[a-f0-9]+)$/i', $header, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private static function lookup_dev_token_user( string $plain ): int {
		global $wpdb;
		$hash = hash( 'sha256', $plain );
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'pdx_dev_tokens'",
			ARRAY_A
		);
		foreach ( $rows ?: [] as $row ) {
			$tokens = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $tokens ) ) {
				continue;
			}
			foreach ( $tokens as $token_id => $meta ) {
				if ( ! is_array( $meta ) || empty( $meta['hash'] ) ) {
					continue;
				}
				if ( hash_equals( (string) $meta['hash'], $hash ) ) {
					$tokens[ $token_id ]['last_used'] = gmdate( 'c' );
					update_user_meta( (int) $row['user_id'], 'pdx_dev_tokens', $tokens );
					return (int) $row['user_id'];
				}
			}
		}
		return 0;
	}

	/**
	 * Whitelist settings keys allowed via REST update (admin only).
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public static function sanitize_rest_settings( array $body ): array {
		$allowed_roots = [
			'enabled', 'contact_url', 'cta_primary_label', 'cta_secondary_label',
			'modules', 'module_tiers', 'module_prices', 'api_keys', 'paypal', 'stripe',
			'dock_position', 'dock_theme', 'dock_size', 'accent_color', 'custom_css',
			'mobile_enabled', 'mobile_breakpoint', 'analytics_enabled', 'gdpr_mode',
		];
		$clean = [];
		foreach ( $allowed_roots as $key ) {
			if ( ! array_key_exists( $key, $body ) ) {
				continue;
			}
			$value = $body[ $key ];
			if ( 'api_keys' === $key && is_array( $value ) ) {
				$keys = [];
				foreach ( [ 'openai', 'virustotal', 'shodan', 'hunter', 'nvd' ] as $k ) {
					if ( isset( $value[ $k ] ) ) {
						$keys[ $k ] = sanitize_text_field( (string) $value[ $k ] );
					}
				}
				$clean['api_keys'] = $keys;
				continue;
			}
			if ( in_array( $key, [ 'enabled', 'analytics_enabled', 'gdpr_mode', 'mobile_enabled' ], true ) ) {
				$clean[ $key ] = (bool) $value;
				continue;
			}
			if ( in_array( $key, [ 'contact_url' ], true ) ) {
				$clean[ $key ] = esc_url_raw( (string) $value );
				continue;
			}
			if ( in_array( $key, [ 'cta_primary_label', 'cta_secondary_label', 'dock_theme', 'dock_size', 'dock_position', 'accent_color', 'custom_css' ], true ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
				continue;
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}
}
