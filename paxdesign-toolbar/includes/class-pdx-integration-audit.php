<?php
/**
 * PDX_Integration_Audit — live provider probes and target-type validation.
 *
 * Used by admin REST endpoint and CLI audit script.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Integration_Audit {

	/** @var list<array<string, mixed>> */
	private array $results = [];

	public function __construct(
		private PDX_Intelligence $intel,
		private PDX_Settings $settings
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function run_full(): array {
		$this->results = [];

		$this->probe_target_normalization();
		$this->probe_verdict_integrity();
		$this->probe_rdap_domain();
		$this->probe_rdap_ip();
		$this->probe_reverse_dns();
		$this->probe_dns();
		$this->probe_geo();
		$this->probe_threat_feeds();
		$this->probe_ssl_labs();
		$this->probe_virustotal();
		$this->probe_shodan();
		$this->probe_hunter();
		$this->probe_nvd_cve();
		$this->probe_url_analysis();
		$this->probe_openai();
		$this->probe_abuseipdb_note();

		$failed  = array_filter( $this->results, static fn( $r ) => 'error' === ( $r['status'] ?? '' ) );
		$skipped = array_filter( $this->results, static fn( $r ) => 'skipped' === ( $r['status'] ?? '' ) );

		return [
			'timestamp'   => gmdate( 'c' ),
			'engine'      => 'pdx-integration-audit',
			'version'     => PDX_VERSION,
			'summary'     => [
				'total'   => count( $this->results ),
				'ok'      => count( array_filter( $this->results, static fn( $r ) => 'ok' === ( $r['status'] ?? '' ) ) ),
				'partial' => count( array_filter( $this->results, static fn( $r ) => 'partial' === ( $r['status'] ?? '' ) ) ),
				'error'   => count( $failed ),
				'skipped' => count( $skipped ),
			],
			'providers'   => $this->results,
			'target_types'=> $this->target_type_matrix(),
		];
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function record( string $provider, string $status, string $message, float $started, array $extra = [] ): void {
		$this->results[] = array_merge(
			[
				'provider'    => $provider,
				'status'      => $status,
				'message'     => $message,
				'latency_ms'  => round( ( microtime( true ) - $started ) * 1000, 1 ),
			],
			$extra
		);
	}

	private function probe_target_normalization(): void {
		$started = microtime( true );
		$cases   = [
			[ '8.8.8.8', 'ip' ],
			[ '2001:4860:4860::8888', 'ip' ],
			[ 'example.com', 'domain' ],
			[ 'www.example.com', 'domain' ],
			[ 'https://example.com/path?q=1', 'url' ],
			[ 'user@example.com', 'email' ],
			[ 'd41d8cd98f00b204e9800998ecf8427e', 'hash' ],
		];
		$bad = [];
		foreach ( $cases as [ $raw, $expected ] ) {
			$r = PDX_Target::resolve( $raw );
			if ( is_wp_error( $r ) || ( $r['type'] ?? '' ) !== $expected ) {
				$bad[] = $raw . '=>' . ( is_wp_error( $r ) ? $r->get_error_code() : ( $r['type'] ?? '?' ) );
			}
		}
		$this->record(
			'Target normalization',
			empty( $bad ) ? 'ok' : 'error',
			empty( $bad ) ? 'All canonical target types normalize correctly.' : 'Failed: ' . implode( ', ', $bad ),
			$started
		);
	}

	private function probe_verdict_integrity(): void {
		$started = microtime( true );
		$ref     = new ReflectionClass( $this->intel );
		$method  = $ref->getMethod( 'resolve_verdict' );
		$method->setAccessible( true );

		$failed_sources = [
			'dns'    => [ 'state' => 'error' ],
			'threat' => [ 'state' => 'error' ],
		];
		$verdict = $method->invoke( $this->intel, 5, $failed_sources, [], 'domain' );

		$this->record(
			'Verdict integrity',
			'insufficient_data' === $verdict ? 'ok' : 'error',
			'insufficient_data' === $verdict
				? 'Failed sources never produce Clean/Low Risk verdicts.'
				: 'Verdict was "' . $verdict . '" with failed required sources.',
			$started,
			[ 'sample_verdict' => $verdict ]
		);
	}

	private function probe_rdap_domain(): void {
		$started = microtime( true );
		$ref     = new ReflectionClass( $this->intel );
		$method  = $ref->getMethod( 'fetch_rdap_resolved' );
		$method->setAccessible( true );
		$out     = $method->invoke( $this->intel, 'example.com' );
		$state   = $out['status']['state'] ?? 'error';
		$this->record(
			'RDAP domain',
			'ok' === $state ? 'ok' : ( 'partial' === $state ? 'partial' : 'error' ),
			(string) ( $out['status']['message'] ?? 'No message' ) . ' (RDAP replaces legacy WHOIS for domains.)',
			$started,
			[ 'sample' => $out['data']['handle'] ?? null ]
		);
	}

	private function probe_rdap_ip(): void {
		$started = microtime( true );
		$ref     = new ReflectionClass( $this->intel );
		$method  = $ref->getMethod( 'fetch_rdap_ip' );
		$method->setAccessible( true );
		$out     = $method->invoke( $this->intel, '8.8.8.8' );
		$state   = $out['status']['state'] ?? 'error';
		$this->record(
			'RDAP IP network',
			'ok' === $state ? 'ok' : 'error',
			(string) ( $out['status']['message'] ?? 'No message' ),
			$started,
			[ 'sample' => $out['data']['name'] ?? $out['data']['handle'] ?? null ]
		);
	}

	private function probe_reverse_dns(): void {
		$started = microtime( true );
		$out     = $this->intel->fetch_reverse_dns( '8.8.8.8' );
		$state   = $out['status']['state'] ?? 'error';
		$this->record(
			'Reverse DNS',
			in_array( $state, [ 'ok', 'partial' ], true ) ? $state : 'error',
			(string) ( $out['status']['message'] ?? 'No message' ),
			$started,
			[ 'ptr' => $out['data']['hostname'] ?? null ]
		);
	}

	private function probe_dns(): void {
		$started = microtime( true );
		$ref     = new ReflectionClass( $this->intel );
		$method  = $ref->getMethod( 'fetch_dns' );
		$method->setAccessible( true );
		$out     = $method->invoke( $this->intel, 'example.com' );
		$state   = $out['status']['state'] ?? 'error';
		$this->record(
			'DNS (DoH)',
			in_array( $state, [ 'ok', 'partial' ], true ) ? $state : 'error',
			(string) ( $out['status']['message'] ?? 'No message' ),
			$started,
			[ 'a_records' => count( (array) ( $out['data']['a'] ?? [] ) ) ]
		);
	}

	private function probe_geo(): void {
		$started = microtime( true );
		$geo     = $this->intel->fetch_geo( '8.8.8.8' );
		$this->record(
			'GeoIP (ip-api.com)',
			$geo ? 'ok' : 'error',
			$geo ? ( 'Resolved ' . ( $geo['country'] ?? 'unknown' ) ) : 'Geolocation lookup failed.',
			$started,
			[ 'country' => $geo['country'] ?? null, 'asn' => $geo['asn'] ?? null ]
		);
	}

	private function probe_threat_feeds(): void {
		$started = microtime( true );
		$ref     = new ReflectionClass( $this->intel );
		$method  = $ref->getMethod( 'fetch_threat_intel' );
		$method->setAccessible( true );
		$resolved = PDX_Target::resolve( 'example.com' );
		$out      = $method->invoke( $this->intel, 'example.com', false, 'domain', $resolved );
		$state    = $out['status']['state'] ?? 'error';
		$this->record(
			'Threat feeds (OTX + URLhaus)',
			in_array( $state, [ 'ok', 'partial' ], true ) ? $state : 'error',
			(string) ( $out['status']['message'] ?? 'No message' ),
			$started,
			[ 'checked' => ! empty( $out['data']['checked'] ) ]
		);
	}

	private function probe_ssl_labs(): void {
		$started = microtime( true );
		$ref     = new ReflectionClass( $this->intel );
		$method  = $ref->getMethod( 'fetch_ssl_polled' );
		$method->setAccessible( true );
		$out     = $method->invoke( $this->intel, 'example.com' );
		$state   = $out['status']['state'] ?? 'error';
		$this->record(
			'SSL Labs',
			in_array( $state, [ 'ok', 'partial', 'skipped' ], true ) ? $state : 'error',
			(string) ( $out['status']['message'] ?? 'No message' ),
			$started,
			[ 'grade' => $out['data']['endpoints'][0]['grade'] ?? null ]
		);
	}

	private function probe_virustotal(): void {
		$started = microtime( true );
		$key     = (string) $this->settings->get( 'api_keys.virustotal', '' );
		if ( '' === $key ) {
			$this->record( 'VirusTotal', 'skipped', 'API key not configured.', $started );
			return;
		}
		$vt = $this->intel->fetch_virustotal( 'example.com', 'domain', PDX_Target::resolve( 'example.com' ) );
		$this->record(
			'VirusTotal',
			$vt ? 'ok' : 'error',
			$vt ? 'Domain report retrieved.' : 'VirusTotal request failed.',
			$started,
			[ 'malicious' => $vt['malicious'] ?? null ]
		);
	}

	private function probe_shodan(): void {
		$started = microtime( true );
		$key     = (string) $this->settings->get( 'api_keys.shodan', '' );
		if ( '' === $key ) {
			$this->record( 'Shodan', 'skipped', 'API key not configured.', $started );
			return;
		}
		$ref    = new ReflectionClass( $this->intel );
		$method = $ref->getMethod( 'fetch_shodan' );
		$method->setAccessible( true );
		$data   = $method->invoke( $this->intel, '8.8.8.8' );
		$this->record(
			'Shodan',
			$data ? 'ok' : 'error',
			$data ? 'Host data retrieved.' : 'Shodan request failed.',
			$started,
			[ 'ports' => count( (array) ( $data['ports'] ?? [] ) ) ]
		);
	}

	private function probe_hunter(): void {
		$started = microtime( true );
		$key     = (string) $this->settings->get( 'api_keys.hunter', '' );
		if ( '' === $key ) {
			$this->record( 'Hunter.io', 'skipped', 'API key not configured.', $started );
			return;
		}
		$ref    = new ReflectionClass( $this->intel );
		$method = $ref->getMethod( 'fetch_hunter' );
		$method->setAccessible( true );
		$data   = $method->invoke( $this->intel, 'example.com' );
		$this->record(
			'Hunter.io',
			$data ? 'ok' : 'partial',
			$data ? 'Domain search responded.' : 'Hunter.io returned no data (may be normal for example.com).',
			$started
		);
	}

	private function probe_nvd_cve(): void {
		$started = microtime( true );
		$result  = $this->intel->fetch_cve( 'CVE-2021-44228' );
		$source  = (string) ( $result['source'] ?? '' );
		$status  = ( ! empty( $result['cves'] ) && 'error' !== $source ) ? 'ok' : ( 'none' === $source ? 'partial' : 'error' );
		$this->record(
			'NVD / CIRCL CVE',
			$status,
			! empty( $result['cves'] ) ? 'CVE sample retrieved.' : (string) ( $result['error'] ?? 'No CVE data returned.' ),
			$started,
			[ 'total' => (int) ( $result['total'] ?? 0 ) ]
		);
	}

	private function probe_url_analysis(): void {
		$started  = microtime( true );
		$resolved = PDX_Target::resolve( 'https://example.com/' );
		$out      = PDX_Url_Analyzer::analyze( 'https://example.com/', is_array( $resolved ) ? $resolved : [] );
		$state    = $out['status']['state'] ?? 'error';
		$this->record(
			'URL forensics',
			in_array( $state, [ 'ok', 'partial', 'skipped' ], true ) ? $state : 'error',
			(string) ( $out['status']['message'] ?? 'No message' ),
			$started,
			[ 'redirect_count' => (int) ( $out['redirect_count'] ?? 0 ) ]
		);
	}

	private function probe_openai(): void {
		$started = microtime( true );
		$key_err = PDX_AI_Service::api_key( $this->settings );
		if ( is_wp_error( $key_err ) ) {
			$this->record( 'OpenAI', 'skipped', $key_err->get_error_message(), $started );
			return;
		}
		$this->record( 'OpenAI', 'ok', 'API key configured (live completion not invoked in audit).', $started );
	}

	private function probe_abuseipdb_note(): void {
		$this->record(
			'AbuseIPDB',
			'skipped',
			'Not integrated in this release — GeoIP uses ip-api.com; threat uses OTX/URLhaus/VirusTotal.',
			microtime( true )
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function target_type_matrix(): array {
		$matrix = [];
		$samples = [
			'ipv4'      => '8.8.8.8',
			'ipv6'      => '2001:4860:4860::8888',
			'domain'    => 'example.com',
			'subdomain' => 'www.example.com',
			'url'       => 'https://example.com/login',
			'email'     => 'test@example.com',
			'hash'      => 'd41d8cd98f00b204e9800998ecf8427e',
			'hostname'  => 'mail.example.com',
		];
		foreach ( $samples as $label => $raw ) {
			$r = PDX_Target::resolve( $raw );
			$matrix[] = [
				'label'    => $label,
				'input'    => $raw,
				'ok'       => ! is_wp_error( $r ),
				'type'     => is_wp_error( $r ) ? null : ( $r['type'] ?? null ),
				'normalized' => is_wp_error( $r ) ? null : ( $r['normalized'] ?? null ),
				'error'    => is_wp_error( $r ) ? $r->get_error_message() : null,
			];
		}
		return $matrix;
	}
}
