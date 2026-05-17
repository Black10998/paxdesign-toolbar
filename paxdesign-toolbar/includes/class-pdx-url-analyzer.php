<?php
/**
 * Deep URL / page analysis — redirects, HTML signals, phishing heuristics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Url_Analyzer {

	private const MAX_REDIRECTS = 8;
	private const MAX_BODY_BYTES = 512000;

	/**
	 * @return array<string, mixed>
	 */
	public static function analyze( string $raw_target, array $resolved = [] ): array {
		$url = self::build_fetch_url( $raw_target, $resolved );
		if ( ! $url ) {
			return [
				'label'  => 'URL Forensics',
				'status' => [ 'state' => 'skipped', 'message' => 'No HTTP(S) URL to analyze for this target type.' ],
			];
		}

		$chain  = [];
		$final  = self::follow_redirects( $url, $chain );
		$html   = null;
		$status = [ 'state' => 'error', 'message' => 'Page fetch failed.' ];

		if ( ! is_wp_error( $final ) ) {
			$code = (int) wp_remote_retrieve_response_code( $final );
			$body = wp_remote_retrieve_body( $final );
			if ( $code >= 200 && $code < 400 && is_string( $body ) && strlen( $body ) > 0 ) {
				$html   = substr( $body, 0, self::MAX_BODY_BYTES );
				$status = [ 'state' => 'ok', 'message' => 'Page retrieved for forensic analysis.' ];
			} else {
				$status = [ 'state' => 'partial', 'message' => "Final response HTTP {$code}." ];
			}
		} else {
			$status = [ 'state' => 'error', 'message' => $final->get_error_message() ];
		}

		$signals = $html ? self::parse_html_signals( $html, $url ) : self::empty_signals();
		$phish   = self::score_phishing( $chain, $signals, $resolved );

		return [
			'label'           => 'URL Forensics',
			'entry_url'       => $url,
			'redirect_chain'  => $chain,
			'redirect_count'  => count( $chain ),
			'final_url'       => ! empty( $chain ) ? ( $chain[ count( $chain ) - 1 ]['url'] ?? $url ) : $url,
			'page_signals'    => $signals,
			'phishing'        => $phish,
			'status'          => $status,
		];
	}

	/**
	 * @param array<string, mixed> $resolved
	 */
	private static function build_fetch_url( string $raw, array $resolved ): ?string {
		$raw = trim( $raw );
		if ( preg_match( '#^https?://#i', $raw ) ) {
			return esc_url_raw( preg_replace( '/[?#].*$/', '', $raw ) ) ?: null;
		}
		$type = $resolved['type'] ?? 'domain';
		$host = $resolved['host'] ?? $resolved['normalized'] ?? '';
		if ( in_array( $type, [ 'domain', 'url' ], true ) && $host ) {
			return 'https://' . $host . '/';
		}
		return null;
	}

	/**
	 * @param list<array<string, mixed>> $chain
	 * @return array|WP_Error
	 */
	private static function follow_redirects( string $url, array &$chain ) {
		$current = $url;

		for ( $i = 0; $i < self::MAX_REDIRECTS; $i++ ) {
			$http = PDX_Http::get(
				$current,
				[
					'timeout'     => 12,
					'redirection' => 0,
					'headers'     => [
						'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
					],
				],
				'url_fetch'
			);
			$resp = $http['response'];

			if ( is_wp_error( $resp ) ) {
				return $resp;
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );
			$chain[] = [
				'url'    => $current,
				'code'   => $code,
				'length' => (int) wp_remote_retrieve_header( $resp, 'content-length' ),
			];

			if ( $code >= 300 && $code < 400 ) {
				$location = wp_remote_retrieve_header( $resp, 'location' );
				if ( ! $location ) {
					break;
				}
				$current = self::resolve_relative_url( $current, $location );
				continue;
			}

			return $resp;
		}

		return new WP_Error( 'pdx_redirect_limit', 'Redirect chain exceeded safe limit.' );
	}

	private static function resolve_relative_url( string $base, string $location ): string {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$parts = wp_parse_url( $base );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		if ( str_starts_with( $location, '/' ) ) {
			return $scheme . '://' . $host . $location;
		}
		$path = $parts['path'] ?? '/';
		$dir  = rtrim( dirname( $path ), '/' );
		return $scheme . '://' . $host . $dir . '/' . $location;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function empty_signals(): array {
		return [
			'title'            => null,
			'forms'            => 0,
			'password_fields'  => 0,
			'external_links'   => 0,
			'script_tags'      => 0,
			'iframe_tags'      => 0,
			'meta_refresh'     => false,
			'suspicious_words' => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parse_html_signals( string $html, string $base_url ): array {
		$signals = self::empty_signals();

		if ( preg_match( '/<title[^>]*>([^<]*)<\/title>/i', $html, $m ) ) {
			$signals['title'] = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		$signals['forms']           = preg_match_all( '/<form\b/i', $html ) ?: 0;
		$signals['password_fields'] = preg_match_all( '/type=["\']password["\']/i', $html ) ?: 0;
		$signals['script_tags']     = preg_match_all( '/<script\b/i', $html ) ?: 0;
		$signals['iframe_tags']     = preg_match_all( '/<iframe\b/i', $html ) ?: 0;
		$signals['meta_refresh']    = (bool) preg_match( '/http-equiv=["\']refresh["\']/i', $html );

		$host = wp_parse_url( $base_url, PHP_URL_HOST );
		if ( preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $links ) ) {
			foreach ( $links[1] as $href ) {
				if ( preg_match( '#^https?://#i', $href ) ) {
					$lh = wp_parse_url( $href, PHP_URL_HOST );
					if ( $lh && $host && strtolower( (string) $lh ) !== strtolower( (string) $host ) ) {
						++$signals['external_links'];
					}
				}
			}
		}

		$needles = [ 'verify your account', 'confirm your password', 'wallet connect', 'seed phrase', 'urgent action', 'suspended account', 'click here immediately' ];
		$lower   = strtolower( $html );
		foreach ( $needles as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				$signals['suspicious_words'][] = $needle;
			}
		}

		return $signals;
	}

	/**
	 * @param list<array<string, mixed>> $chain
	 * @param array<string, mixed>      $signals
	 * @param array<string, mixed>      $resolved
	 * @return array<string, mixed>
	 */
	private static function score_phishing( array $chain, array $signals, array $resolved ): array {
		$score   = 0;
		$reasons = [];

		if ( count( $chain ) > 4 ) {
			$score += 15;
			$reasons[] = 'Long redirect chain (' . count( $chain ) . ' hops).';
		}

		if ( ! empty( $signals['password_fields'] ) && (int) $signals['forms'] > 0 ) {
			$score += 20;
			$reasons[] = 'Login or password form detected on landing page.';
		}

		if ( ! empty( $signals['meta_refresh'] ) ) {
			$score += 10;
			$reasons[] = 'Meta refresh redirect present.';
		}

		if ( ! empty( $signals['suspicious_words'] ) ) {
			$score += min( 25, count( $signals['suspicious_words'] ) * 8 );
			$reasons[] = 'Suspicious phrases: ' . implode( ', ', array_slice( $signals['suspicious_words'], 0, 3 ) );
		}

		$entry_host = wp_parse_url( $chain[0]['url'] ?? '', PHP_URL_HOST );
		$final_host = wp_parse_url( $chain[ count( $chain ) - 1 ]['url'] ?? '', PHP_URL_HOST );
		if ( $entry_host && $final_host && strtolower( (string) $entry_host ) !== strtolower( (string) $final_host ) ) {
			$score += 15;
			$reasons[] = "Cross-domain redirect: {$entry_host} → {$final_host}.";
		}

		$verdict = 'low';
		if ( $score >= 45 ) {
			$verdict = 'high';
		} elseif ( $score >= 25 ) {
			$verdict = 'medium';
		}

		return [
			'score'   => min( 100, $score ),
			'verdict' => $verdict,
			'reasons' => $reasons,
		];
	}
}
