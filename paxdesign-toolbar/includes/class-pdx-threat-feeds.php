<?php
/**
 * Threat feed aggregation for Threat Intel module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Threat_Feeds {

	/**
	 * @return array{feeds:list<array<string,mixed>>,synced_at:string,status:array<string,mixed>}
	 */
	public static function aggregate( string $target = '' ): array {
		$target = trim( $target );
		$feeds  = [
			self::feed_row( 'AlienVault OTX', 'otx', 'https://otx.alienvault.com', self::probe_otx( $target ) ),
			self::feed_row( 'URLhaus (Abuse.ch)', 'urlhaus', 'https://urlhaus.abuse.ch', self::probe_urlhaus( $target ) ),
			self::feed_row( 'NVD / CIRCL CVE', 'cve', 'https://nvd.nist.gov', self::probe_cve() ),
			self::feed_row( 'Google DNS (DoH)', 'dns', 'https://dns.google', self::probe_dns() ),
			self::feed_row( 'RDAP / WHOIS', 'rdap', 'https://rdap.org', self::probe_rdap( $target ) ),
		];

		$ok = 0;
		foreach ( $feeds as $f ) {
			if ( ( $f['status'] ?? '' ) === 'online' ) {
				++$ok;
			}
		}

		return [
			'target'    => $target,
			'feeds'     => $feeds,
			'synced_at' => gmdate( 'c' ),
			'summary'   => [
				'total'  => count( $feeds ),
				'online' => $ok,
			],
			'status'    => [
				'state'   => $ok > 0 ? 'ok' : 'error',
				'message' => "{$ok}/" . count( $feeds ) . ' feeds reachable from server.',
			],
		];
	}

	/**
	 * @param array{state:string,message?:string} $probe
	 * @return array<string, mixed>
	 */
	private static function feed_row( string $name, string $id, string $url, array $probe ): array {
		$state = $probe['state'] ?? 'error';
		return [
			'id'          => $id,
			'name'        => $name,
			'url'         => $url,
			'status'      => 'ok' === $state ? 'online' : ( 'partial' === $state ? 'degraded' : 'offline' ),
			'message'     => $probe['message'] ?? '',
			'last_sync'   => gmdate( 'c' ),
			'indicators'  => (int) ( $probe['indicators'] ?? 0 ),
		];
	}

	/**
	 * @return array{state:string,message?:string,indicators?:int}
	 */
	private static function probe_otx( string $target ): array {
		if ( '' === $target ) {
			return [ 'state' => 'ok', 'message' => 'Ready — enter a target to query pulses.' ];
		}

		$resolved = PDX_Target::resolve( $target );
		if ( is_wp_error( $resolved ) ) {
			return [ 'state' => 'error', 'message' => 'Invalid target for OTX lookup.' ];
		}

		$lookup = PDX_Target::scan_host( $resolved );
		$type   = (string) ( $resolved['type'] ?? 'domain' );
		$path   = 'domain';
		if ( 'ip' === $type ) {
			$path = filter_var( $lookup, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 'IPv6' : 'IPv4';
		} elseif ( 'hash' === $type ) {
			$path = 'file';
		}

		return PDX_Cache::remember(
			'feed_probe_otx_' . md5( $type . '|' . $lookup ),
			300,
			static function () use ( $lookup, $path, $type ) {
				$http = PDX_Http::get(
					'https://otx.alienvault.com/api/v1/indicators/' . $path . '/' . rawurlencode( $lookup ) . '/general',
					[ 'timeout' => 10 ],
					'otx_probe'
				);
				if ( is_wp_error( $http['response'] ) ) {
					return [ 'state' => 'error', 'message' => 'OTX temporarily unavailable.' ];
				}
				$code = (int) wp_remote_retrieve_response_code( $http['response'] );
				if ( 200 !== $code ) {
					return [
						'state'   => in_array( $code, [ 401, 403, 404, 429 ], true ) ? 'partial' : 'error',
						'message' => 'OTX ' . PDX_Http::http_error_message( $code ),
					];
				}
				$data  = json_decode( wp_remote_retrieve_body( $http['response'] ), true );
				$pulse = (int) ( $data['pulse_info']['count'] ?? 0 );
				return [
					'state'      => 'ok',
					'message'    => $pulse > 0 ? "{$pulse} pulse(s) for {$lookup}" : "No pulses for {$lookup}",
					'indicators' => $pulse,
				];
			}
		);
	}

	/**
	 * @return array{state:string,message?:string,indicators?:int}
	 */
	private static function probe_urlhaus( string $target ): array {
		if ( '' === $target ) {
			return [ 'state' => 'ok', 'message' => 'Ready — enter a domain to query URLhaus.' ];
		}
		$host = self::normalize_host( $target );
		if ( ! $host ) {
			return [ 'state' => 'error', 'message' => 'Invalid host for URLhaus.' ];
		}

		return PDX_Cache::remember(
			'feed_probe_urlhaus_' . md5( $host ),
			300,
			static function () use ( $host ) {
				$headers = function_exists( 'pdx_settings' ) ? pdx_settings()->abusech_auth_headers() : [];
				if ( empty( $headers ) ) {
					return [
						'state'   => 'partial',
						'message' => 'abuse.ch Auth-Key required since June 2025 — configure in Admin → API Keys.',
					];
				}
				$http = PDX_Http::post(
					'https://urlhaus-api.abuse.ch/v1/host/',
					[ 'timeout' => 10, 'headers' => $headers, 'body' => [ 'host' => $host ] ],
					'urlhaus_probe'
				);
				if ( is_wp_error( $http['response'] ) ) {
					return [ 'state' => 'error', 'message' => 'URLhaus temporarily unavailable.' ];
				}
				$code = (int) wp_remote_retrieve_response_code( $http['response'] );
				if ( 200 !== $code ) {
					return [
						'state'   => in_array( $code, [ 401, 403, 404, 429 ], true ) ? 'partial' : 'error',
						'message' => 'URLhaus ' . PDX_Http::http_error_message( $code ),
					];
				}
				$data = json_decode( wp_remote_retrieve_body( $http['response'] ), true );
				if ( ! is_array( $data ) ) {
					return [ 'state' => 'partial', 'message' => 'URLhaus returned an unexpected response.' ];
				}
				$cnt = (int) ( $data['url_count'] ?? 0 );
				return [
					'state'      => 'ok',
					'message'    => $cnt > 0 ? "{$cnt} malicious URL(s)" : 'Host clean in URLhaus',
					'indicators' => $cnt,
				];
			}
		);
	}

	private static function normalize_host( string $target ): string {
		$r = PDX_Target::resolve( $target );
		if ( is_wp_error( $r ) ) {
			return '';
		}
		return PDX_Target::scan_host( $r );
	}

	/**
	 * @return array{state:string,message?:string}
	 */
	private static function probe_cve(): array {
		if ( ! function_exists( 'pdx_container' ) ) {
			return [ 'state' => 'partial', 'message' => 'CVE engine not loaded.' ];
		}
		$result = pdx_container()->intel->fetch_cve( 'CVE-2021-44228' );
		if ( ! empty( $result['cves'] ) ) {
			return [ 'state' => 'ok', 'message' => 'NVD/CIRCL responded (' . ( $result['source'] ?? 'unknown' ) . ').' ];
		}
		return [
			'state'   => 'partial',
			'message' => (string) ( $result['error'] ?? 'CVE lookup returned no data.' ),
		];
	}

	/**
	 * @return array{state:string,message?:string}
	 */
	private static function probe_dns(): array {
		$http = PDX_Http::get(
			'https://cloudflare-dns.com/dns-query?name=example.com&type=A',
			[ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/dns-json' ] ],
			'dns_probe'
		);
		if ( is_wp_error( $http['response'] ) ) {
			return [ 'state' => 'error', 'message' => 'DNS over HTTPS unreachable.' ];
		}
		$code = (int) wp_remote_retrieve_response_code( $http['response'] );
		if ( 200 !== $code ) {
			return [ 'state' => 'partial', 'message' => 'DoH HTTP ' . $code ];
		}
		return [ 'state' => 'ok', 'message' => 'Passive DNS resolution active.' ];
	}

	/**
	 * @return array{state:string,message?:string}
	 */
	private static function probe_rdap( string $target ): array {
		if ( ! function_exists( 'pdx_container' ) ) {
			return [ 'state' => 'partial', 'message' => 'RDAP engine not loaded.' ];
		}
		$sample = 'example.com';
		if ( '' !== $target ) {
			$resolved = PDX_Target::resolve( $target );
			if ( ! is_wp_error( $resolved ) ) {
				$host = PDX_Target::scan_host( $resolved );
				if ( '' !== $host ) {
					$sample = $host;
				}
			}
		}
		$out   = pdx_container()->intel->fetch_rdap_resolved( $sample );
		$state = $out['status']['state'] ?? 'error';
		if ( 'ok' === $state ) {
			return [ 'state' => 'ok', 'message' => 'Registration data retrieved for ' . $sample . '.' ];
		}
		if ( 'skipped' === $state ) {
			return [ 'state' => 'partial', 'message' => (string) ( $out['status']['message'] ?? 'RDAP unavailable for this TLD.' ) ];
		}
		return [ 'state' => 'error', 'message' => (string) ( $out['status']['message'] ?? 'RDAP lookup failed.' ) ];
	}
}
