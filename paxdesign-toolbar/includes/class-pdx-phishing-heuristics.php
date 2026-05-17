<?php
/**
 * Global phishing / URL / infrastructure heuristics (v8.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PDX_Phishing_Heuristics {

	/** @var list<string> */
	private const SUSPICIOUS_TLDS = [
		'tk', 'ml', 'ga', 'cf', 'gq', 'xyz', 'top', 'cam', 'zip', 'mov', 'rest', 'bond', 'sbs',
	];

	/** @var list<string> */
	private const BAIT_PATH_SEGMENTS = [
		'login', 'signin', 'sign-in', 'verify', 'secure', 'account', 'wallet', 'update', 'confirm',
		'auth', 'oauth', 'sso', 'banking', 'paypal', 'microsoft', 'appleid', 'recover', 'unlock',
	];

	/** @var list<string> */
	private const BRAND_KEYWORDS = [
		'paypal', 'microsoft', 'apple', 'google', 'amazon', 'netflix', 'facebook', 'instagram',
		'coinbase', 'binance', 'metamask', 'office365', 'outlook', 'dhl', 'fedex', 'chase',
	];

	/** @var list<string> */
	private const PHISHING_PHRASES = [
		'verify your account', 'confirm your password', 'wallet connect', 'seed phrase',
		'urgent action', 'suspended account', 'click here immediately', 'unusual activity',
		'confirm your identity', 'update your payment', 'security alert', 'limited time',
		'act now', 'your account will be closed', 'validate your credentials', '2fa required',
		'crypto giveaway', 'airdrop claim', 'restore access', 'billing problem',
	];

	/**
	 * @param array<string, mixed> $resolved PDX_Target::resolve output
	 * @return array{score:int,flags:list<string>}
	 */
	public static function analyze_target( string $raw, array $resolved ): array {
		$score = 0;
		$flags = [];
		$host  = strtolower( (string) ( $resolved['host'] ?? $resolved['normalized'] ?? '' ) );

		if ( $host && str_contains( $host, 'xn--' ) ) {
			$score += 18;
			$flags[] = 'Punycode / IDN homograph domain detected.';
		}

		if ( $host && preg_match( '/\d{1,3}-\d{1,3}-\d{1,3}-\d{1,3}/', $host ) ) {
			$score += 12;
			$flags[] = 'Hostname resembles dotted-decimal IP encoding.';
		}

		$tld = self::extract_tld( $host );
		if ( $tld && in_array( $tld, self::SUSPICIOUS_TLDS, true ) ) {
			$score += 14;
			$flags[] = "High-abuse TLD (.{$tld}).";
		}

		if ( $host && substr_count( $host, '.' ) >= 3 ) {
			$score += 8;
			$flags[] = 'Deep subdomain chain (possible phishing bucket).';
		}

		$path = '';
		if ( preg_match( '#^https?://#i', $raw ) ) {
			$path = (string) ( wp_parse_url( $raw, PHP_URL_PATH ) ?? '' );
		}
		$path_score = self::analyze_path( $path );
		$score     += $path_score['score'];
		$flags      = array_merge( $flags, $path_score['flags'] );

		return [
			'score' => min( 40, $score ),
			'flags' => $flags,
		];
	}

	/**
	 * @return array{score:int,flags:list<string>}
	 */
	public static function analyze_path( string $path ): array {
		$score = 0;
		$flags = [];
		$lower = strtolower( $path );

		if ( '' === $lower || '/' === $lower ) {
			return [ 'score' => 0, 'flags' => [] ];
		}

		foreach ( self::BAIT_PATH_SEGMENTS as $seg ) {
			if ( str_contains( $lower, '/' . $seg ) || str_contains( $lower, $seg . '.php' ) || str_contains( $lower, $seg . '/' ) ) {
				$score += 6;
				$flags[] = "Suspicious path segment: /{$seg}";
				break;
			}
		}

		if ( preg_match( '/@(?!)/', $path ) || str_contains( $lower, '%40' ) ) {
			$score += 10;
			$flags[] = 'URL contains @ credential-bait pattern.';
		}

		if ( preg_match( '/\?[^=]*=(https?%3A|https?:)/i', $path ) || preg_match( '/redirect=|url=|next=|goto=/i', $lower ) ) {
			$score += 8;
			$flags[] = 'Open redirect parameter in URL path/query.';
		}

		if ( preg_match( '/\.(php|asp|aspx|cgi)\?/i', $path ) && str_contains( $lower, 'login' ) ) {
			$score += 5;
			$flags[] = 'Scripted login endpoint in path.';
		}

		return [
			'score' => min( 25, $score ),
			'flags' => array_slice( array_unique( $flags ), 0, 6 ),
		];
	}

	/**
	 * @param list<array<string, mixed>> $chain
	 * @return array{score:int,flags:list<string>,intent:string}
	 */
	public static function analyze_redirect_intent( array $chain ): array {
		$score  = 0;
		$flags  = [];
		$intent = 'direct';

		if ( count( $chain ) < 2 ) {
			return [ 'score' => 0, 'flags' => [], 'intent' => $intent ];
		}

		$hosts = [];
		foreach ( $chain as $hop ) {
			$h = wp_parse_url( (string) ( $hop['url'] ?? '' ), PHP_URL_HOST );
			if ( $h ) {
				$hosts[] = strtolower( $h );
			}
		}

		$unique_hosts = array_unique( $hosts );
		if ( count( $unique_hosts ) >= 3 ) {
			$score   += 12;
			$intent   = 'multi_hop_laundering';
			$flags[] = count( $unique_hosts ) . ' distinct hosts in redirect chain.';
		}

		$entry = $hosts[0] ?? '';
		$final = $hosts[ count( $hosts ) - 1 ] ?? '';
		if ( $entry && $final && $entry !== $final ) {
			$score   += 10;
			$intent   = 'cross_domain_delivery';
			$flags[] = "Redirect intent: {$entry} → {$final}";
		}

		foreach ( $chain as $hop ) {
			$code = (int) ( $hop['code'] ?? 0 );
			if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) && count( $chain ) > 4 ) {
				$score += 5;
				$flags[] = 'Multiple temporary redirects (possible cloaking).';
				break;
			}
		}

		return [
			'score'  => min( 30, $score ),
			'flags'  => array_slice( array_unique( $flags ), 0, 5 ),
			'intent' => $intent,
		];
	}

	/**
	 * @param array<string, mixed> $signals
	 * @return array{score:int,flags:list<string>}
	 */
	public static function analyze_landing_page( array $signals, string $base_url ): array {
		$score = 0;
		$flags = [];
		$title = strtolower( (string) ( $signals['title'] ?? '' ) );
		$host  = strtolower( (string) wp_parse_url( $base_url, PHP_URL_HOST ) );

		foreach ( self::BRAND_KEYWORDS as $brand ) {
			if ( $title && str_contains( $title, $brand ) && $host && ! str_contains( $host, $brand ) ) {
				$score += 15;
				$flags[] = "Possible brand impersonation in title ({$brand}).";
				break;
			}
		}

		if ( ! empty( $signals['hidden_fields'] ) ) {
			$score += 8;
			$flags[] = 'Hidden form fields detected (credential harvesting risk).';
		}

		if ( ! empty( $signals['external_form_action'] ) ) {
			$score += 12;
			$flags[] = 'Form submits credentials to external host.';
		}

		if ( ! empty( $signals['data_uri_links'] ) ) {
			$score += 6;
			$flags[] = 'Data-URI links present (obfuscation).';
		}

		if ( (int) ( $signals['iframe_tags'] ?? 0 ) > 2 ) {
			$score += 5;
			$flags[] = 'Multiple embedded iframes.';
		}

		if ( (int) ( $signals['script_tags'] ?? 0 ) > 25 ) {
			$score += 4;
			$flags[] = 'Heavy script surface on landing page.';
		}

		return [
			'score' => min( 35, $score ),
			'flags' => array_slice( array_unique( $flags ), 0, 6 ),
		];
	}

	/**
	 * @return list<string>
	 */
	public static function scan_phrases( string $html ): array {
		$found = [];
		$lower = strtolower( $html );
		foreach ( self::PHISHING_PHRASES as $phrase ) {
			if ( str_contains( $lower, $phrase ) ) {
				$found[] = $phrase;
			}
		}
		return $found;
	}

	/**
	 * @param array<string, mixed> $report Partial scan report
	 * @return array{fingerprint:string,score:int,relationships:list<array<string,string>>}
	 */
	public static function infrastructure_fingerprint( array $report ): array {
		$sources = $report['sources'] ?? [];
		$dns     = $sources['dns'] ?? [];
		$geo     = $sources['geo'] ?? $sources['geolocation'] ?? [];
		$rdap    = $sources['rdap'] ?? [];
		$parts   = [];
		$rels    = [];
		$score   = 0;

		if ( ! empty( $geo['org'] ) ) {
			$parts[] = 'org:' . sanitize_title( (string) $geo['org'] );
			$rels[]  = [ 'type' => 'hosts_on', 'label' => (string) $geo['org'] ];
		}
		if ( ! empty( $geo['asn'] ) ) {
			$parts[] = 'asn:' . (string) $geo['asn'];
		}
		if ( ! empty( $rdap['registrar'] ) ) {
			$parts[] = 'reg:' . sanitize_title( (string) $rdap['registrar'] );
			$rels[]  = [ 'type' => 'registered_via', 'label' => (string) $rdap['registrar'] ];
		}
		if ( ! empty( $dns['mx'] ) ) {
			$parts[] = 'mx:' . count( (array) $dns['mx'] );
		}
		if ( ! empty( $sources['shodan']['ports'] ) ) {
			$port_count = count( (array) $sources['shodan']['ports'] );
			$parts[]    = 'ports:' . $port_count;
			if ( $port_count > 15 ) {
				$score += 8;
			}
		}
		if ( ! empty( $report['forensics']['redirect_hops'] ) && (int) $report['forensics']['redirect_hops'] > 2 ) {
			$parts[] = 'redirects:' . (int) $report['forensics']['redirect_hops'];
			$score  += 5;
		}

		$fingerprint = $parts ? substr( hash( 'sha256', implode( '|', $parts ) ), 0, 16 ) : 'unknown';

		return [
			'fingerprint'   => $fingerprint,
			'score'         => min( 20, $score ),
			'relationships' => $rels,
		];
	}

	private static function extract_tld( string $host ): string {
		if ( ! $host || ! str_contains( $host, '.' ) ) {
			return '';
		}
		$parts = explode( '.', $host );
		return strtolower( (string) end( $parts ) );
	}
}
