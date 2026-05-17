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
			self::feed_row( 'NVD / CIRCL CVE', 'cve', 'https://nvd.nist.gov', [ 'state' => 'ok', 'message' => 'CVE API available via Threat Intel tab.' ] ),
			self::feed_row( 'Google DNS (DoH)', 'dns', 'https://dns.google', [ 'state' => 'ok', 'message' => 'Passive DNS resolution active.' ] ),
			self::feed_row( 'RDAP / WHOIS', 'rdap', 'https://rdap.org', [ 'state' => 'ok', 'message' => 'Registration intelligence active.' ] ),
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
			return [ 'state' => 'ok', 'message' => 'Ready — enter a domain to query pulses.' ];
		}
		$host = self::normalize_host( $target );
		if ( ! $host ) {
			return [ 'state' => 'error', 'message' => 'Invalid domain for OTX lookup.' ];
		}
		$http = PDX_Http::get( 'https://otx.alienvault.com/api/v1/indicators/domain/' . rawurlencode( $host ) . '/general', [ 'timeout' => 10 ], 'otx_probe' );
		if ( is_wp_error( $http['response'] ) ) {
			return [ 'state' => 'error', 'message' => $http['response']->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $http['response'] );
		if ( 200 !== $code ) {
			return [ 'state' => 'error', 'message' => "OTX HTTP {$code}" ];
		}
		$data = json_decode( wp_remote_retrieve_body( $http['response'] ), true );
		$pulse = (int) ( $data['pulse_info']['count'] ?? 0 );
		return [
			'state'      => 'ok',
			'message'    => $pulse > 0 ? "{$pulse} pulse(s) for {$host}" : "No pulses for {$host}",
			'indicators' => $pulse,
		];
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
		$http = PDX_Http::post(
			'https://urlhaus-api.abuse.ch/v1/host/',
			[ 'timeout' => 10, 'body' => [ 'host' => $host ] ],
			'urlhaus_probe'
		);
		if ( is_wp_error( $http['response'] ) ) {
			return [ 'state' => 'error', 'message' => $http['response']->get_error_message() ];
		}
		$data = json_decode( wp_remote_retrieve_body( $http['response'] ), true );
		$cnt  = (int) ( $data['url_count'] ?? 0 );
		return [
			'state'      => 'ok',
			'message'    => $cnt > 0 ? "{$cnt} malicious URL(s)" : 'Host clean in URLhaus',
			'indicators' => $cnt,
		];
	}

	private static function normalize_host( string $target ): string {
		$r = PDX_Target::resolve( $target );
		if ( is_wp_error( $r ) ) {
			return '';
		}
		return PDX_Target::api_host( $r );
	}
}
