<?php
/**
 * PDX_Intelligence — multi-source threat intelligence aggregation engine.
 *
 * Handles: RDAP, SSL Labs, VirusTotal, Shodan, Hunter.io, ip-api,
 *          behavioral scoring, anomaly detection, risk matrix.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Intelligence {

	public function __construct( private PDX_Settings $settings ) {}

	/* ── Orchestration ──────────────────────────────────── */

	/**
	 * Full intelligence scan — runs all available sources and returns
	 * a structured report with risk scoring.
	 */
	public function full_scan( string $raw_target, bool $paid = false ): array {
		PDX_Http::reset_debug_log();
		$start = microtime( true );

		$resolved = PDX_Target::resolve( $raw_target );
		if ( is_wp_error( $resolved ) ) {
			return $this->error_report( $raw_target, $resolved );
		}

		$target = PDX_Target::api_host( $resolved );
		$report = [
			'target'         => $target,
			'target_raw'     => $resolved['raw'],
			'target_type'    => $resolved['type'],
			'target_meta'    => $resolved,
			'scan_id'        => 'scan-' . substr( bin2hex( random_bytes( 6 ) ), 0, 10 ),
			'timestamp'      => gmdate( 'c' ),
			'paid'           => $paid,
			'sources'        => [],
			'source_status'  => [],
			'risk'           => [],
			'timeline'       => [],
			'indicators'     => [],
			'debug'          => [],
		];

		// RDAP / WHOIS
		$rdap = $this->fetch_rdap_resolved( $target );
		$report['source_status']['rdap'] = $rdap['status'];
		if ( ! empty( $rdap['data'] ) ) {
			$report['sources']['rdap'] = $this->parse_rdap( $rdap['data'] );
			if ( ! empty( $rdap['queried'] ) && $rdap['queried'] !== $target ) {
				$report['sources']['rdap']['note'] = sprintf(
					'Registration data for parent zone %s (subdomain RDAP unavailable).',
					$rdap['queried']
				);
			}
			$report['timeline'] = array_merge( $report['timeline'], $this->extract_timeline( $rdap['data'] ) );
		}

		// DNS (Google DoH)
		$dns = $this->fetch_dns( $target );
		$report['source_status']['dns'] = $dns['status'];
		if ( ! empty( $dns['data'] ) ) {
			$report['sources']['dns'] = $dns['data'];
		}

		// SSL Labs (with polling)
		$ssl = $this->fetch_ssl_polled( $target );
		$report['source_status']['ssl'] = $ssl['status'];
		if ( ! empty( $ssl['data'] ) ) {
			$report['sources']['ssl'] = $this->parse_ssl( $ssl['data'] );
			if ( ! empty( $ssl['message'] ) ) {
				$report['sources']['ssl']['note'] = $ssl['message'];
			}
		}

		// Geolocation from resolved A/AAAA record (free)
		$resolved_ip = $this->resolve_host_ip( $target );
		if ( $resolved_ip ) {
			$geo = $this->fetch_geo( $resolved_ip );
			if ( $geo ) {
				$report['sources']['geolocation'] = $geo;
				$report['sources']['geo']         = $geo;
				$report['source_status']['geo']   = [ 'state' => 'ok', 'message' => 'Resolved via ' . $resolved_ip ];
			} else {
				$report['source_status']['geo'] = [ 'state' => 'error', 'message' => 'Geolocation lookup failed for ' . $resolved_ip ];
			}
		} else {
			$report['source_status']['geo'] = [ 'state' => 'error', 'message' => 'Could not resolve host to an IP address.' ];
		}

		// Free + paid threat intelligence (OTX, URLhaus; VT when paid + key)
		$threat = $this->fetch_threat_intel( $target, $paid );
		$report['source_status']['threat'] = $threat['status'];
		if ( ! empty( $threat['data'] ) ) {
			$report['sources']['threat']     = $threat['data'];
			$report['sources']['virustotal'] = $threat['data']['virustotal'] ?? null;
			if ( ! empty( $threat['data']['virustotal'] ) ) {
				$report['indicators'] = array_merge( $report['indicators'], $this->extract_iocs( $threat['data']['virustotal'] ) );
			}
		}

		if ( $paid ) {
			$vt = $this->fetch_virustotal( $target );
			if ( $vt ) {
				$vt_threat = $this->map_vt_to_threat( $vt );
				$report['sources']['virustotal'] = $vt;
				if ( empty( $report['sources']['threat'] ) ) {
					$report['sources']['threat'] = $vt_threat;
				} else {
					$report['sources']['threat']['malicious']  = max( (int) $report['sources']['threat']['malicious'], (int) $vt_threat['malicious'] );
					$report['sources']['threat']['suspicious'] = max( (int) $report['sources']['threat']['suspicious'], (int) $vt_threat['suspicious'] );
					$report['sources']['threat']['harmless']   = max( (int) $report['sources']['threat']['harmless'], (int) $vt_threat['harmless'] );
					$report['sources']['threat']['virustotal'] = $vt;
					$report['sources']['threat']['feeds']      = array_values(
						array_unique(
							array_merge(
								(array) ( $report['sources']['threat']['feeds'] ?? [] ),
								(array) ( $vt_threat['feeds'] ?? [] )
							)
						)
					);
				}
				$report['source_status']['threat'] = [ 'state' => 'ok', 'message' => 'VirusTotal + open feeds' ];
				$report['indicators']              = array_merge( $report['indicators'], $this->extract_iocs( $vt ) );
			}

			$shodan = $this->fetch_shodan( $resolved_ip ?: $target );
			if ( $shodan ) {
				$report['sources']['shodan']           = $shodan;
				$report['source_status']['shodan']     = [ 'state' => 'ok', 'message' => 'Host data retrieved' ];
			} else {
				$report['source_status']['shodan'] = [ 'state' => 'skipped', 'message' => 'Shodan API key missing or host not indexed.' ];
			}

			$hunter = $this->fetch_hunter( $target );
			if ( $hunter ) {
				$report['sources']['hunter']       = $hunter;
				$report['source_status']['hunter'] = [ 'state' => 'ok', 'message' => 'Email discovery complete' ];
			}
		}

		$report['risk']           = $this->compute_risk( $report['sources'], $report['source_status'] );
		$report['confidence']     = $this->compute_confidence( $report['source_status'] );
		$narrative                = $this->build_narrative( $target, $report );
		$report['ai_summary']      = $narrative['summary'];
		$report['recommendations'] = $narrative['recommendations'];
		$report['duration']        = round( microtime( true ) - $start, 3 );
		$report['debug']           = PDX_Http::get_debug_log();

		return $report;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function error_report( string $raw_target, WP_Error $error ): array {
		return [
			'target'          => $raw_target,
			'target_raw'      => $raw_target,
			'target_type'     => 'unknown',
			'scan_id'         => 'scan-' . substr( bin2hex( random_bytes( 6 ) ), 0, 10 ),
			'timestamp'       => gmdate( 'c' ),
			'paid'            => false,
			'sources'         => [],
			'source_status'   => [
				'normalize' => [
					'state'   => 'error',
					'message' => $error->get_error_message(),
				],
			],
			'risk'            => [
				'score'   => 0,
				'verdict' => 'insufficient_data',
				'label'   => 'Invalid Target',
				'factors' => [],
			],
			'confidence'      => 0,
			'ai_summary'      => 'Target could not be normalized: ' . $error->get_error_message(),
			'recommendations' => [ 'Enter a valid domain (e.g. example.com), IP, or URL without typos.' ],
			'debug'           => [],
		];
	}

	/**
	 * @param array<string, mixed> $status
	 * @param array<string, mixed> $log
	 * @return array<string, mixed>
	 */
	private function with_http_log( array $status, array $log ): array {
		return PDX_Http::enrich_status( $status, $log );
	}

	/* ── RDAP ───────────────────────────────────────────── */

	public function fetch_rdap( string $domain ): ?array {
		$result = $this->fetch_rdap_resolved( $domain );
		return $result['data'] ?? null;
	}

	/**
	 * RDAP with parent-domain fallback for subdomains (e.g. juice-shop.herokuapp.com).
	 *
	 * @return array{data:?array,status:array,queried:string}
	 */
	public function fetch_rdap_resolved( string $domain ): array {
		$tried    = $this->rdap_lookup_candidates( $domain );
		$last_err = 'RDAP lookup failed.';
		$last_log = [];

		foreach ( $tried as $candidate ) {
			$url  = 'https://rdap.org/domain/' . rawurlencode( $candidate );
			$http = PDX_Http::get(
				$url,
				[
					'timeout' => 15,
					'headers' => [ 'Accept' => 'application/rdap+json, application/json' ],
				],
				'rdap'
			);
			$resp = $http['response'];

			if ( is_wp_error( $resp ) ) {
				$last_err = $resp->get_error_message();
				$last_log = $http['log'];
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $code ) {
				$last_err = "RDAP HTTP {$code} for {$candidate}";
				$last_log = $http['log'];
				continue;
			}

			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $body ) || empty( $body['ldhName'] ) ) {
				$last_err = 'Invalid RDAP response (parse failed).';
				$last_log = $http['log'];
				$last_log['parse_status'] = 'parse_error';
				continue;
			}

			$last_log['parse_status'] = 'ok';

			return [
				'data'    => $body,
				'queried' => $candidate,
				'status'  => $this->with_http_log(
					[
						'state'   => 'ok',
						'message' => $candidate === $domain ? 'Registration data retrieved.' : "Parent zone {$candidate} used.",
					],
					$http['log']
				),
			];
		}

		$fail_status = [ 'state' => 'error', 'message' => $last_err ];
		if ( ! empty( $last_log ) ) {
			$fail_status = $this->with_http_log( $fail_status, $last_log );
		}

		return [
			'data'    => null,
			'queried' => $domain,
			'status'  => $fail_status,
		];
	}

	/**
	 * @return list<string>
	 */
	private function rdap_lookup_candidates( string $domain ): array {
		$candidates = [ $domain ];
		$parts      = explode( '.', $domain );
		if ( count( $parts ) > 2 ) {
			$candidates[] = implode( '.', array_slice( $parts, -2 ) );
		}
		return array_values( array_unique( $candidates ) );
	}

	private function parse_rdap( array $rdap ): array {
		$events     = [];
		$nameservers = [];
		$registrar  = null;
		$registrant = null;

		foreach ( $rdap['events'] ?? [] as $e ) {
			$events[ $e['eventAction'] ] = substr( $e['eventDate'] ?? '', 0, 10 );
		}

		foreach ( $rdap['nameservers'] ?? [] as $ns ) {
			$nameservers[] = strtolower( $ns['ldhName'] ?? '' );
		}

		foreach ( $rdap['entities'] ?? [] as $ent ) {
			$roles = $ent['roles'] ?? [];
			$vc    = $ent['vcardArray'][1] ?? [];
			$name  = null;
			foreach ( $vc as $v ) {
				if ( $v[0] === 'fn' ) { $name = $v[3]; break; }
			}
			if ( in_array( 'registrar', $roles, true ) )  $registrar  = $name;
			if ( in_array( 'registrant', $roles, true ) ) $registrant = $name;
		}

		$age_days = null;
		if ( ! empty( $events['registration'] ) ) {
			$age_days = (int) floor( ( time() - strtotime( $events['registration'] ) ) / 86400 );
		}

		return [
			'label'       => 'Domain Registration',
			'registrar'   => $registrar ?? 'Unknown',
			'registrant'  => $registrant ?? 'Redacted',
			'registered'  => $events['registration'] ?? null,
			'updated'     => $events['last changed'] ?? null,
			'expires'     => $events['expiration'] ?? null,
			'age_days'    => $age_days,
			'status'      => array_slice( $rdap['status'] ?? [], 0, 5 ),
			'nameservers' => $nameservers,
		];
	}

	private function extract_timeline( array $rdap ): array {
		$timeline = [];
		foreach ( $rdap['events'] ?? [] as $e ) {
			if ( empty( $e['eventDate'] ) ) continue;
			$timeline[] = [
				'ts'     => $e['eventDate'],
				'event'  => $e['eventAction'],
				'source' => 'rdap',
			];
		}
		return $timeline;
	}

	/* ── SSL Labs ───────────────────────────────────────── */

	public function fetch_ssl( string $domain ): ?array {
		$result = $this->fetch_ssl_polled( $domain );
		return $result['data'] ?? null;
	}

	/**
	 * Poll SSL Labs until READY, ERROR, or attempt limit.
	 *
	 * @return array{data:?array,status:array,message?:string}
	 */
	public function fetch_ssl_polled( string $domain ): array {
		$url      = 'https://api.ssllabs.com/api/v3/analyze?host=' . rawurlencode( $domain ) . '&fromCache=on&maxAge=24&all=done';
		$attempts = 6;
		$body     = null;
		$last_err = 'SSL Labs assessment unavailable.';
		$last_log = [];

		for ( $i = 0; $i < $attempts; $i++ ) {
			$http = PDX_Http::get( $url, [ 'timeout' => 25 ], 'ssl_labs' );
			$resp = $http['response'];
			$last_log = $http['log'];

			if ( is_wp_error( $resp ) ) {
				$last_err = $resp->get_error_message();
				break;
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $code ) {
				$last_err = "SSL Labs HTTP {$code}";
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $body ) ) {
				$last_err = 'Invalid SSL Labs response (parse failed).';
				$last_log['parse_status'] = 'parse_error';
				break;
			}

			$status = strtoupper( (string) ( $body['status'] ?? '' ) );
			if ( in_array( $status, [ 'READY', 'ERROR' ], true ) ) {
				$last_log['parse_status'] = 'ok';
				if ( 'ERROR' === $status ) {
					return [
						'data'    => $body,
						'status'  => $this->with_http_log(
							[ 'state' => 'error', 'message' => $body['statusMessage'] ?? 'SSL Labs returned an error.' ],
							$last_log
						),
						'message' => $body['statusMessage'] ?? null,
					];
				}
				return [
					'data'   => $body,
					'status' => $this->with_http_log(
						[ 'state' => 'ok', 'message' => 'Assessment complete.' ],
						$last_log
					),
				];
			}

			if ( $i < $attempts - 1 ) {
				sleep( 3 );
				$url = 'https://api.ssllabs.com/api/v3/analyze?host=' . rawurlencode( $domain ) . '&startNew=on&all=done';
			}
		}

		if ( is_array( $body ) ) {
			return [
				'data'    => $body,
				'status'  => $this->with_http_log(
					[ 'state' => 'partial', 'message' => 'Assessment still in progress; partial data shown.' ],
					$last_log
				),
				'message' => 'SSL Labs scan did not finish in time. Retry for a full grade.',
			];
		}

		$fail = [ 'state' => 'error', 'message' => $last_err ];
		if ( ! empty( $last_log ) ) {
			$fail = $this->with_http_log( $fail, $last_log );
		}

		return [
			'data'   => null,
			'status' => $fail,
		];
	}

	private function parse_ssl( array $ssl ): array {
		$endpoints = [];
		$issuer    = null;
		$subject   = null;
		$valid_from = null;
		$valid_to   = null;

		foreach ( $ssl['endpoints'] ?? [] as $ep ) {
			$cert = $ep['details']['cert'] ?? [];
			if ( ! $issuer && ! empty( $cert['issuerLabel'] ) ) {
				$issuer = $cert['issuerLabel'];
			}
			if ( ! $subject && ! empty( $cert['subject'] ) ) {
				$subject = $cert['subject'];
			}
			if ( ! empty( $cert['notBefore'] ) ) {
				$valid_from = substr( (string) $cert['notBefore'], 0, 10 );
			}
			if ( ! empty( $cert['notAfter'] ) ) {
				$valid_to = substr( (string) $cert['notAfter'], 0, 10 );
			}

			$endpoints[] = [
				'ip'           => $ep['ipAddress'] ?? null,
				'grade'        => $ep['grade'] ?? 'N/A',
				'status'       => $ep['statusMessage'] ?? 'Unknown',
				'has_warnings' => ! empty( $ep['hasWarnings'] ),
			];
		}

		$best_grade = null;
		foreach ( $endpoints as $ep ) {
			if ( ! empty( $ep['grade'] ) && 'N/A' !== $ep['grade'] ) {
				$best_grade = $ep['grade'];
				break;
			}
		}

		$days_remaining = null;
		if ( $valid_to ) {
			$days_remaining = (int) floor( ( strtotime( $valid_to ) - time() ) / 86400 );
		}

		return [
			'label'          => 'SSL / TLS',
			'status'         => $ssl['status'] ?? 'UNKNOWN',
			'grade'          => $best_grade,
			'assessed'       => null !== $best_grade,
			'endpoints'      => $endpoints,
			'protocol'       => $ssl['protocol'] ?? null,
			'issuer'         => $issuer,
			'subject'        => $subject,
			'valid_from'     => $valid_from,
			'valid_to'       => $valid_to,
			'days_remaining' => $days_remaining,
		];
	}

	/**
	 * DNS via Google DoH — structured for TrustCheck UI.
	 *
	 * @return array{data:?array,status:array}
	 */
	public function fetch_dns( string $domain ): array {
		$types   = [ 'A', 'AAAA', 'MX', 'TXT', 'NS', 'CAA' ];
		$records = [ 'a' => [], 'aaaa' => [], 'mx' => [], 'txt' => [], 'ns' => [], 'caa' => [] ];
		$errors  = 0;
		$last_log = [];

		foreach ( $types as $type ) {
			$url  = 'https://dns.google/resolve?name=' . rawurlencode( $domain ) . '&type=' . $type;
			$http = PDX_Http::get(
				$url,
				[ 'timeout' => 10, 'headers' => [ 'Accept' => 'application/dns-json' ] ],
				'dns_' . strtolower( $type )
			);
			$resp     = $http['response'];
			$last_log = $http['log'];

			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				++$errors;
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( empty( $data['Answer'] ) || ! is_array( $data['Answer'] ) ) {
				continue;
			}
			$last_log['parse_status'] = 'ok';

			foreach ( $data['Answer'] as $rec ) {
				$value = (string) ( $rec['data'] ?? '' );
				if ( '' === $value ) {
					continue;
				}
				$key = strtolower( $type );
				if ( 'a' === $key || 'aaaa' === $key ) {
					$records[ $key ][] = $value;
				} elseif ( 'mx' === $key ) {
					$records['mx'][] = preg_replace( '/^\d+\s+/', '', $value );
				} else {
					$records[ $key ][] = $value;
				}
			}
		}

		foreach ( $records['txt'] as $txt ) {
			if ( stripos( $txt, 'v=spf1' ) !== false ) {
				$records['spf'] = $txt;
			}
			if ( stripos( $txt, 'v=DMARC1' ) !== false ) {
				$records['dmarc'] = $txt;
			}
		}

		$has_any = ! empty( $records['a'] ) || ! empty( $records['aaaa'] ) || ! empty( $records['mx'] )
			|| ! empty( $records['ns'] ) || ! empty( $records['txt'] );

		if ( ! $has_any ) {
			$status = [ 'state' => 'error', 'message' => 'No DNS records returned from resolver.' ];
			if ( ! empty( $last_log ) ) {
				$status = $this->with_http_log( $status, $last_log );
			}
			return [
				'data'   => null,
				'status' => $status,
			];
		}

		$records['label'] = 'DNS';
		$status = [
			'state'   => $errors > 0 ? 'partial' : 'ok',
			'message' => $errors > 0 ? 'Some record types could not be queried.' : 'DNS records retrieved.',
		];
		if ( ! empty( $last_log ) ) {
			$status = $this->with_http_log( $status, $last_log );
		}

		return [
			'data'   => $records,
			'status' => $status,
		];
	}

	/**
	 * Resolve hostname to first A or AAAA address.
	 */
	public function resolve_host_ip( string $host ): ?string {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}

		foreach ( [ 'A', 'AAAA' ] as $type ) {
			$http = PDX_Http::get(
				'https://dns.google/resolve?name=' . rawurlencode( $host ) . '&type=' . $type,
				[ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/dns-json' ] ],
				'dns_resolve'
			);
			$resp = $http['response'];
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				continue;
			}
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			foreach ( $data['Answer'] ?? [] as $rec ) {
				$ip = (string) ( $rec['data'] ?? '' );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return null;
	}

	/**
	 * Open-source threat feeds (OTX, URLhaus) + optional VirusTotal when paid.
	 *
	 * @return array{data:?array,status:array}
	 */
	public function fetch_threat_intel( string $target, bool $paid ): array {
		$feeds      = [];
		$malicious  = 0;
		$suspicious = 0;
		$errors     = 0;
		$last_log   = [];

		// AlienVault OTX (no API key required for indicator summary)
		$otx_url = 'https://otx.alienvault.com/api/v1/indicators/domain/' . rawurlencode( $target ) . '/general';
		$otx_http = PDX_Http::get( $otx_url, [ 'timeout' => 12 ], 'otx' );
		$otx      = $otx_http['response'];
		$last_log = $otx_http['log'];

		if ( ! is_wp_error( $otx ) && 200 === (int) wp_remote_retrieve_response_code( $otx ) ) {
			$odata = json_decode( wp_remote_retrieve_body( $otx ), true );
			$last_log['parse_status'] = is_array( $odata ) ? 'ok' : 'parse_error';
			$pulse = (int) ( $odata['pulse_info']['count'] ?? 0 );
			if ( $pulse > 0 ) {
				$suspicious += min( 5, $pulse );
				$feeds[] = "OTX ({$pulse} pulses)";
			} else {
				$feeds[] = 'OTX (0 pulses)';
			}
		} else {
			++$errors;
		}

		// URLhaus host lookup
		$urlhaus_http = PDX_Http::post(
			'https://urlhaus-api.abuse.ch/v1/host/',
			[
				'timeout' => 12,
				'body'    => [ 'host' => $target ],
			],
			'urlhaus'
		);
		$urlhaus  = $urlhaus_http['response'];
		$last_log = $urlhaus_http['log'];

		if ( ! is_wp_error( $urlhaus ) && 200 === (int) wp_remote_retrieve_response_code( $urlhaus ) ) {
			$udata = json_decode( wp_remote_retrieve_body( $urlhaus ), true );
			$last_log['parse_status'] = is_array( $udata ) ? 'ok' : 'parse_error';
			if ( 'ok' === ( $udata['query_status'] ?? '' ) && ! empty( $udata['url_count'] ) ) {
				$malicious += (int) $udata['url_count'];
				$feeds[]     = 'URLhaus (' . (int) $udata['url_count'] . ' URLs)';
			} else {
				$feeds[] = 'URLhaus (clean)';
			}
		} else {
			++$errors;
		}

		$data = [
			'label'      => 'Threat Intelligence',
			'malicious'  => $malicious,
			'suspicious' => $suspicious,
			'harmless'   => 0,
			'feeds'      => $feeds,
			'checked'    => true,
			'sources'    => array_filter( [ 'OTX', 'URLhaus' ] ),
		];

		if ( $errors >= 2 ) {
			$status = [ 'state' => 'error', 'message' => 'Threat feed queries failed. Check outbound HTTPS from the server.' ];
			if ( ! empty( $last_log ) ) {
				$status = $this->with_http_log( $status, $last_log );
			}
			return [
				'data'   => null,
				'status' => $status,
			];
		}

		$status = [
			'state'   => $errors > 0 ? 'partial' : 'ok',
			'message' => $malicious > 0 ? 'Malicious indicators found in open feeds.' : 'No malicious hits in open feeds.',
		];
		if ( ! empty( $last_log ) ) {
			$status = $this->with_http_log( $status, $last_log );
		}

		return [
			'data'   => $data,
			'status' => $status,
		];
	}

	/**
	 * @param array<string, mixed> $vt
	 * @return array<string, mixed>
	 */
	private function map_vt_to_threat( array $vt ): array {
		return [
			'label'      => 'Threat Intelligence',
			'malicious'  => (int) ( $vt['malicious'] ?? 0 ),
			'suspicious' => (int) ( $vt['suspicious'] ?? 0 ),
			'harmless'   => (int) ( $vt['harmless'] ?? 0 ),
			'feeds'      => array_merge( [ 'VirusTotal' ], (array) ( $vt['categories'] ?? [] ) ),
			'categories' => $vt['categories'] ?? [],
			'checked'    => true,
			'virustotal' => $vt,
		];
	}

	/* ── Geolocation ────────────────────────────────────── */

	public function fetch_geo( string $target ): ?array {
		$http = PDX_Http::get(
			'https://ip-api.com/json/' . rawurlencode( $target ) . '?fields=status,country,countryCode,regionName,city,zip,lat,lon,isp,org,as,query,hosting',
			[ 'timeout' => 8 ],
			'geo'
		);
		$resp = $http['response'];
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ( $data['status'] ?? '' ) !== 'success' ) return null;

		return [
			'label'    => 'IP Geolocation',
			'ip'       => $data['query']      ?? null,
			'country'  => $data['country']    ?? null,
			'code'     => $data['countryCode'] ?? null,
			'region'   => $data['regionName'] ?? null,
			'city'     => $data['city']       ?? null,
			'lat'      => $data['lat']        ?? null,
			'lon'      => $data['lon']        ?? null,
			'isp'      => $data['isp']        ?? null,
			'org'      => $data['org']        ?? null,
			'asn'      => $data['as']         ?? null,
			'hosting'  => (bool) ( $data['hosting'] ?? false ),
		];
	}

	/* ── VirusTotal ─────────────────────────────────────── */

	public function fetch_virustotal( string $target ): ?array {
		$key = $this->settings->get( 'api_keys.virustotal', '' );
		if ( ! $key ) return null;

		$resp = wp_remote_get(
			'https://www.virustotal.com/api/v3/domains/' . rawurlencode( $target ),
			[ 'timeout' => 12, 'headers' => [ 'x-apikey' => $key ] ]
		);
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;

		$data  = json_decode( wp_remote_retrieve_body( $resp ), true );
		$attrs = $data['data']['attributes'] ?? [];
		$stats = $attrs['last_analysis_stats'] ?? [];

		return [
			'label'       => 'VirusTotal',
			'malicious'   => (int) ( $stats['malicious']  ?? 0 ),
			'suspicious'  => (int) ( $stats['suspicious'] ?? 0 ),
			'clean'       => (int) ( $stats['undetected'] ?? 0 ),
			'harmless'    => (int) ( $stats['harmless']   ?? 0 ),
			'reputation'  => (int) ( $attrs['reputation'] ?? 0 ),
			'categories'  => array_values( $attrs['categories'] ?? [] ),
			'last_scan'   => $attrs['last_analysis_date'] ?? null,
			'tags'        => $attrs['tags'] ?? [],
		];
	}

	private function extract_iocs( array $vt ): array {
		$iocs = [];
		if ( $vt['malicious'] > 0 ) {
			$iocs[] = [ 'type' => 'malicious_domain', 'severity' => 'critical', 'source' => 'virustotal', 'count' => $vt['malicious'] ];
		}
		if ( $vt['suspicious'] > 0 ) {
			$iocs[] = [ 'type' => 'suspicious_domain', 'severity' => 'warn', 'source' => 'virustotal', 'count' => $vt['suspicious'] ];
		}
		return $iocs;
	}

	/* ── Shodan ─────────────────────────────────────────── */

	public function fetch_shodan( string $target ): ?array {
		$key = $this->settings->get( 'api_keys.shodan', '' );
		if ( ! $key ) return null;

		$resp = wp_remote_get(
			'https://api.shodan.io/shodan/host/' . rawurlencode( $target ) . '?key=' . rawurlencode( $key ),
			[ 'timeout' => 10 ]
		);
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data ) || isset( $data['error'] ) ) return null;

		$ports = array_unique( $data['ports'] ?? [] );
		sort( $ports );

		return [
			'label'    => 'Shodan',
			'ip'       => $data['ip_str']  ?? null,
			'org'      => $data['org']     ?? null,
			'isp'      => $data['isp']     ?? null,
			'country'  => $data['country_name'] ?? null,
			'ports'    => $ports,
			'vulns'    => array_keys( $data['vulns'] ?? [] ),
			'hostnames'=> $data['hostnames'] ?? [],
			'os'       => $data['os']      ?? null,
			'last_update' => $data['last_update'] ?? null,
		];
	}

	/* ── Hunter.io ──────────────────────────────────────── */

	public function fetch_hunter( string $domain ): ?array {
		$key = $this->settings->get( 'api_keys.hunter', '' );
		if ( ! $key ) return null;

		$resp = wp_remote_get(
			'https://api.hunter.io/v2/domain-search?domain=' . rawurlencode( $domain ) . '&api_key=' . rawurlencode( $key ) . '&limit=10',
			[ 'timeout' => 8 ]
		);
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		$d    = $data['data'] ?? [];

		return [
			'label'        => 'Email Intelligence',
			'total_emails' => $d['total'] ?? 0,
			'pattern'      => $d['pattern'] ?? null,
			'emails'       => array_slice( array_map( fn( $e ) => [
				'email'      => $e['value']      ?? null,
				'type'       => $e['type']       ?? null,
				'confidence' => $e['confidence'] ?? null,
				'first_name' => $e['first_name'] ?? null,
				'last_name'  => $e['last_name']  ?? null,
				'position'   => $e['position']   ?? null,
			], $d['emails'] ?? [] ), 0, 10 ),
		];
	}

	/* ── Risk Scoring ───────────────────────────────────── */

	/**
	 * Compute a 0-100 risk score and risk matrix from aggregated sources.
	 *
	 * @param array<string, mixed>                   $sources
	 * @param array<string, array{state?:string}>    $source_status
	 */
	public function compute_risk( array $sources, array $source_status = [], array $forensics = [] ): array {
		$score   = 0;
		$factors = [];

		// SSL grade — only when assessment completed
		$ssl = $sources['ssl'] ?? [];
		if ( ! empty( $ssl['assessed'] ) && ! empty( $ssl['grade'] ) ) {
			$grade_map = [ 'A+' => 0, 'A' => 5, 'A-' => 8, 'B' => 20, 'C' => 35, 'D' => 50, 'E' => 65, 'F' => 80, 'T' => 70, 'M' => 60 ];
			$ssl_grade = $ssl['grade'];
			$ssl_risk  = $grade_map[ $ssl_grade ] ?? 20;
			$score    += $ssl_risk;
			if ( $ssl_risk > 0 ) {
				$factors[] = [ 'factor' => 'SSL Grade', 'value' => $ssl_grade, 'risk' => $ssl_risk, 'weight' => 'medium' ];
			}
		}

		// Domain age (RDAP)
		$age_days = $sources['rdap']['age_days'] ?? null;
		if ( null !== $age_days ) {
			if ( $age_days < 30 ) {
				$score    += 30;
				$factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 30, 'weight' => 'high' ];
			} elseif ( $age_days < 180 ) {
				$score    += 15;
				$factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 15, 'weight' => 'medium' ];
			} elseif ( $age_days < 365 ) {
				$score    += 5;
				$factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 5, 'weight' => 'low' ];
			}
		}

		// Threat intelligence (aggregated + VT)
		$threat = $sources['threat'] ?? [];
		$mal    = (int) ( $threat['malicious'] ?? $sources['virustotal']['malicious'] ?? 0 );
		$sus    = (int) ( $threat['suspicious'] ?? $sources['virustotal']['suspicious'] ?? 0 );
		if ( $mal > 5 ) {
			$score    += 40;
			$factors[] = [ 'factor' => 'Threat Feed Detections', 'value' => "{$mal} malicious", 'risk' => 40, 'weight' => 'critical' ];
		} elseif ( $mal > 0 ) {
			$score    += 25;
			$factors[] = [ 'factor' => 'Threat Feed Detections', 'value' => "{$mal} malicious", 'risk' => 25, 'weight' => 'high' ];
		} elseif ( $sus > 0 ) {
			$score    += 12;
			$factors[] = [ 'factor' => 'Threat Feed Suspicious', 'value' => "{$sus} suspicious", 'risk' => 12, 'weight' => 'medium' ];
		}

		// Shodan
		if ( isset( $sources['shodan'] ) ) {
			$vulns = count( $sources['shodan']['vulns'] ?? [] );
			$ports = count( $sources['shodan']['ports'] ?? [] );
			if ( $vulns > 0 ) {
				$risk_v = min( 30, $vulns * 8 );
				$score += $risk_v;
				$factors[] = [ 'factor' => 'Known CVEs', 'value' => "{$vulns} CVEs", 'risk' => $risk_v, 'weight' => 'critical' ];
			}
			if ( $ports > 20 ) {
				$score    += 10;
				$factors[] = [ 'factor' => 'Open Ports', 'value' => "{$ports} ports", 'risk' => 10, 'weight' => 'medium' ];
			}
		}

		// Hosting / CDN (low weight)
		if ( ! empty( $sources['geolocation']['hosting'] ) ) {
			$score    += 3;
			$factors[] = [ 'factor' => 'Hosting Provider IP', 'value' => 'Yes', 'risk' => 3, 'weight' => 'low' ];
		}

		// Missing email auth (informational risk)
		$dns = $sources['dns'] ?? [];
		if ( ! empty( $dns ) && empty( $dns['spf'] ) && ! empty( $dns['mx'] ) ) {
			$score    += 5;
			$factors[] = [ 'factor' => 'Email Auth', 'value' => 'No SPF', 'risk' => 5, 'weight' => 'low' ];
		}

		// URL forensics / phishing heuristics (v8)
		$phish_score = (int) ( $forensics['phishing_score'] ?? $sources['url_forensics']['phishing']['score'] ?? 0 );
		if ( $phish_score >= 25 ) {
			$phish_risk = min( 35, $phish_score );
			$score     += $phish_risk;
			$factors[] = [
				'factor' => 'Phishing / Page Forensics',
				'value'  => ( $forensics['phishing_verdict'] ?? 'elevated' ) . " ({$phish_score})",
				'risk'   => $phish_risk,
				'weight' => $phish_score >= 45 ? 'critical' : 'high',
			];
		}

		if ( ! empty( $forensics['has_login_form'] ) ) {
			$score    += 10;
			$factors[] = [ 'factor' => 'Credential Form on Page', 'value' => 'Detected', 'risk' => 10, 'weight' => 'medium' ];
		}

		if ( ! empty( $forensics['redirect_hops'] ) && (int) $forensics['redirect_hops'] > 3 ) {
			$score    += 8;
			$factors[] = [ 'factor' => 'Redirect Chain', 'value' => (int) $forensics['redirect_hops'] . ' hops', 'risk' => 8, 'weight' => 'medium' ];
		}

		$path_risk = (int) ( $forensics['path_risk_score'] ?? 0 );
		if ( $path_risk >= 10 ) {
			$path_factor = min( 18, $path_risk );
			$score      += $path_factor;
			$factors[]   = [ 'factor' => 'Suspicious URL Path', 'value' => "{$path_risk}", 'risk' => $path_factor, 'weight' => 'high' ];
		}

		$landing_risk = (int) ( $forensics['landing_risk_score'] ?? 0 );
		if ( $landing_risk >= 10 ) {
			$land_factor = min( 20, $landing_risk );
			$score      += $land_factor;
			$factors[]   = [ 'factor' => 'Landing Page Heuristics', 'value' => "{$landing_risk}", 'risk' => $land_factor, 'weight' => 'high' ];
		}

		$intent = (string) ( $forensics['redirect_intent'] ?? '' );
		if ( in_array( $intent, [ 'multi_hop_laundering', 'cross_domain_delivery' ], true ) ) {
			$score    += 10;
			$factors[] = [ 'factor' => 'Redirect Intent', 'value' => $intent, 'risk' => 10, 'weight' => 'high' ];
		}

		if ( ! empty( $forensics['external_form_action'] ) ) {
			$score    += 14;
			$factors[] = [ 'factor' => 'Credential Exfiltration Form', 'value' => 'External action', 'risk' => 14, 'weight' => 'critical' ];
		}

		$malware = (array) ( $forensics['malware_indicators'] ?? [] );
		if ( ! empty( $malware ) ) {
			$mal_risk = min( 22, count( $malware ) * 8 );
			$score   += $mal_risk;
			$factors[] = [
				'factor' => 'Malware / Abuse Indicators',
				'value'  => implode( ', ', array_slice( $malware, 0, 3 ) ),
				'risk'   => $mal_risk,
				'weight' => 'high',
			];
		}

		$infra_score = (int) ( $forensics['infrastructure_score'] ?? 0 );
		if ( $infra_score > 0 ) {
			$score    += $infra_score;
			$factors[] = [
				'factor' => 'Infrastructure Fingerprint',
				'value'  => (string) ( $forensics['infrastructure_fingerprint'] ?? 'mapped' ),
				'risk'   => $infra_score,
				'weight' => 'medium',
			];
		}

		$score = min( 100, max( 0, $score ) );

		$ok_sources = 0;
		foreach ( [ 'rdap', 'dns', 'ssl', 'threat' ] as $key ) {
			if ( ( $source_status[ $key ]['state'] ?? '' ) === 'ok' ) {
				++$ok_sources;
			}
		}

		if ( 0 === $ok_sources && empty( $factors ) ) {
			$verdict = 'insufficient_data';
		} elseif ( $score >= 75 ) {
			$verdict = 'critical';
		} elseif ( $score >= 50 ) {
			$verdict = 'high';
		} elseif ( $score >= 25 ) {
			$verdict = 'medium';
		} elseif ( $score >= 10 ) {
			$verdict = 'low';
		} else {
			$verdict = 'clean';
		}

		$labels = [
			'clean'             => 'Clean',
			'low'               => 'Low Risk',
			'medium'            => 'Medium Risk',
			'high'              => 'High Risk',
			'critical'          => 'Critical',
			'insufficient_data' => 'Insufficient Data',
		];

		return [
			'score'      => $score,
			'verdict'    => $verdict,
			'factors'    => $factors,
			'label'      => $labels[ $verdict ] ?? ucfirst( $verdict ),
			'confidence' => $this->compute_confidence( $source_status ),
		];
	}

	/**
	 * @param array<string, array{state?:string}> $source_status
	 */
	public function compute_confidence( array $source_status ): int {
		$weights = [ 'rdap' => 25, 'dns' => 25, 'ssl' => 25, 'threat' => 25 ];
		$total   = 0;

		foreach ( $weights as $key => $weight ) {
			$state = $source_status[ $key ]['state'] ?? 'error';
			if ( 'ok' === $state ) {
				$total += $weight;
			} elseif ( 'partial' === $state ) {
				$total += (int) round( $weight * 0.5 );
			}
		}

		return min( 100, max( 0, $total ) );
	}

	/**
	 * Server-side narrative aligned with computed risk (no contradictory client text).
	 *
	 * @param array<string, mixed> $report
	 * @return array{summary:string,recommendations:list<string>}
	 */
	public function build_narrative( string $target, array $report ): array {
		$risk    = $report['risk'] ?? [];
		$score   = (int) ( $risk['score'] ?? 0 );
		$verdict = (string) ( $risk['verdict'] ?? 'insufficient_data' );
		$sources = $report['sources'] ?? [];
		$status  = $report['source_status'] ?? [];
		$recs    = [];

		$verdict_phrases = [
			'clean'             => 'no significant risk indicators were identified from available intelligence sources',
			'low'               => 'low-level risk indicators were identified',
			'medium'            => 'moderate risk indicators require review',
			'high'              => 'high-risk indicators were detected',
			'critical'          => 'critical risk indicators were detected',
			'insufficient_data' => 'insufficient intelligence was collected to produce a reliable risk assessment',
		];

		$phrase = $verdict_phrases[ $verdict ] ?? $verdict_phrases['insufficient_data'];
		$parts  = [ sprintf( 'Analysis of %s: %s.', $target, $phrase ) ];

		if ( 'insufficient_data' !== $verdict ) {
			$parts[] = sprintf( 'Composite risk score: %d/100 (%s).', $score, $risk['label'] ?? $verdict );
		}

		if ( ! empty( $sources['rdap']['age_days'] ) ) {
			$age = (int) $sources['rdap']['age_days'];
			if ( $age < 90 ) {
				$parts[] = "Domain registration age is {$age} days.";
				$recs[]  = 'Treat recently registered infrastructure with heightened scrutiny.';
			}
		}

		if ( ! empty( $sources['ssl']['grade'] ) && ! empty( $sources['ssl']['assessed'] ) ) {
			$parts[] = 'SSL/TLS grade: ' . $sources['ssl']['grade'] . '.';
			if ( ! in_array( $sources['ssl']['grade'], [ 'A+', 'A' ], true ) ) {
				$recs[] = 'Review TLS configuration and enable HSTS where appropriate.';
			}
		} elseif ( ( $status['ssl']['state'] ?? '' ) === 'error' ) {
			$parts[] = 'SSL Labs assessment could not be completed.';
			$recs[]  = 'Retry SSL assessment or verify the host presents a valid HTTPS certificate.';
		}

		$threat = $sources['threat'] ?? [];
		if ( ! empty( $threat['malicious'] ) ) {
			$parts[] = (int) $threat['malicious'] . ' malicious indicator(s) reported by threat feeds.';
			$recs[]  = 'Block or isolate traffic to this target pending further investigation.';
		} elseif ( ( $status['threat']['state'] ?? '' ) === 'ok' ) {
			$parts[] = 'Open threat feeds reported no malicious hits for this target.';
		} elseif ( ( $status['threat']['state'] ?? '' ) === 'error' ) {
			$parts[] = 'Threat feed queries failed — reputation is unknown, not verified clean.';
			$recs[]  = 'Configure outbound HTTPS and API keys, then re-run the scan.';
		}

		if ( ! empty( $report['forensics']['phishing_reasons'] ) ) {
			$parts[] = 'Forensics: ' . implode( '; ', array_slice( $report['forensics']['phishing_reasons'], 0, 2 ) );
		}

		if ( ! empty( $sources['url_forensics']['redirect_count'] ) && (int) $sources['url_forensics']['redirect_count'] > 0 ) {
			$parts[] = 'HTTP redirect chain: ' . (int) $sources['url_forensics']['redirect_count'] . ' hop(s) analyzed.';
		}

		if ( ( $status['rdap']['state'] ?? '' ) === 'error' ) {
			$recs[] = 'WHOIS/RDAP lookup failed; registration ownership could not be verified.';
		}
		if ( ( $status['dns']['state'] ?? '' ) === 'error' ) {
			$recs[] = 'DNS resolution failed from this server; verify resolver connectivity.';
		}

		if ( ! empty( $report['anomalies'] ) ) {
			$recs[] = 'Investigate detected anomalies against baseline scan history.';
		}

		if ( 'insufficient_data' === $verdict ) {
			$recs[] = 'Do not treat this target as safe until more intelligence sources return data.';
		} elseif ( empty( $recs ) && in_array( $verdict, [ 'clean', 'low' ], true ) ) {
			$recs[] = 'Continue routine monitoring; no immediate containment action indicated.';
		} elseif ( empty( $recs ) && $score >= 50 ) {
			$recs[] = 'Conduct deeper investigation before trusting this target in production.';
		}

		return [
			'summary'         => implode( ' ', $parts ),
			'recommendations' => array_values( array_unique( $recs ) ),
		];
	}

	/* ── Anomaly Detection ──────────────────────────────── */

	/**
	 * Compare current scan against historical scans for the same target.
	 * Returns anomalies if significant changes are detected.
	 */
	public static function detect_anomalies( string $target, array $current_risk ): array {
		$history_key = 'pdx_scan_history_' . md5( $target );
		$history     = get_option( $history_key, [] );
		$anomalies   = [];

		if ( ! empty( $history ) ) {
			$last = end( $history );
			$delta = $current_risk['score'] - ( $last['score'] ?? 0 );

			if ( abs( $delta ) >= 20 ) {
				$anomalies[] = [
					'type'    => 'risk_score_change',
					'message' => sprintf( 'Risk score changed by %+d points since last scan', $delta ),
					'delta'   => $delta,
					'prev'    => $last['score'] ?? 0,
					'current' => $current_risk['score'],
				];
			}

			if ( ( $last['verdict'] ?? '' ) !== $current_risk['verdict'] ) {
				$anomalies[] = [
					'type'    => 'verdict_change',
					'message' => sprintf( 'Verdict changed from %s to %s', $last['verdict'] ?? 'unknown', $current_risk['verdict'] ),
					'prev'    => $last['verdict'] ?? 'unknown',
					'current' => $current_risk['verdict'],
				];
			}
		}

		// Store current scan in history (keep last 30)
		$history[] = [
			'ts'      => time(),
			'score'   => $current_risk['score'],
			'verdict' => $current_risk['verdict'],
		];
		$history = array_slice( $history, -30 );
		update_option( $history_key, $history );

		return $anomalies;
	}

	/* ── Behavioral Scoring ─────────────────────────────── */

	/**
	 * Score a target's behavioral profile based on aggregated signals.
	 */
	public static function behavioral_score( array $report ): array {
		$signals = [];

		// Privacy-preserving registrant
		$registrant = $report['sources']['rdap']['registrant'] ?? '';
		if ( stripos( $registrant, 'redact' ) !== false || stripos( $registrant, 'privacy' ) !== false ) {
			$signals[] = [ 'signal' => 'Privacy-protected registrant', 'type' => 'neutral' ];
		}

		// Hosting provider
		if ( ! empty( $report['sources']['geolocation']['hosting'] ) ) {
			$signals[] = [ 'signal' => 'Hosted on cloud/VPS infrastructure', 'type' => 'neutral' ];
		}

		// Many open ports
		$ports = count( $report['sources']['shodan']['ports'] ?? [] );
		if ( $ports > 10 ) {
			$signals[] = [ 'signal' => "Large attack surface ({$ports} open ports)", 'type' => 'negative' ];
		}

		// Email exposure
		$emails = count( $report['sources']['hunter']['emails'] ?? [] );
		if ( $emails > 5 ) {
			$signals[] = [ 'signal' => "High email exposure ({$emails} addresses found)", 'type' => 'negative' ];
		}

		// Old domain = positive signal
		$age = $report['sources']['rdap']['age_days'] ?? 0;
		if ( $age > 1825 ) { // 5+ years
			$signals[] = [ 'signal' => 'Established domain (5+ years)', 'type' => 'positive' ];
		}

		// Good SSL
		if ( in_array( $report['sources']['ssl']['grade'] ?? '', [ 'A+', 'A' ], true ) ) {
			$signals[] = [ 'signal' => 'Strong SSL/TLS configuration', 'type' => 'positive' ];
		}

		$forensics = $report['forensics'] ?? [];
		if ( (int) ( $forensics['phishing_score'] ?? 0 ) >= 45 ) {
			$signals[] = [ 'signal' => 'Elevated phishing heuristics on landing page', 'type' => 'negative' ];
		}
		if ( ! empty( $forensics['malware_indicators'] ) ) {
			$signals[] = [
				'signal' => 'Malware-style indicators: ' . implode( ', ', array_slice( (array) $forensics['malware_indicators'], 0, 3 ) ),
				'type'   => 'negative',
			];
		}
		if ( in_array( $forensics['redirect_intent'] ?? '', [ 'multi_hop_laundering', 'cross_domain_delivery' ], true ) ) {
			$signals[] = [ 'signal' => 'Suspicious redirect intent (' . $forensics['redirect_intent'] . ')', 'type' => 'negative' ];
		}
		if ( ! empty( $forensics['external_form_action'] ) ) {
			$signals[] = [ 'signal' => 'Credential form posts to external host', 'type' => 'negative' ];
		}

		return $signals;
	}

	/* ── CVE Lookup ─────────────────────────────────────── */

	/**
	 * Look up CVEs by ID or keyword.
	 * Primary: NVD API v2.0. Fallback: CIRCL CVE API (no key required).
	 *
	 * @param string $query CVE ID (e.g. "CVE-2021-44228") or keyword.
	 * @return array { cves: array, source: string, total: int, error?: string }
	 */
	public function fetch_cve( string $query ): array {
		$query = trim( $query );
		if ( ! $query ) {
			return [ 'cves' => [], 'total' => 0, 'source' => 'none', 'error' => 'No query provided.' ];
		}

		$is_cve_id = (bool) preg_match( '/^CVE-\d{4}-\d{4,}$/i', $query );

		// ── NVD API v2.0 ──────────────────────────────────
		try {
			$nvd_key     = (string) ( $this->settings->get( 'api_keys.nvd', '' ) ?? '' );
			$nvd_headers = [ 'Accept' => 'application/json' ];
			if ( $nvd_key !== '' ) {
				$nvd_headers['apiKey'] = $nvd_key;
			}

			$nvd_url = $is_cve_id
				? 'https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=' . rawurlencode( strtoupper( $query ) )
				: 'https://services.nvd.nist.gov/rest/json/cves/2.0?keywordSearch=' . rawurlencode( $query ) . '&resultsPerPage=10';

			$nvd_resp = wp_remote_get( $nvd_url, [ 'timeout' => 15, 'headers' => $nvd_headers ] );
			$nvd_code = is_wp_error( $nvd_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $nvd_resp );

			if ( $nvd_code === 200 ) {
				$body  = json_decode( wp_remote_retrieve_body( $nvd_resp ), true );
				$items = is_array( $body['vulnerabilities'] ?? null ) ? $body['vulnerabilities'] : [];
				if ( ! empty( $items ) ) {
					$cves = [];
					foreach ( $items as $item ) {
						if ( is_array( $item ) ) {
							$cves[] = $this->parse_nvd_cve( $item );
						}
					}
					return [
						'cves'   => $cves,
						'total'  => (int) ( $body['totalResults'] ?? count( $cves ) ),
						'source' => 'NVD',
					];
				}
			}

			// Log NVD failures for debugging without crashing.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$err = is_wp_error( $nvd_resp ) ? $nvd_resp->get_error_message() : "HTTP {$nvd_code}";
				error_log( "[PDX] NVD API failed for '{$query}': {$err}" );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] fetch_cve NVD block exception: ' . $e->getMessage() );
			}
		}

		// ── CIRCL CVE API fallback (no key required) ──────
		try {
			if ( $is_cve_id ) {
				$circl_url  = 'https://cve.circl.lu/api/cve/' . rawurlencode( strtoupper( $query ) );
				$circl_resp = wp_remote_get( $circl_url, [ 'timeout' => 10 ] );
				$circl_code = is_wp_error( $circl_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $circl_resp );

				if ( $circl_code === 200 ) {
					$cve = json_decode( wp_remote_retrieve_body( $circl_resp ), true );
					// CIRCL returns the CVE object directly; must be an array with an id field.
					if ( is_array( $cve ) && ! empty( $cve ) && ( isset( $cve['id'] ) || isset( $cve['cveMetadata']['cveId'] ) ) ) {
						return [
							'cves'   => [ $this->parse_circl_cve( $cve ) ],
							'total'  => 1,
							'source' => 'CIRCL',
						];
					}
				}

				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					$err = is_wp_error( $circl_resp ) ? $circl_resp->get_error_message() : "HTTP {$circl_code}";
					error_log( "[PDX] CIRCL API failed for '{$query}': {$err}" );
				}
			} else {
				$circl_url  = 'https://cve.circl.lu/api/search/' . rawurlencode( $query );
				$circl_resp = wp_remote_get( $circl_url, [ 'timeout' => 10 ] );
				$circl_code = is_wp_error( $circl_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $circl_resp );

				if ( $circl_code === 200 ) {
					$data  = json_decode( wp_remote_retrieve_body( $circl_resp ), true );
					$items = [];
					if ( is_array( $data ) ) {
						$items = isset( $data['results'] ) && is_array( $data['results'] )
							? $data['results']
							: array_values( $data );
					}
					if ( ! empty( $items ) ) {
						$cves = [];
						foreach ( array_slice( $items, 0, 10 ) as $item ) {
							if ( is_array( $item ) ) {
								$cves[] = $this->parse_circl_cve( $item );
							}
						}
						if ( ! empty( $cves ) ) {
							return [ 'cves' => $cves, 'total' => count( $items ), 'source' => 'CIRCL' ];
						}
					}
				}
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] fetch_cve CIRCL block exception: ' . $e->getMessage() );
			}
		}

		$rate_note = ( (string) ( $this->settings->get( 'api_keys.nvd', '' ) ?? '' ) === '' )
			? ' Without an NVD API key, requests are limited to 5 per 30 seconds.'
			: '';

		return [
			'cves'   => [],
			'total'  => 0,
			'source' => 'none',
			'error'  => 'No CVE data found for "' . esc_html( $query ) . '".' . $rate_note . ' Check WP debug log for details.',
		];
	}

	/** Normalise a single NVD v2.0 vulnerability entry. */
	private function parse_nvd_cve( array $item ): array {
		$cve  = $item['cve'] ?? $item;
		$id   = $cve['id'] ?? ( $cve['CVE_data_meta']['ID'] ?? 'Unknown' );
		$desc = '';
		foreach ( $cve['descriptions'] ?? [] as $d ) {
			if ( ( $d['lang'] ?? '' ) === 'en' ) { $desc = $d['value']; break; }
		}

		// CVSS v3.1 preferred, fall back to v3.0 then v2.
		$cvss_score    = null;
		$cvss_severity = null;
		$cvss_vector   = null;
		$metrics = $cve['metrics'] ?? [];
		foreach ( [ 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2' ] as $key ) {
			if ( ! empty( $metrics[ $key ][0] ) ) {
				$m             = $metrics[ $key ][0]['cvssData'] ?? $metrics[ $key ][0];
				$cvss_score    = $m['baseScore']    ?? null;
				$cvss_severity = $m['baseSeverity'] ?? ( $m['severity'] ?? null );
				$cvss_vector   = $m['vectorString'] ?? null;
				break;
			}
		}

		$refs = [];
		foreach ( $cve['references'] ?? [] as $r ) {
			$refs[] = $r['url'] ?? '';
		}

		$published = $cve['published']        ?? null;
		$modified  = $cve['lastModified']     ?? null;
		$status    = $cve['vulnStatus']       ?? null;
		$cwes      = [];
		foreach ( $cve['weaknesses'] ?? [] as $w ) {
			foreach ( $w['description'] ?? [] as $wd ) {
				if ( ! empty( $wd['value'] ) && $wd['value'] !== 'NVD-CWE-Other' ) {
					$cwes[] = $wd['value'];
				}
			}
		}

		return [
			'id'          => $id,
			'description' => $desc,
			'cvss_score'  => $cvss_score,
			'severity'    => $cvss_severity ? strtoupper( $cvss_severity ) : null,
			'vector'      => $cvss_vector,
			'cwes'        => array_unique( $cwes ),
			'published'   => $published,
			'modified'    => $modified,
			'status'      => $status,
			'references'  => array_slice( $refs, 0, 5 ),
		];
	}

	/** Normalise a CIRCL CVE API entry. */
	private function parse_circl_cve( array $cve ): array {
		$score = null;
		if ( isset( $cve['cvss3'] ) ) {
			$score = (float) $cve['cvss3'];
		} elseif ( isset( $cve['cvss'] ) ) {
			$score = (float) $cve['cvss'];
		}

		$severity = null;
		if ( $score !== null ) {
			if ( $score >= 9.0 )      $severity = 'CRITICAL';
			elseif ( $score >= 7.0 )  $severity = 'HIGH';
			elseif ( $score >= 4.0 )  $severity = 'MEDIUM';
			else                      $severity = 'LOW';
		}

		$refs = [];
		foreach ( $cve['references'] ?? [] as $r ) {
			$refs[] = is_array( $r ) ? ( $r['url'] ?? '' ) : $r;
		}

		return [
			'id'          => $cve['id'] ?? ( $cve['cveMetadata']['cveId'] ?? 'Unknown' ),
			'description' => $cve['summary'] ?? ( $cve['description'] ?? '' ),
			'cvss_score'  => $score,
			'severity'    => $severity,
			'vector'      => $cve['cvss-vector'] ?? null,
			'cwes'        => (array) ( $cve['cwe'] ?? [] ),
			'published'   => $cve['Published'] ?? ( $cve['published'] ?? null ),
			'modified'    => $cve['Modified']  ?? ( $cve['modified']  ?? null ),
			'status'      => null,
			'references'  => array_slice( $refs, 0, 5 ),
		];
	}

	/* ── Attack Surface ─────────────────────────────────── */

	/**
	 * Build an attack surface report for a domain or IP.
	 * Aggregates open ports, services, DNS records, and known CVEs from Shodan.
	 *
	 * @param string $target Domain or IP address.
	 * @return array
	 */
	public function fetch_attack_surface( string $raw_target ): array {
		$resolved = PDX_Target::resolve( trim( $raw_target ) );
		if ( is_wp_error( $resolved ) ) {
			return [
				'target'     => $raw_target,
				'target_raw' => $raw_target,
				'ports'      => [],
				'vulns'      => [],
				'dns'        => [],
				'score'      => 0,
				'summary'    => $resolved->get_error_message(),
				'source'     => [],
				'error'      => $resolved->get_error_message(),
			];
		}

		$target = PDX_Target::api_host( $resolved );
		$result = [
			'target'     => $target,
			'target_raw' => $resolved['raw'],
			'ports'    => [],
			'services' => [],
			'vulns'    => [],
			'dns'      => [],
			'os'       => null,
			'org'      => null,
			'country'  => null,
			'score'    => 0,
			'summary'  => '',
			'source'   => [],
		];

		// ── Shodan host data ──────────────────────────────
		try {
			$shodan = $this->fetch_shodan( $target );
			if ( is_array( $shodan ) ) {
				$result['ports']    = is_array( $shodan['ports'] ?? null )  ? $shodan['ports']  : [];
				$result['vulns']    = is_array( $shodan['vulns'] ?? null )  ? $shodan['vulns']  : [];
				$result['os']       = $shodan['os']      ?? null;
				$result['org']      = $shodan['org']     ?? null;
				$result['country']  = $shodan['country'] ?? null;
				$result['source'][] = 'Shodan';
				foreach ( $result['ports'] as $port ) {
					if ( is_numeric( $port ) ) {
						$result['services'][] = [ 'port' => (int) $port, 'service' => $this->port_label( (int) $port ) ];
					}
				}
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] fetch_attack_surface Shodan block exception: ' . $e->getMessage() );
			}
		}

		// ── DNS records via Google DoH ────────────────────
		try {
			$dns_types   = [ 'A', 'MX', 'TXT', 'NS', 'AAAA' ];
			$dns_records = [];
			foreach ( $dns_types as $type ) {
				$dns_http = PDX_Http::get(
					'https://dns.google/resolve?name=' . rawurlencode( $target ) . '&type=' . $type,
					[ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/dns-json' ] ],
					'surface_dns'
				);
				$dns_resp = $dns_http['response'];
				if ( ! is_wp_error( $dns_resp ) && (int) wp_remote_retrieve_response_code( $dns_resp ) === 200 ) {
					$dns_data = json_decode( wp_remote_retrieve_body( $dns_resp ), true );
					if ( is_array( $dns_data['Answer'] ?? null ) ) {
						foreach ( $dns_data['Answer'] as $rec ) {
							if ( is_array( $rec ) ) {
								$dns_records[] = [ 'type' => $type, 'value' => (string) ( $rec['data'] ?? '' ) ];
							}
						}
					}
					$result['source'][] = 'DNS';
				}
			}
			$result['dns']    = $dns_records;
			$result['source'] = array_values( array_unique( $result['source'] ) );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] fetch_attack_surface DNS block exception: ' . $e->getMessage() );
			}
		}

		// ── Risk score ────────────────────────────────────
		$port_count = count( $result['ports'] );
		$vuln_count = count( $result['vulns'] );
		$score      = 0;
		if ( $port_count > 20 )     $score += 30;
		elseif ( $port_count > 10 ) $score += 20;
		elseif ( $port_count > 5 )  $score += 10;
		$score += min( 50, $vuln_count * 10 );
		$risky = array_intersect( $result['ports'], [ 21, 23, 445, 3389, 5900, 6379, 27017 ] );
		$score += count( $risky ) * 5;
		$result['score'] = min( 100, $score );

		// ── Plain-language summary ────────────────────────
		$parts = [];
		if ( $port_count )   $parts[] = "{$port_count} open port" . ( $port_count !== 1 ? 's' : '' );
		if ( $vuln_count )   $parts[] = "{$vuln_count} known CVE" . ( $vuln_count !== 1 ? 's' : '' );
		if ( $result['os'] ) $parts[] = 'OS: ' . $result['os'];
		$result['summary'] = $parts
			? 'Attack surface analysis complete. Found: ' . implode( ', ', $parts ) . '.'
			: 'No significant attack surface data found for this target.';

		return $result;
	}

	/** Map common port numbers to service names. */
	private function port_label( int $port ): string {
		$map = [
			21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP',
			53 => 'DNS', 80 => 'HTTP', 110 => 'POP3', 143 => 'IMAP',
			443 => 'HTTPS', 445 => 'SMB', 3306 => 'MySQL', 3389 => 'RDP',
			5432 => 'PostgreSQL', 5900 => 'VNC', 6379 => 'Redis',
			8080 => 'HTTP-Alt', 8443 => 'HTTPS-Alt', 27017 => 'MongoDB',
		];
		return $map[ $port ] ?? "Port {$port}";
	}
}
