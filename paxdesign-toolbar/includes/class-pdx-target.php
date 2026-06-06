<?php
/**
 * Global target normalization for all PaxDesign intelligence modules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Target {

	/**
	 * Normalize and classify any user-supplied indicator.
	 *
	 * @return array{
	 *   raw:string,
	 *   normalized:string,
	 *   host:?string,
	 *   type:string,
	 *   url:?string,
	 *   path:?string,
	 *   query:?string,
	 *   valid:bool
	 * }|WP_Error
	 */
	public static function resolve( string $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'pdx_empty_target', 'Target is required.' );
		}

		// Email — host is the domain part for DNS/RDAP/threat lookups.
		if ( filter_var( $raw, FILTER_VALIDATE_EMAIL ) ) {
			$email  = strtolower( $raw );
			$domain = self::email_domain_from_address( $email );
			if ( '' === $domain || ! self::is_valid_hostname( $domain ) ) {
				return new WP_Error( 'pdx_invalid_email', 'Could not extract a valid domain from the email address.' );
			}
			return self::pack( $raw, $email, $domain, 'email', null, null, null );
		}

		// Hash (md5 / sha1 / sha256)
		if ( preg_match( '/^[a-f0-9]{32}$/i', $raw ) ) {
			return self::pack( $raw, strtolower( $raw ), null, 'hash', null, null, null );
		}
		if ( preg_match( '/^[a-f0-9]{40}$/i', $raw ) ) {
			return self::pack( $raw, strtolower( $raw ), null, 'hash', null, null, null );
		}
		if ( preg_match( '/^[a-f0-9]{64}$/i', $raw ) ) {
			return self::pack( $raw, strtolower( $raw ), null, 'hash', null, null, null );
		}

		// IP (IPv4 and IPv6)
		if ( filter_var( $raw, FILTER_VALIDATE_IP ) ) {
			return self::pack( $raw, $raw, $raw, 'ip', null, null, null );
		}

		$parse_input = $raw;
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $parse_input ) ) {
			// Hostname with query/path but no scheme — parse_url needs a scheme.
			if ( preg_match( '~[/?#]~', $parse_input ) ) {
				$parse_input = 'http://' . $parse_input;
			}
		}

		$parts = wp_parse_url( $parse_input );
		$host  = null;
		$path  = null;
		$query = null;
		$url   = null;

		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$host = self::clean_hostname( (string) $parts['host'] );
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : null;
			$query = isset( $parts['query'] ) ? (string) $parts['query'] : null;
			$scheme = $parts['scheme'] ?? 'https';
			$url    = $scheme . '://' . $host . ( $path ?? '' ) . ( $query ? '?' . $query : '' );
		} else {
			$host = self::clean_hostname( self::strip_to_hostname( $raw ) );
		}

		if ( ! $host ) {
			return new WP_Error( 'pdx_invalid_target', 'Could not extract a valid hostname, IP, or email from the input.' );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::pack( $raw, $host, $host, 'ip', $url, $path, $query );
		}

		if ( ! self::is_valid_hostname( $host ) ) {
			return new WP_Error(
				'pdx_invalid_hostname',
				sprintf( 'Invalid hostname after normalization: "%s".', $host )
			);
		}

		$type = 'domain';
		if ( $url || preg_match( '#[/?]#', $raw ) ) {
			$type = 'url';
		}

		return self::pack( $raw, $host, $host, $type, $url, $path, $query );
	}

	/**
	 * Hostname used for DNS/RDAP/SSL/threat API calls.
	 */
	public static function api_host( array $resolved ): string {
		return (string) ( $resolved['host'] ?? $resolved['normalized'] ?? '' );
	}

	/**
	 * Primary lookup key for intelligence routing (domain for email, hash for hash, etc.).
	 */
	public static function scan_host( array $resolved ): string {
		$type = (string) ( $resolved['type'] ?? 'domain' );

		if ( 'hash' === $type ) {
			return (string) ( $resolved['normalized'] ?? '' );
		}

		if ( 'email' === $type ) {
			return self::email_domain_from_address( (string) ( $resolved['normalized'] ?? '' ) )
				?: self::api_host( $resolved );
		}

		return self::api_host( $resolved );
	}

	/**
	 * @return string Domain part of an email address.
	 */
	public static function email_domain_from_address( string $email ): string {
		$parts = explode( '@', strtolower( trim( $email ) ), 2 );
		return $parts[1] ?? '';
	}

	public static function is_ip_type( string $type ): bool {
		return 'ip' === $type;
	}

	public static function is_domain_like( string $type ): bool {
		return in_array( $type, [ 'domain', 'url', 'email' ], true );
	}

	/**
	 * REST validate_callback — accepts domains, URLs, IPs, emails, hashes (after normalization).
	 */
	public static function rest_validate_indicator( $value ): bool {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return false;
		}
		$resolved = self::resolve( $value );
		return ! is_wp_error( $resolved ) && ! empty( $resolved['valid'] );
	}

	/**
	 * @return array{raw:string,normalized:string,host:?string,type:string,url:?string,path:?string,query:?string,valid:bool}
	 */
	private static function pack( string $raw, string $normalized, ?string $host, string $type, ?string $url, ?string $path, ?string $query ): array {
		return [
			'raw'         => $raw,
			'normalized'  => $normalized,
			'host'        => $host,
			'type'        => $type,
			'url'         => $url,
			'path'        => $path,
			'query'       => $query,
			'valid'       => true,
		];
	}

	private static function strip_to_hostname( string $input ): string {
		$s = trim( $input );

		// Bracketed IPv6 literal from URLs.
		if ( preg_match( '/^\[([^\]]+)\](?::\d+)?(?:\/|$)/', $s, $m ) ) {
			return $m[1];
		}

		if ( filter_var( $s, FILTER_VALIDATE_IP ) ) {
			return $s;
		}

		$s = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $s ) ?? $s;
		$s = preg_replace( '/[#?].*$/s', '', $s ) ?? $s;
		$s = explode( '/', $s )[0] ?? $s;

		// Strip port only for non-IPv6 host:port (single colon).
		if ( ! str_contains( $s, '::' ) && substr_count( $s, ':' ) === 1 ) {
			$s = explode( ':', $s )[0] ?? $s;
		}

		return self::clean_hostname( $s );
	}

	private static function clean_hostname( string $host ): string {
		$host = strtolower( trim( $host, ". \t\n\r\0\x0B" ) );
		return rtrim( $host, '.' );
	}

	private static function is_valid_hostname( string $host ): bool {
		if ( strlen( $host ) > 253 || '' === $host ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		// Allow punycode / standard FQDNs.
		return (bool) preg_match(
			'/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
			$host
		) || (bool) preg_match( '/^[a-z0-9-]+\.[a-z]{2,}$/i', $host );
	}
}
