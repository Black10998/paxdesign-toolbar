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
}
