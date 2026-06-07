<?php
/**
 * PDX_Intelligence — multi-source threat intelligence aggregation engine.
 *
 * Handles: RDAP, SSL Labs, VirusTotal, Shodan, Hunter.io, ip-api,
 *          behavioral scoring, anomaly detection, risk matrix.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Intelligence {

	/** @var array|WP_Error|null Last raw paid-API response for status messaging. */
	private array|WP_Error|null $last_paid_api_response = null;

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

		$target      = PDX_Target::scan_host( $resolved );
		$target_type = (string) $resolved['type'];
		$report = [
			'target'         => 'email' === $target_type ? $resolved['normalized'] : $target,
			'target_raw'     => $resolved['raw'],
			'target_type'    => $target_type,
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

		$resolved_ip = null;

		switch ( $target_type ) {
			case 'ip':
				$resolved_ip = $target;
				$ip_rdap     = $this->fetch_rdap_ip( $target );
				$report['source_status']['ip_network'] = $ip_rdap['status'];
				if ( ! empty( $ip_rdap['data'] ) ) {
					$report['sources']['ip_network'] = $this->parse_rdap_ip( $ip_rdap['data'], $target );
				}
				$report['source_status']['rdap'] = $this->skipped_status( 'Domain WHOIS does not apply to IP addresses — see IP Network Registration.' );

				$report['source_status']['ssl'] = $this->skipped_status( 'SSL/TLS certificate analysis applies to domains and URLs, not raw IP addresses.' );

				$reverse = $this->fetch_reverse_dns( $target );
				$report['source_status']['reverse_dns'] = $reverse['status'];
				if ( ! empty( $reverse['data'] ) ) {
					$report['sources']['reverse_dns'] = $reverse['data'];
				}
				$report['source_status']['dns'] = $this->skipped_status( 'Forward DNS record lookup is not applicable to IP targets — see Reverse DNS.' );
				break;

			case 'hash':
				$report['target'] = $resolved['normalized'];
				$report['source_status']['rdap'] = $this->skipped_status( 'RDAP/WHOIS does not apply to file hashes.' );
				$report['source_status']['dns']  = $this->skipped_status( 'DNS lookups do not apply to file hashes.' );
				$report['source_status']['ssl']  = $this->skipped_status( 'SSL/TLS analysis does not apply to file hashes.' );
				$report['source_status']['geo']  = $this->skipped_status( 'Geolocation does not apply to file hashes.' );
				break;

			case 'email':
				$report['target'] = $resolved['normalized'];
				$domain           = $target;
				$rdap             = $this->fetch_rdap_resolved( $domain );
				$report['source_status']['rdap'] = $rdap['status'];
				if ( ! empty( $rdap['data'] ) ) {
					$report['sources']['rdap'] = $this->parse_rdap( $rdap['data'] );
				}

				$dns = $this->fetch_dns( $domain );
				$report['source_status']['dns'] = $dns['status'];
				if ( ! empty( $dns['data'] ) ) {
					$report['sources']['dns'] = $dns['data'];
					$report['sources']['email_auth'] = [
						'label'         => 'Email Authentication',
						'address'       => $resolved['normalized'],
						'domain'        => $domain,
						'mx_configured' => ! empty( $dns['data']['mx'] ),
						'spf_configured'=> ! empty( $dns['data']['spf'] ),
						'dmarc_configured' => ! empty( $dns['data']['dmarc'] ),
					];
				}

				$ssl = $this->fetch_ssl_polled( $domain );
				$report['source_status']['ssl'] = $ssl['status'];
				if ( ! empty( $ssl['data'] ) ) {
					$report['sources']['ssl'] = $this->parse_ssl( $ssl['data'] );
				}

				$resolved_ip = $this->resolve_host_ip( $domain );
				break;

			default:
				// domain, url, and hostname-like targets.
				$domain_host = $target;
				$rdap        = $this->fetch_rdap_resolved( $domain_host );
				$report['source_status']['rdap'] = $rdap['status'];
				if ( ! empty( $rdap['data'] ) ) {
					$report['sources']['rdap'] = $this->parse_rdap( $rdap['data'] );
					if ( ! empty( $rdap['queried'] ) && $rdap['queried'] !== $domain_host ) {
						$report['sources']['rdap']['note'] = sprintf(
							'Registration data for parent zone %s (subdomain RDAP unavailable).',
							$rdap['queried']
						);
					}
					$report['timeline'] = array_merge( $report['timeline'], $this->extract_timeline( $rdap['data'] ) );
				}

				$dns = $this->fetch_dns( $domain_host );
				$report['source_status']['dns'] = $dns['status'];
				if ( ! empty( $dns['data'] ) ) {
					$report['sources']['dns'] = $dns['data'];
				}

				$ssl = $this->fetch_ssl_polled( $domain_host );
				$report['source_status']['ssl'] = $ssl['status'];
				if ( ! empty( $ssl['data'] ) ) {
					$report['sources']['ssl'] = $this->parse_ssl( $ssl['data'] );
					if ( ! empty( $ssl['message'] ) ) {
						$report['sources']['ssl']['note'] = $ssl['message'];
					}
				}

				$resolved_ip = $this->resolve_host_ip( $domain_host );
				break;
		}

		if ( null === $resolved_ip && 'ip' !== $target_type && 'hash' !== $target_type ) {
			$resolved_ip = $this->resolve_host_ip( $target );
		}

		if ( 'hash' !== $target_type ) {
			if ( $resolved_ip ) {
				$geo_result = $this->fetch_geo_with_status( $resolved_ip );
				if ( ! empty( $geo_result['data'] ) ) {
					$report['sources']['geolocation'] = $geo_result['data'];
					$report['sources']['geo']         = $geo_result['data'];
					$report['source_status']['geo']   = $geo_result['status'];
				} else {
					$report['source_status']['geo'] = $geo_result['status'] ?? [
						'state'   => 'error',
						'message' => 'Geolocation lookup failed for ' . $resolved_ip,
					];
				}
			} elseif ( 'ip' !== $target_type ) {
				$report['source_status']['geo'] = [ 'state' => 'error', 'message' => 'Could not resolve host to an IP address.' ];
			}
		}

		// Threat intelligence — type-appropriate OTX / URLhaus / VT endpoints.
		$threat = $this->fetch_threat_intel( $target, $paid, $target_type, $resolved );
		$report['source_status']['threat'] = $threat['status'];
		if ( ! empty( $threat['data'] ) ) {
			$report['sources']['threat']     = $threat['data'];
			$report['sources']['virustotal'] = $threat['data']['virustotal'] ?? null;
			if ( ! empty( $threat['data']['virustotal'] ) ) {
				$report['indicators'] = array_merge( $report['indicators'], $this->extract_iocs( $threat['data']['virustotal'] ) );
			}
		}

		$abuse_ip = 'ip' === $target_type ? $target : $resolved_ip;
		if ( $abuse_ip && filter_var( $abuse_ip, FILTER_VALIDATE_IP ) ) {
			$abuse = $this->fetch_abuseipdb( $abuse_ip );
			$report['source_status']['abuseipdb'] = $abuse['status'];
			if ( ! empty( $abuse['data'] ) ) {
				$report['sources']['abuseipdb'] = $abuse['data'];
				if ( ! empty( $report['sources']['threat'] ) ) {
					$conf = (int) ( $abuse['data']['abuse_confidence'] ?? 0 );
					if ( $conf >= 25 ) {
						$report['sources']['threat']['suspicious'] = max(
							(int) ( $report['sources']['threat']['suspicious'] ?? 0 ),
							min( 5, (int) ceil( $conf / 20 ) )
						);
						$report['sources']['threat']['feeds'] = array_values(
							array_unique(
								array_merge(
									(array) ( $report['sources']['threat']['feeds'] ?? [] ),
									[ 'AbuseIPDB (' . $conf . '% confidence)' ]
								)
							)
						);
					}
				}
			}
		} elseif ( in_array( $target_type, [ 'hash' ], true ) ) {
			$report['source_status']['abuseipdb'] = $this->skipped_status( 'AbuseIPDB does not apply to file hashes.' );
		} elseif ( 'ip' !== $target_type && ! $resolved_ip ) {
			$report['source_status']['abuseipdb'] = $this->skipped_status( 'AbuseIPDB requires a resolved IP address.' );
		} else {
			$report['source_status']['abuseipdb'] = $this->skipped_status( 'AbuseIPDB applies to IP addresses only.' );
		}

		if ( $paid ) {
			$vt_key = (string) $this->settings->get( 'api_keys.virustotal', '' );
			$vt     = $this->fetch_virustotal( $target, $target_type, $resolved );
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
				$report['source_status']['threat']     = [ 'state' => 'ok', 'message' => 'VirusTotal + open feeds' ];
				$report['source_status']['virustotal'] = [ 'state' => 'ok', 'message' => 'VirusTotal data retrieved' ];
				$report['indicators']                  = array_merge( $report['indicators'], $this->extract_iocs( $vt ) );
			} else {
				$report['source_status']['virustotal'] = $this->paid_api_status(
					'VirusTotal',
					$this->last_paid_api_response,
					'' !== $vt_key
				);
			}

			if ( in_array( $target_type, [ 'ip', 'domain', 'url', 'email' ], true ) ) {
				$shodan_key = (string) $this->settings->get( 'api_keys.shodan', '' );
				$shodan_ip  = $resolved_ip ?: ( 'ip' === $target_type ? $target : null );
				if ( $shodan_ip ) {
					$shodan_out = $this->fetch_shodan_with_status( $shodan_ip );
					if ( ! empty( $shodan_out['data'] ) ) {
						$report['sources']['shodan']       = $shodan_out['data'];
						$report['source_status']['shodan'] = $shodan_out['status'];
					} else {
						$report['source_status']['shodan'] = $shodan_out['status'];
					}
				} else {
					$report['source_status']['shodan'] = $this->skipped_status( 'Could not resolve target to an IP for Shodan lookup.' );
				}
			} else {
				$report['source_status']['shodan'] = $this->skipped_status( 'Shodan enrichment does not apply to this target type.' );
			}

			if ( in_array( $target_type, [ 'domain', 'url', 'email' ], true ) ) {
				$hunter_key = (string) $this->settings->get( 'api_keys.hunter', '' );
				$hunter_dom = 'email' === $target_type ? $target : $target;
				$hunter     = $this->fetch_hunter( $hunter_dom );
				if ( $hunter ) {
					$report['sources']['hunter']       = $hunter;
					$report['source_status']['hunter'] = [ 'state' => 'ok', 'message' => 'Email discovery complete' ];
				} else {
					$report['source_status']['hunter'] = $this->paid_api_status(
						'Hunter.io',
						$this->last_paid_api_response,
						'' !== $hunter_key
					);
				}
			} else {
				$report['source_status']['hunter'] = $this->skipped_status( 'Hunter.io domain search does not apply to this target type.' );
			}
		}

		$report['risk']           = $this->compute_risk( $report['sources'], $report['source_status'], [], $target_type );
		$report['report_quality'] = $this->assess_report_quality( $report['source_status'], $target_type, $report['risk'] );
		$report['confidence']     = $this->compute_confidence(
			$report['source_status'],
			$target_type,
			(string) ( $report['report_quality']['coverage_tier'] ?? 'verified' )
		);
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
			'report_quality'  => [
				'reliable'        => false,
				'failed_sources'  => [ 'normalize' ],
				'skipped_sources' => [],
				'partial_sources' => [],
				'message'         => 'Target could not be normalized — no intelligence was collected.',
			],
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

	/**
	 * @param array|WP_Error|null $response
	 * @return array{state:string,message:string}
	 */
	private function paid_api_status( string $label, $response, bool $has_key ): array {
		if ( ! $has_key ) {
			return [ 'state' => 'skipped', 'message' => $label . ' API key not configured.' ];
		}
		if ( is_wp_error( $response ) ) {
			return [ 'state' => 'partial', 'message' => $label . ' temporarily unavailable.' ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return [ 'state' => 'partial', 'message' => $label . ' API key rejected or unauthorized.' ];
		}
		if ( 404 === $code ) {
			return [ 'state' => 'skipped', 'message' => $label . ' has no data for this target.' ];
		}
		if ( 429 === $code ) {
			return [ 'state' => 'partial', 'message' => $label . ' rate limit reached. Try again later.' ];
		}
		if ( 200 !== $code ) {
			return [ 'state' => 'partial', 'message' => $label . ' ' . PDX_Http::http_error_message( $code ) . '.' ];
		}
		return [ 'state' => 'skipped', 'message' => $label . ' returned no usable data for this target.' ];
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
		$tried     = $this->rdap_lookup_candidates( $domain );
		$last_err  = 'RDAP lookup failed.';
		$last_log  = [];
		$last_code = 0;

		foreach ( $tried as $candidate ) {
			foreach ( $this->rdap_endpoint_urls( $candidate ) as $url ) {
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
					$last_err  = $resp->get_error_message();
					$last_log  = $http['log'];
					$last_code = 0;
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $resp );
				if ( 200 !== $code ) {
					$last_err  = "RDAP HTTP {$code} for {$candidate}" . ( str_contains( $url, 'rdap.org' ) ? '' : ' via registry endpoint' );
					$last_log  = $http['log'];
					$last_code = $code;
					continue;
				}

				$body = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( ! is_array( $body ) || ! $this->is_valid_rdap_domain_response( $body, $candidate ) ) {
					$last_err                 = 'Invalid RDAP response (parse failed).';
					$last_log                 = $http['log'];
					$last_log['parse_status'] = 'parse_error';
					$last_code                = 200;
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
		}

		if ( $this->rdap_registry_unlisted( $domain ) ) {
			$tld = $this->domain_tld( $domain );
			return [
				'data'    => null,
				'queried' => $domain,
				'status'  => $this->with_http_log(
					[
						'state'   => 'skipped',
						'message' => ".{$tld} is not listed in the IANA RDAP bootstrap — registration data unavailable via RDAP (WHOIS web lookup may still exist).",
					],
					$last_log
				),
			];
		}

		if ( 404 === $last_code ) {
			return [
				'data'    => null,
				'queried' => $domain,
				'status'  => $this->with_http_log(
					[
						'state'   => 'skipped',
						'message' => 'No RDAP record published for this domain — registration age and ownership could not be verified.',
					],
					$last_log
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
	private function rdap_endpoint_urls( string $domain ): array {
		$encoded = rawurlencode( $domain );
		$urls    = [ 'https://rdap.org/domain/' . $encoded ];
		$tld     = $this->domain_tld( $domain );

		$registry_bases = [
			'at'    => 'https://rdap.nic.at/domain/',
			'co.at' => 'https://rdap.nic.at/domain/',
			'or.at' => 'https://rdap.nic.at/domain/',
			'de'    => 'https://rdap.denic.de/domain/',
			'fr'    => 'https://rdap.nic.fr/domain/',
			'uk'    => 'https://rdap.nominet.uk/uk/domain/',
		];

		if ( isset( $registry_bases[ $tld ] ) ) {
			$urls[] = $registry_bases[ $tld ] . $encoded;
		}

		return array_values( array_unique( $urls ) );
	}

	private function domain_tld( string $domain ): string {
		$domain = strtolower( trim( $domain, '.' ) );
		foreach ( [ 'co.at', 'or.at' ] as $special ) {
			if ( $domain === $special || str_ends_with( $domain, '.' . $special ) ) {
				return $special;
			}
		}
		$parts = explode( '.', $domain );
		return (string) ( end( $parts ) ?: '' );
	}

	/**
	 * TLDs known to be absent from the IANA RDAP bootstrap (rdap.org returns 404).
	 */
	private function rdap_registry_unlisted( string $domain ): bool {
		$tld = $this->domain_tld( $domain );
		return in_array( $tld, [ 'at', 'co.at', 'or.at' ], true );
	}

	private function is_valid_rdap_domain_response( array $body, string $candidate ): bool {
		if ( ! empty( $body['ldhName'] ) ) {
			return true;
		}
		$unicode = strtolower( (string) ( $body['unicodeName'] ?? '' ) );
		if ( $unicode && $unicode === strtolower( $candidate ) ) {
			return true;
		}
		if ( ! empty( $body['handle'] ) && ! empty( $body['entities'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	/**
	 * @return array{state:string,message:string}
	 */
	private function skipped_status( string $message ): array {
		return [ 'state' => 'skipped', 'message' => $message ];
	}

	/**
	 * RDAP IP lookup (IPv4 / IPv6).
	 *
	 * @return array{data:?array,status:array,queried:string}
	 */
	public function fetch_rdap_ip( string $ip ): array {
		$url  = 'https://rdap.org/ip/' . rawurlencode( $ip );
		$http = PDX_Http::get(
			$url,
			[
				'timeout' => 15,
				'headers' => [ 'Accept' => 'application/rdap+json, application/json' ],
			],
			'rdap_ip'
		);
		$resp = $http['response'];

		if ( is_wp_error( $resp ) ) {
			return [
				'data'    => null,
				'queried' => $ip,
				'status'  => $this->with_http_log(
					[ 'state' => 'error', 'message' => $resp->get_error_message() ],
					$http['log']
				),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return [
				'data'    => null,
				'queried' => $ip,
				'status'  => $this->with_http_log(
					[ 'state' => 'error', 'message' => "RDAP IP HTTP {$code}." ],
					$http['log']
				),
			];
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			return [
				'data'    => null,
				'queried' => $ip,
				'status'  => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'Invalid RDAP IP response.' ],
					$http['log']
				),
			];
		}

		$http['log']['parse_status'] = 'ok';

		return [
			'data'    => $body,
			'queried' => $ip,
			'status'  => $this->with_http_log(
				[ 'state' => 'ok', 'message' => 'IP registration data retrieved.' ],
				$http['log']
			),
		];
	}

	/**
	 * @param array<string, mixed> $rdap
	 * @return array<string, mixed>
	 */
	private function parse_rdap_ip( array $rdap, string $ip ): array {
		$org         = null;
		$cidr_block  = null;
		$cidr_entry  = $rdap['cidr0_cidrs'][0] ?? null;

		if ( is_array( $cidr_entry ) ) {
			$prefix = $cidr_entry['v4prefix'] ?? $cidr_entry['v6prefix'] ?? null;
			$length = $cidr_entry['length'] ?? null;
			if ( $prefix && null !== $length ) {
				$cidr_block = $prefix . '/' . $length;
			} elseif ( $prefix ) {
				$cidr_block = (string) $prefix;
			}
		}

		foreach ( $rdap['entities'] ?? [] as $ent ) {
			$roles = $ent['roles'] ?? [];
			if ( ! array_intersect( $roles, [ 'registrant', 'administrative', 'technical' ] ) ) {
				continue;
			}
			$vc = $ent['vcardArray'][1] ?? [];
			foreach ( $vc as $v ) {
				if ( 'fn' === ( $v[0] ?? '' ) && ! empty( $v[3] ) ) {
					$org = (string) $v[3];
					break 2;
				}
			}
		}

		return [
			'label'        => 'IP Network Registration',
			'type'         => 'ip_network',
			'ip'           => $ip,
			'handle'       => $rdap['handle'] ?? null,
			'name'         => $rdap['name'] ?? null,
			'cidr'         => $cidr_block,
			'organization' => $org,
			'country'      => $rdap['country'] ?? null,
			'registry'     => 'RDAP',
			'start_address'=> $rdap['startAddress'] ?? null,
			'end_address'  => $rdap['endAddress'] ?? null,
			'status'       => array_slice( $rdap['status'] ?? [], 0, 5 ),
		];
	}

	/**
	 * Reverse DNS (PTR) for an IP address.
	 *
	 * @return array{data:?array,status:array}
	 */
	public function fetch_reverse_dns( string $ip ): array {
		$ptr = $this->reverse_dns_query_name( $ip );
		if ( ! $ptr ) {
			return [
				'data'   => null,
				'status' => [ 'state' => 'error', 'message' => 'Could not build reverse DNS query for this IP.' ],
			];
		}

		$http = PDX_Http::get(
			'https://dns.google/resolve?name=' . rawurlencode( $ptr ) . '&type=PTR',
			[ 'timeout' => 10, 'headers' => [ 'Accept' => 'application/dns-json' ] ],
			'dns_ptr'
		);
		$resp = $http['response'];

		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return [
				'data'   => null,
				'status' => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'Reverse DNS lookup failed.' ],
					$http['log']
				),
			];
		}

		$data  = json_decode( wp_remote_retrieve_body( $resp ), true );
		$names = [];
		foreach ( $data['Answer'] ?? [] as $rec ) {
			$value = rtrim( (string) ( $rec['data'] ?? '' ), '.' );
			if ( '' !== $value ) {
				$names[] = $value;
			}
		}

		if ( empty( $names ) ) {
			return [
				'data'   => null,
				'status' => $this->with_http_log(
					[ 'state' => 'partial', 'message' => 'No PTR records found for this IP.' ],
					$http['log']
				),
			];
		}

		return [
			'data'   => [
				'label'   => 'Reverse DNS',
				'ptr'     => $names,
				'hostnames' => $names,
			],
			'status' => $this->with_http_log(
				[ 'state' => 'ok', 'message' => 'Reverse DNS records retrieved.' ],
				$http['log']
			),
		];
	}

	private function reverse_dns_query_name( string $ip ): ?string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets = array_reverse( explode( '.', $ip ) );
			return implode( '.', $octets ) . '.in-addr.arpa';
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return null;
			}
			$nibbles = str_split( bin2hex( $packed ) );
			return implode( '.', array_reverse( $nibbles ) ) . '.ip6.arpa';
		}

		return null;
	}

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
					$status_message = (string) ( $body['statusMessage'] ?? 'SSL Labs returned an error.' );
					$is_blacklisted = false !== stripos( $status_message, 'blacklist' );
					$state          = $is_blacklisted ? 'partial' : 'error';
					$message        = $is_blacklisted
						? $status_message . ' — SSL Labs blocks automated scans for this hostname. Real domain scans are unaffected; use a non-blacklisted target.'
						: $status_message;
					return [
						'data'    => $body,
						'status'  => $this->with_http_log(
							[ 'state' => $state, 'message' => $message ],
							$last_log
						),
						'message' => $status_message,
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
	 * Open-source threat feeds (OTX, URLhaus) with type-appropriate endpoints.
	 *
	 * @param array<string, mixed> $resolved
	 * @return array{data:?array,status:array}
	 */
	public function fetch_threat_intel( string $target, bool $paid, string $type = 'domain', array $resolved = [] ): array {
		$feeds      = [];
		$malicious  = 0;
		$suspicious = 0;
		$errors     = 0;
		$attempts   = 0;
		$last_log   = [];

		if ( 'hash' === $type ) {
			++$attempts;
			$otx_url  = 'https://otx.alienvault.com/api/v1/indicators/file/' . rawurlencode( $target ) . '/general';
			$otx_http = PDX_Http::get( $otx_url, [ 'timeout' => 12 ], 'otx_file' );
			$otx      = $otx_http['response'];
			$last_log = $otx_http['log'];

			if ( ! is_wp_error( $otx ) && 200 === (int) wp_remote_retrieve_response_code( $otx ) ) {
				$odata = json_decode( wp_remote_retrieve_body( $otx ), true );
				$pulse = (int) ( $odata['pulse_info']['count'] ?? 0 );
				if ( $pulse > 0 ) {
					$suspicious += min( 5, $pulse );
					$feeds[] = "OTX file ({$pulse} pulses)";
				} else {
					$feeds[] = 'OTX file (0 pulses)';
				}
			} else {
				++$errors;
			}

			return $this->finalize_threat_intel( $feeds, $malicious, $suspicious, $errors, $attempts, $last_log );
		}

		$otx_indicator = $target;
		$otx_path      = 'domain';
		if ( 'ip' === $type ) {
			$otx_path = filter_var( $target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 'IPv6' : 'IPv4';
		}

		++$attempts;
		$otx_url  = 'https://otx.alienvault.com/api/v1/indicators/' . $otx_path . '/' . rawurlencode( $otx_indicator ) . '/general';
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

		// URLhaus — URL lookup for URLs, host lookup otherwise.
		$abusech_headers = $this->settings->abusech_auth_headers();
		if ( empty( $abusech_headers ) ) {
			$feeds[] = 'URLhaus (abuse.ch Auth-Key not configured)';
		} else {
			++$attempts;
			if ( 'url' === $type ) {
				$fetch_url = $resolved['url'] ?? $resolved['raw'] ?? '';
				if ( ! preg_match( '#^https?://#i', (string) $fetch_url ) ) {
					$fetch_url = 'https://' . $target . ( $resolved['path'] ?? '' );
				}
				$urlhaus_http = PDX_Http::post(
					'https://urlhaus-api.abuse.ch/v1/url/',
					[
						'timeout' => 12,
						'headers' => $abusech_headers,
						'body'    => [ 'url' => $fetch_url ],
					],
					'urlhaus_url'
				);
			} else {
				$urlhaus_http = PDX_Http::post(
					'https://urlhaus-api.abuse.ch/v1/host/',
					[
						'timeout' => 12,
						'headers' => $abusech_headers,
						'body'    => [ 'host' => $target ],
					],
					'urlhaus'
				);
			}

			$urlhaus  = $urlhaus_http['response'];
			$last_log = $urlhaus_http['log'];

			if ( ! is_wp_error( $urlhaus ) && 200 === (int) wp_remote_retrieve_response_code( $urlhaus ) ) {
				$udata = json_decode( wp_remote_retrieve_body( $urlhaus ), true );
				$last_log['parse_status'] = is_array( $udata ) ? 'ok' : 'parse_error';
				if ( 'url' === $type && 'ok' === ( $udata['query_status'] ?? '' ) && ! empty( $udata['url_status'] ) ) {
					if ( in_array( $udata['url_status'], [ 'online', 'offline' ], true ) ) {
						++$malicious;
						$feeds[] = 'URLhaus (malicious URL)';
					} else {
						$feeds[] = 'URLhaus (not listed)';
					}
				} elseif ( 'ok' === ( $udata['query_status'] ?? '' ) && ! empty( $udata['url_count'] ) ) {
					$malicious += (int) $udata['url_count'];
					$feeds[]     = 'URLhaus (' . (int) $udata['url_count'] . ' URLs)';
				} else {
					$feeds[] = 'URLhaus (not listed)';
				}
			} else {
				++$errors;
				$code = is_wp_error( $urlhaus ) ? 0 : (int) wp_remote_retrieve_response_code( $urlhaus );
				if ( in_array( $code, [ 401, 403 ], true ) ) {
					$feeds[] = 'URLhaus (authentication failed — verify abuse.ch Auth-Key)';
				}
			}
		}

		return $this->finalize_threat_intel( $feeds, $malicious, $suspicious, $errors, $attempts, $last_log );
	}

	/**
	 * @param list<string> $feeds
	 * @return array{data:?array,status:array}
	 */
	private function finalize_threat_intel( array $feeds, int $malicious, int $suspicious, int $errors, int $attempts, array $last_log ): array {
		$data = [
			'label'      => 'Threat Intelligence',
			'malicious'  => $malicious,
			'suspicious' => $suspicious,
			'harmless'   => 0,
			'feeds'      => $feeds,
			'checked'    => true,
			'sources'    => array_filter( [ 'OTX', 'URLhaus' ] ),
		];

		if ( $attempts > 0 && $errors >= $attempts ) {
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

	/**
	 * @return array{data:?array,status:array<string,mixed>}
	 */
	public function fetch_geo_with_status( string $target ): array {
		$fields = 'status,message,country,countryCode,regionName,city,zip,lat,lon,isp,org,as,query,hosting';
		$pro_key = (string) $this->settings->get( 'api_keys.ipapi', '' );

		if ( '' !== $pro_key ) {
			$url = 'https://pro.ip-api.com/json/' . rawurlencode( $target ) . '?key=' . rawurlencode( $pro_key ) . '&fields=' . $fields;
		} else {
			// Free tier: HTTP only — HTTPS returns 403 ("SSL unavailable for this endpoint").
			$url = 'http://ip-api.com/json/' . rawurlencode( $target ) . '?fields=' . $fields;
		}

		$http = PDX_Http::get( $url, [ 'timeout' => 8 ], 'geo' );
		$resp = $http['response'];

		if ( is_wp_error( $resp ) ) {
			return [
				'data'   => null,
				'status' => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'GeoIP request failed: ' . $resp->get_error_message() ],
					$http['log']
				),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			return [
				'data'   => null,
				'status' => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'GeoIP HTTP ' . $code . ' — unexpected response.' ],
					$http['log']
				),
			];
		}

		if ( ( $body['status'] ?? '' ) !== 'success' ) {
			$api_msg = (string) ( $body['message'] ?? 'Lookup failed.' );
			$hint    = str_contains( strtolower( $api_msg ), 'ssl' )
				? ' Free tier requires HTTP; configure an ip-api Pro key in Admin → API Keys for HTTPS.'
				: '';
			return [
				'data'   => null,
				'status' => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'GeoIP: ' . $api_msg . $hint ],
					$http['log']
				),
			];
		}

		$data = [
			'label'    => 'IP Geolocation',
			'ip'       => $body['query']      ?? null,
			'country'  => $body['country']    ?? null,
			'code'     => $body['countryCode'] ?? null,
			'region'   => $body['regionName'] ?? null,
			'city'     => $body['city']       ?? null,
			'lat'      => $body['lat']        ?? null,
			'lon'      => $body['lon']        ?? null,
			'isp'      => $body['isp']        ?? null,
			'org'      => $body['org']        ?? null,
			'asn'      => $body['as']         ?? null,
			'hosting'  => (bool) ( $body['hosting'] ?? false ),
		];

		return [
			'data'   => $data,
			'status' => $this->with_http_log(
				[ 'state' => 'ok', 'message' => 'Resolved ' . ( $data['country'] ?? 'unknown' ) . ' via ip-api.com.' ],
				$http['log']
			),
		];
	}

	public function fetch_geo( string $target ): ?array {
		$result = $this->fetch_geo_with_status( $target );
		return $result['data'] ?? null;
	}

	/* ── VirusTotal ─────────────────────────────────────── */

	public function fetch_virustotal( string $target, string $type = 'domain', array $resolved = [] ): ?array {
		$key = $this->settings->get( 'api_keys.virustotal', '' );
		if ( ! $key ) {
			$this->last_paid_api_response = null;
			return null;
		}

		$endpoint = $this->virustotal_endpoint( $target, $type, $resolved );
		if ( ! $endpoint ) {
			$this->last_paid_api_response = null;
			return null;
		}

		$resp = wp_remote_get(
			$endpoint,
			[ 'timeout' => 12, 'headers' => [ 'x-apikey' => $key ] ]
		);
		$this->last_paid_api_response = $resp;
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return null;
		}

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

	/**
	 * @param array<string, mixed> $resolved
	 */
	private function virustotal_endpoint( string $target, string $type, array $resolved ): ?string {
		switch ( $type ) {
			case 'ip':
				return 'https://www.virustotal.com/api/v3/ip_addresses/' . rawurlencode( $target );
			case 'hash':
				return 'https://www.virustotal.com/api/v3/files/' . rawurlencode( $target );
			case 'url':
				$url = (string) ( $resolved['url'] ?? $resolved['raw'] ?? '' );
				if ( ! preg_match( '#^https?://#i', $url ) ) {
					$path  = $resolved['path'] ?? '/';
					$query = ! empty( $resolved['query'] ) ? '?' . $resolved['query'] : '';
					$url   = 'https://' . $target . $path . $query;
				}
				$id = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
				return 'https://www.virustotal.com/api/v3/urls/' . $id;
			case 'email':
			case 'domain':
			default:
				return 'https://www.virustotal.com/api/v3/domains/' . rawurlencode( $target );
		}
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

	/**
	 * @return array{data:?array,status:array<string,mixed>,resolved_ip:?string,http_code:int}
	 */
	public function fetch_shodan_with_status( string $target ): array {
		$key = trim( (string) $this->settings->get( 'api_keys.shodan', '' ) );
		if ( '' === $key ) {
			$this->last_paid_api_response = null;
			return [
				'data'        => null,
				'status'      => $this->skipped_status( 'Shodan API key not configured in Admin → API Keys.' ),
				'resolved_ip' => null,
				'http_code'   => 0,
			];
		}

		$resolved_ip = filter_var( $target, FILTER_VALIDATE_IP )
			? $target
			: $this->resolve_host_ip( $target );

		if ( ! $resolved_ip ) {
			return [
				'data'        => null,
				'status'      => [ 'state' => 'error', 'message' => 'Could not resolve ' . $target . ' to an IP address for Shodan lookup.' ],
				'resolved_ip' => null,
				'http_code'   => 0,
			];
		}

		$url  = 'https://api.shodan.io/shodan/host/' . rawurlencode( $resolved_ip ) . '?key=' . rawurlencode( $key );
		$http = PDX_Http::get( $url, [ 'timeout' => 15 ], 'shodan_host' );
		$resp = $http['response'];
		$this->last_paid_api_response = $resp;

		if ( is_wp_error( $resp ) ) {
			return [
				'data'        => null,
				'status'      => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'Shodan request failed: ' . $resp->get_error_message() ],
					$http['log']
				),
				'resolved_ip' => $resolved_ip,
				'http_code'   => 0,
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( 401 === $code || 403 === $code ) {
			return [
				'data'        => null,
				'status'      => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'Shodan authentication failed (HTTP ' . $code . ') — verify API key.' ],
					$http['log']
				),
				'resolved_ip' => $resolved_ip,
				'http_code'   => $code,
			];
		}

		if ( 429 === $code ) {
			return [
				'data'        => null,
				'status'      => $this->with_http_log(
					[ 'state' => 'partial', 'message' => 'Shodan rate limit reached (HTTP 429) — retry later.' ],
					$http['log']
				),
				'resolved_ip' => $resolved_ip,
				'http_code'   => $code,
			];
		}

		if ( 404 === $code ) {
			return [
				'data'        => null,
				'status'      => $this->with_http_log(
					[
						'state'   => 'partial',
						'message' => 'Shodan has no scan data for ' . $resolved_ip . ' (HTTP 404) — host may not be indexed yet.',
					],
					$http['log']
				),
				'resolved_ip' => $resolved_ip,
				'http_code'   => $code,
			];
		}

		if ( 200 !== $code || ! is_array( $body ) || ! empty( $body['error'] ) ) {
			$api_err = is_array( $body ) ? (string) ( $body['error'] ?? '' ) : '';
			return [
				'data'        => null,
				'status'      => $this->with_http_log(
					[
						'state'   => 'error',
						'message' => $api_err ? 'Shodan error: ' . $api_err : 'Shodan HTTP ' . $code . ' — unexpected response.',
					],
					$http['log']
				),
				'resolved_ip' => $resolved_ip,
				'http_code'   => $code,
			];
		}

		$data = $this->parse_shodan_host( $body, $resolved_ip );

		return [
			'data'        => $data,
			'status'      => $this->with_http_log(
				[
					'state'   => 'ok',
					'message' => 'Shodan host data for ' . $resolved_ip . ' (HTTP 200).',
				],
				$http['log']
			),
			'resolved_ip' => $resolved_ip,
			'http_code'   => $code,
		];
	}

	public function fetch_shodan( string $target ): ?array {
		$result = $this->fetch_shodan_with_status( $target );
		return $result['data'] ?? null;
	}

	/**
	 * Shodan DNS API — subdomain discovery for a domain.
	 *
	 * @return array{subdomains:list<string>,status:array<string,mixed>,http_code:int}
	 */
	public function fetch_shodan_dns_subdomains( string $domain ): array {
		$key = trim( (string) $this->settings->get( 'api_keys.shodan', '' ) );
		if ( '' === $key ) {
			return [
				'subdomains' => [],
				'status'     => $this->skipped_status( 'Shodan API key not configured.' ),
				'http_code'  => 0,
			];
		}

		$url  = 'https://api.shodan.io/dns/domain/' . rawurlencode( $domain ) . '?key=' . rawurlencode( $key );
		$http = PDX_Http::get( $url, [ 'timeout' => 12 ], 'shodan_dns' );
		$resp = $http['response'];

		if ( is_wp_error( $resp ) ) {
			return [
				'subdomains' => [],
				'status'     => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'Shodan DNS request failed: ' . $resp->get_error_message() ],
					$http['log']
				),
				'http_code'  => 0,
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( 401 === $code ) {
			return [
				'subdomains' => [],
				'status'     => $this->with_http_log(
					[ 'state' => 'error', 'message' => 'Shodan DNS rejected the API key (HTTP 401).' ],
					$http['log']
				),
				'http_code'  => $code,
			];
		}

		if ( 403 === $code ) {
			return [
				'subdomains' => [],
				'status'     => $this->with_http_log(
					[
						'state'   => 'skipped',
						'message' => 'Shodan DNS API requires a Membership plan (HTTP 403). Host API access is unchanged; subdomains are still discovered via CT and DNS.',
						'tier'    => 'membership',
					],
					$http['log']
				),
				'http_code'  => $code,
			];
		}

		if ( 200 !== $code || ! is_array( $body ) ) {
			return [
				'subdomains' => [],
				'status'     => $this->with_http_log(
					[ 'state' => 'partial', 'message' => 'Shodan DNS returned HTTP ' . $code . '.' ],
					$http['log']
				),
				'http_code'  => $code,
			];
		}

		$subs = [];
		foreach ( (array) ( $body['subdomains'] ?? [] ) as $sub ) {
			$sub = strtolower( trim( (string) $sub ) );
			if ( '' !== $sub ) {
				$subs[] = $sub . '.' . $domain;
			}
		}
		foreach ( (array) ( $body['data'] ?? [] ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['subdomain'] ) ) {
				$subs[] = strtolower( (string) $row['subdomain'] ) . '.' . $domain;
			}
		}
		$subs = array_values( array_unique( $subs ) );

		return [
			'subdomains' => $subs,
			'status'     => $this->with_http_log(
				[
					'state'   => 'ok',
					'message' => count( $subs ) . ' subdomain(s) from Shodan DNS (HTTP 200).',
				],
				$http['log']
			),
			'http_code'  => $code,
		];
	}

	/**
	 * Certificate Transparency + common-prefix DNS checks (no API key).
	 *
	 * @return array{subdomains:list<array{subdomain:string,ip:?string,source:string}>,status:array<string,mixed>}
	 */
	public function enumerate_subdomains( string $domain ): array {
		$found   = [];
		$sources = [];

		// crt.sh Certificate Transparency.
		$crt_url  = 'https://crt.sh/?q=' . rawurlencode( '%.' . $domain ) . '&output=json';
		$crt_http = PDX_Http::get( $crt_url, [ 'timeout' => 15 ], 'surface_crtsh' );
		$crt_resp = $crt_http['response'];
		if ( ! is_wp_error( $crt_resp ) && 200 === (int) wp_remote_retrieve_response_code( $crt_resp ) ) {
			$crt_data = json_decode( wp_remote_retrieve_body( $crt_resp ), true );
			if ( is_array( $crt_data ) ) {
				foreach ( $crt_data as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$names = preg_split( '/\s*,\s*/', (string) ( $row['name_value'] ?? '' ) ) ?: [];
					foreach ( $names as $name ) {
						$name = strtolower( trim( $name ) );
						if ( str_starts_with( $name, '*.' ) ) {
							$name = substr( $name, 2 );
						}
						if ( $name && ( $name === $domain || str_ends_with( $name, '.' . $domain ) ) ) {
							$found[ $name ] = [ 'subdomain' => $name, 'ip' => null, 'source' => 'crt.sh' ];
						}
					}
				}
				if ( ! empty( $found ) ) {
					$sources[] = 'crt.sh';
				}
			}
		}

		// Common prefix DNS resolution.
		$prefixes = [ 'www', 'mail', 'ftp', 'admin', 'api', 'dev', 'staging', 'test', 'shop', 'portal', 'vpn', 'webmail', 'blog', 'app', 'cdn', 'static', 'support', 'status', 'demo', 'secure', 'login', 'mx', 'ns', 'autodiscover', 'cpanel' ];
		foreach ( $prefixes as $prefix ) {
			$host = $prefix . '.' . $domain;
			if ( isset( $found[ $host ] ) ) {
				continue;
			}
			$ip = $this->resolve_host_ip( $host );
			if ( $ip ) {
				$found[ $host ] = [ 'subdomain' => $host, 'ip' => $ip, 'source' => 'dns' ];
				$sources[]      = 'dns-prefix';
			}
		}

		$subdomains = array_values( $found );
		$status     = [
			'state'   => ! empty( $subdomains ) ? 'ok' : 'partial',
			'message' => ! empty( $subdomains )
				? count( $subdomains ) . ' subdomain(s) via ' . implode( ', ', array_unique( $sources ) ) . '.'
				: 'No subdomains discovered via CT logs or common-prefix DNS.',
		];

		return [
			'subdomains' => $subdomains,
			'status'     => $status,
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function parse_shodan_host( array $body, string $resolved_ip ): array {
		$ports = array_values( array_unique( array_map( 'intval', $body['ports'] ?? [] ) ) );
		sort( $ports );

		$services     = [];
		$technologies = [];
		foreach ( (array) ( $body['data'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$port    = (int) ( $item['port'] ?? 0 );
			$product = (string) ( $item['product'] ?? '' );
			$banner  = (string) ( $item['data'] ?? $item['banner'] ?? '' );
			$service = [
				'port'      => $port,
				'service'   => $product ?: $this->port_label( $port ),
				'transport' => (string) ( $item['transport'] ?? 'tcp' ),
				'banner'    => substr( $banner, 0, 200 ),
			];
			$services[] = $service;
			if ( $product ) {
				$technologies[ strtolower( $product ) ] = [ 'name' => $product, 'port' => $port ];
			}
			if ( ! empty( $item['http']['server'] ) ) {
				$server = (string) $item['http']['server'];
				$technologies[ strtolower( $server ) ] = [ 'name' => $server, 'port' => $port, 'type' => 'http-server' ];
			}
		}

		if ( empty( $services ) ) {
			foreach ( $ports as $port ) {
				$services[] = [ 'port' => $port, 'service' => $this->port_label( $port ), 'transport' => 'tcp', 'banner' => '' ];
			}
		}

		$vulns = array_values( array_unique( array_map( 'strval', array_keys( (array) ( $body['vulns'] ?? [] ) ) ) ) );

		return [
			'label'        => 'Shodan',
			'ip'           => $body['ip_str'] ?? $resolved_ip,
			'org'          => $body['org'] ?? null,
			'isp'          => $body['isp'] ?? null,
			'country'      => $body['country_name'] ?? null,
			'ports'        => $ports,
			'vulns'        => $vulns,
			'hostnames'    => $body['hostnames'] ?? [],
			'os'           => $body['os'] ?? null,
			'last_update'  => $body['last_update'] ?? null,
			'services'     => $services,
			'technologies' => array_values( $technologies ),
		];
	}

	/**
	 * AbuseIPDB IP reputation check.
	 *
	 * @return array{data:?array,status:array}
	 */
	public function fetch_abuseipdb( string $ip ): array {
		$key = (string) $this->settings->get( 'api_keys.abuseipdb', '' );
		if ( '' === $key ) {
			return [
				'data'   => null,
				'status' => $this->skipped_status( 'AbuseIPDB API key not configured.' ),
			];
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return [
				'data'   => null,
				'status' => [ 'state' => 'error', 'message' => 'Invalid IP address for AbuseIPDB lookup.' ],
			];
		}

		$url  = 'https://api.abuseipdb.com/api/v2/check?' . http_build_query(
			[
				'ipAddress'    => $ip,
				'maxAgeInDays' => 90,
			]
		);
		$http = PDX_Http::get(
			$url,
			[
				'timeout' => 12,
				'headers' => [
					'Key'    => $key,
					'Accept' => 'application/json',
				],
			],
			'abuseipdb'
		);
		$resp = $http['response'];
		$this->last_paid_api_response = $resp;

		if ( is_wp_error( $resp ) ) {
			$status = [ 'state' => 'error', 'message' => 'AbuseIPDB request failed: ' . $resp->get_error_message() ];
			return [ 'data' => null, 'status' => $this->with_http_log( $status, $http['log'] ) ];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( 429 === $code ) {
			$status = [ 'state' => 'partial', 'message' => 'AbuseIPDB rate limit reached — retry later.' ];
			return [ 'data' => null, 'status' => $this->with_http_log( $status, $http['log'] ) ];
		}

		if ( 401 === $code || 403 === $code ) {
			$status = [ 'state' => 'error', 'message' => 'AbuseIPDB authentication failed — verify API key.' ];
			return [ 'data' => null, 'status' => $this->with_http_log( $status, $http['log'] ) ];
		}

		if ( 200 !== $code || ! is_array( $body['data'] ?? null ) ) {
			$detail = is_array( $body ) ? (string) ( $body['errors'][0]['detail'] ?? $body['errors'][0]['title'] ?? '' ) : '';
			$status = [ 'state' => 'error', 'message' => $detail ? 'AbuseIPDB error: ' . $detail : 'AbuseIPDB returned an unexpected response.' ];
			return [ 'data' => null, 'status' => $this->with_http_log( $status, $http['log'] ) ];
		}

		$row = $body['data'];
		$data = [
			'label'             => 'AbuseIPDB',
			'ip'                => $row['ipAddress'] ?? $ip,
			'abuse_confidence'  => (int) ( $row['abuseConfidenceScore'] ?? 0 ),
			'total_reports'     => (int) ( $row['totalReports'] ?? 0 ),
			'num_distinct_users'=> (int) ( $row['numDistinctUsers'] ?? 0 ),
			'country'           => $row['countryCode'] ?? null,
			'isp'               => $row['isp'] ?? null,
			'domain'            => $row['domain'] ?? null,
			'usage_type'        => $row['usageType'] ?? null,
			'is_whitelisted'    => ! empty( $row['isWhitelisted'] ),
			'is_tor'            => ! empty( $row['isTor'] ),
			'last_reported_at'  => $row['lastReportedAt'] ?? null,
		];

		$conf = (int) $data['abuse_confidence'];
		$message = $conf >= 50
			? 'High abuse confidence reported by AbuseIPDB.'
			: ( $conf >= 25 ? 'Moderate abuse confidence reported by AbuseIPDB.' : 'No significant abuse reports in AbuseIPDB.' );

		return [
			'data'   => $data,
			'status' => $this->with_http_log( [ 'state' => 'ok', 'message' => $message ], $http['log'] ),
		];
	}

	/* ── Hunter.io ──────────────────────────────────────── */

	public function fetch_hunter( string $domain ): ?array {
		$key = $this->settings->get( 'api_keys.hunter', '' );
		if ( ! $key ) {
			$this->last_paid_api_response = null;
			return null;
		}

		$resp = wp_remote_get(
			'https://api.hunter.io/v2/domain-search?domain=' . rawurlencode( $domain ) . '&api_key=' . rawurlencode( $key ) . '&limit=10',
			[ 'timeout' => 8 ]
		);
		$this->last_paid_api_response = $resp;
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return null;
		}

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
	 * Sources that must respond for a verified verdict (by target type).
	 *
	 * @return list<string>
	 */
	public function required_sources_for( string $target_type ): array {
		return match ( $target_type ) {
			'ip'    => [ 'geo', 'threat' ],
			'hash'  => [ 'threat' ],
			'email' => [ 'dns', 'threat' ],
			'url'   => [ 'threat', 'url_forensics' ],
			default => [ 'dns', 'threat' ],
		};
	}

	/**
	 * Whether a source status allows its data to contribute to risk scoring.
	 */
	private function source_scoring_eligible( string $key, array $source_status ): bool {
		$state = $source_status[ $key ]['state'] ?? 'error';
		return in_array( $state, [ 'ok', 'partial' ], true );
	}

	public function compute_risk( array $sources, array $source_status = [], array $forensics = [], string $target_type = 'domain' ): array {
		$score                 = 0;
		$factors               = [];
		$contributing_sources  = [];

		// SSL grade — only when assessment completed and source verified.
		$ssl = $sources['ssl'] ?? [];
		if ( $this->source_scoring_eligible( 'ssl', $source_status ) && ! empty( $ssl['assessed'] ) && ! empty( $ssl['grade'] ) ) {
			$grade_map = [ 'A+' => 0, 'A' => 5, 'A-' => 8, 'B' => 20, 'C' => 35, 'D' => 50, 'E' => 65, 'F' => 80, 'T' => 70, 'M' => 60 ];
			$ssl_grade = $ssl['grade'];
			$ssl_risk  = $grade_map[ $ssl_grade ] ?? 20;
			$score    += $ssl_risk;
			if ( $ssl_risk > 0 ) {
				$factors[] = [ 'factor' => 'SSL Grade', 'value' => $ssl_grade, 'risk' => $ssl_risk, 'weight' => 'medium', 'source' => 'ssl' ];
			}
			$contributing_sources[] = 'ssl';
		}

		// Domain age (RDAP) — only when RDAP succeeded.
		if ( $this->source_scoring_eligible( 'rdap', $source_status ) ) {
			$age_days = $sources['rdap']['age_days'] ?? null;
			if ( null !== $age_days ) {
				if ( $age_days < 30 ) {
					$score    += 30;
					$factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 30, 'weight' => 'high', 'source' => 'rdap' ];
				} elseif ( $age_days < 180 ) {
					$score    += 15;
					$factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 15, 'weight' => 'medium', 'source' => 'rdap' ];
				} elseif ( $age_days < 365 ) {
					$score    += 5;
					$factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 5, 'weight' => 'low', 'source' => 'rdap' ];
				}
				$contributing_sources[] = 'rdap';
			}
		}

		// Threat intelligence — only when feeds responded.
		if ( $this->source_scoring_eligible( 'threat', $source_status ) ) {
			$threat = $sources['threat'] ?? [];
			$mal    = (int) ( $threat['malicious'] ?? $sources['virustotal']['malicious'] ?? 0 );
			$sus    = (int) ( $threat['suspicious'] ?? $sources['virustotal']['suspicious'] ?? 0 );
			if ( $mal > 5 ) {
				$score    += 40;
				$factors[] = [ 'factor' => 'Threat Feed Detections', 'value' => "{$mal} malicious", 'risk' => 40, 'weight' => 'critical', 'source' => 'threat' ];
			} elseif ( $mal > 0 ) {
				$score    += 25;
				$factors[] = [ 'factor' => 'Threat Feed Detections', 'value' => "{$mal} malicious", 'risk' => 25, 'weight' => 'high', 'source' => 'threat' ];
			} elseif ( $sus > 0 ) {
				$score    += 12;
				$factors[] = [ 'factor' => 'Threat Feed Suspicious', 'value' => "{$sus} suspicious", 'risk' => 12, 'weight' => 'medium', 'source' => 'threat' ];
			}
			$contributing_sources[] = 'threat';
		}

		$abuse = $sources['abuseipdb'] ?? [];
		if ( $this->source_scoring_eligible( 'abuseipdb', $source_status ) && ! empty( $abuse ) ) {
			$conf    = (int) ( $abuse['abuse_confidence'] ?? 0 );
			$reports = (int) ( $abuse['total_reports'] ?? 0 );
			if ( $conf >= 75 || $reports >= 20 ) {
				$score    += 35;
				$factors[] = [ 'factor' => 'AbuseIPDB Reputation', 'value' => "{$conf}% confidence ({$reports} reports)", 'risk' => 35, 'weight' => 'critical', 'source' => 'abuseipdb' ];
			} elseif ( $conf >= 50 || $reports >= 5 ) {
				$score    += 22;
				$factors[] = [ 'factor' => 'AbuseIPDB Reputation', 'value' => "{$conf}% confidence ({$reports} reports)", 'risk' => 22, 'weight' => 'high', 'source' => 'abuseipdb' ];
			} elseif ( $conf >= 25 ) {
				$score    += 10;
				$factors[] = [ 'factor' => 'AbuseIPDB Reputation', 'value' => "{$conf}% confidence", 'risk' => 10, 'weight' => 'medium', 'source' => 'abuseipdb' ];
			}
			$contributing_sources[] = 'abuseipdb';
		}

		if ( $this->source_scoring_eligible( 'shodan', $source_status ) && isset( $sources['shodan'] ) ) {
			$vulns = count( $sources['shodan']['vulns'] ?? [] );
			$ports = count( $sources['shodan']['ports'] ?? [] );
			if ( $vulns > 0 ) {
				$risk_v = min( 30, $vulns * 8 );
				$score += $risk_v;
				$factors[] = [ 'factor' => 'Known CVEs', 'value' => "{$vulns} CVEs", 'risk' => $risk_v, 'weight' => 'critical', 'source' => 'shodan' ];
			}
			if ( $ports > 20 ) {
				$score    += 10;
				$factors[] = [ 'factor' => 'Open Ports', 'value' => "{$ports} ports", 'risk' => 10, 'weight' => 'medium', 'source' => 'shodan' ];
			}
			if ( $vulns > 0 || $ports > 20 ) {
				$contributing_sources[] = 'shodan';
			}
		}

		if ( $this->source_scoring_eligible( 'geo', $source_status ) && ! empty( $sources['geolocation']['hosting'] ) ) {
			$score    += 3;
			$factors[] = [ 'factor' => 'Hosting Provider IP', 'value' => 'Yes', 'risk' => 3, 'weight' => 'low', 'source' => 'geo' ];
			$contributing_sources[] = 'geo';
		}

		$dns = $sources['dns'] ?? [];
		if ( $this->source_scoring_eligible( 'dns', $source_status ) && ! empty( $dns ) && empty( $dns['spf'] ) && ! empty( $dns['mx'] ) ) {
			$score    += 5;
			$factors[] = [ 'factor' => 'Email Auth', 'value' => 'No SPF', 'risk' => 5, 'weight' => 'low', 'source' => 'dns' ];
			$contributing_sources[] = 'dns';
		}

		$forensics_eligible = $this->source_scoring_eligible( 'url_forensics', $source_status )
			|| ( 'url' !== $target_type && ! empty( $forensics ) );
		if ( $forensics_eligible ) {
			$phish_score = (int) ( $forensics['phishing_score'] ?? $sources['url_forensics']['phishing']['score'] ?? 0 );
			if ( $phish_score >= 25 ) {
				$phish_risk = min( 35, $phish_score );
				$score     += $phish_risk;
				$factors[] = [
					'factor' => 'Phishing / Page Forensics',
					'value'  => ( $forensics['phishing_verdict'] ?? 'elevated' ) . " ({$phish_score})",
					'risk'   => $phish_risk,
					'weight' => $phish_score >= 45 ? 'critical' : 'high',
					'source' => 'url_forensics',
				];
				$contributing_sources[] = 'url_forensics';
			}

			if ( ! empty( $forensics['has_login_form'] ) ) {
				$score    += 10;
				$factors[] = [ 'factor' => 'Credential Form on Page', 'value' => 'Detected', 'risk' => 10, 'weight' => 'medium', 'source' => 'url_forensics' ];
				$contributing_sources[] = 'url_forensics';
			}

			if ( ! empty( $forensics['redirect_hops'] ) && (int) $forensics['redirect_hops'] > 3 ) {
				$score    += 8;
				$factors[] = [ 'factor' => 'Redirect Chain', 'value' => (int) $forensics['redirect_hops'] . ' hops', 'risk' => 8, 'weight' => 'medium', 'source' => 'url_forensics' ];
				$contributing_sources[] = 'url_forensics';
			}

			$path_risk = (int) ( $forensics['path_risk_score'] ?? 0 );
			if ( $path_risk >= 10 ) {
				$path_factor = min( 18, $path_risk );
				$score      += $path_factor;
				$factors[]   = [ 'factor' => 'Suspicious URL Path', 'value' => "{$path_risk}", 'risk' => $path_factor, 'weight' => 'high', 'source' => 'url_forensics' ];
				$contributing_sources[] = 'url_forensics';
			}

			$landing_risk = (int) ( $forensics['landing_risk_score'] ?? 0 );
			if ( $landing_risk >= 10 ) {
				$land_factor = min( 20, $landing_risk );
				$score      += $land_factor;
				$factors[]   = [ 'factor' => 'Landing Page Heuristics', 'value' => "{$landing_risk}", 'risk' => $land_factor, 'weight' => 'high', 'source' => 'url_forensics' ];
				$contributing_sources[] = 'url_forensics';
			}

			$intent = (string) ( $forensics['redirect_intent'] ?? '' );
			if ( in_array( $intent, [ 'multi_hop_laundering', 'cross_domain_delivery' ], true ) ) {
				$score    += 10;
				$factors[] = [ 'factor' => 'Redirect Intent', 'value' => $intent, 'risk' => 10, 'weight' => 'high', 'source' => 'url_forensics' ];
				$contributing_sources[] = 'url_forensics';
			}

			if ( ! empty( $forensics['external_form_action'] ) ) {
				$score    += 14;
				$factors[] = [ 'factor' => 'Credential Exfiltration Form', 'value' => 'External action', 'risk' => 14, 'weight' => 'critical', 'source' => 'url_forensics' ];
				$contributing_sources[] = 'url_forensics';
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
					'source' => 'url_forensics',
				];
				$contributing_sources[] = 'url_forensics';
			}

			$infra_score = (int) ( $forensics['infrastructure_score'] ?? 0 );
			if ( $infra_score > 0 ) {
				$score    += $infra_score;
				$factors[] = [
					'factor' => 'Infrastructure Fingerprint',
					'value'  => (string) ( $forensics['infrastructure_fingerprint'] ?? 'mapped' ),
					'risk'   => $infra_score,
					'weight' => 'medium',
					'source' => 'url_forensics',
				];
				$contributing_sources[] = 'url_forensics';
			}
		}

		$contributing_sources = array_values( array_unique( $contributing_sources ) );
		$indicative_score     = min( 100, max( 0, $score ) );
		$verdict              = $this->resolve_verdict( $indicative_score, $source_status, $target_type );
		$verified_score       = 'insufficient_data' === $verdict ? 0 : $indicative_score;

		$labels = [
			'clean'             => 'Clean',
			'low'               => 'Low Risk',
			'medium'            => 'Medium Risk',
			'high'              => 'High Risk',
			'critical'          => 'Critical',
			'insufficient_data' => 'Insufficient Data',
		];

		return [
			'score'                => $verified_score,
			'indicative_score'     => $indicative_score,
			'verdict'              => $verdict,
			'factors'              => $factors,
			'contributing_sources' => $contributing_sources,
			'label'                => $labels[ $verdict ] ?? ucfirst( $verdict ),
			'confidence'           => 0,
		];
	}

	private function resolve_verdict( int $score, array $source_status, string $target_type ): string {
		if ( ! $this->scan_has_sufficient_coverage( $source_status, $target_type ) ) {
			return 'insufficient_data';
		}

		$threat_state = $source_status['threat']['state'] ?? 'error';
		if ( ! in_array( $threat_state, [ 'ok', 'partial' ], true ) ) {
			return 'insufficient_data';
		}

		$verdict = 'clean';
		if ( $score >= 75 ) {
			$verdict = 'critical';
		} elseif ( $score >= 50 ) {
			$verdict = 'high';
		} elseif ( $score >= 25 ) {
			$verdict = 'medium';
		} elseif ( $score >= 10 ) {
			$verdict = 'low';
		}

		if ( in_array( $verdict, [ 'clean', 'low' ], true ) && 'ok' !== $threat_state ) {
			return 'insufficient_data';
		}

		return $verdict;
	}

	private function scan_has_sufficient_coverage( array $source_status, string $target_type ): bool {
		foreach ( $this->required_sources_for( $target_type ) as $key ) {
			$state = $source_status[ $key ]['state'] ?? 'error';
			if ( ! in_array( $state, [ 'ok', 'partial' ], true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Summarize whether the report is reliable enough for customer-facing verdicts.
	 *
	 * @param array<string, mixed> $risk
	 * @return array<string, mixed>
	 */
	public function assess_report_quality( array $source_status, string $target_type, array $risk = [] ): array {
		$required         = $this->required_sources_for( $target_type );
		$failed_required  = [];
		$failed_optional  = [];
		$skipped          = [];
		$partial          = [];
		$required_states  = [];

		foreach ( $source_status as $key => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$state = $meta['state'] ?? 'error';
			if ( 'error' === $state ) {
				if ( in_array( $key, $required, true ) ) {
					$failed_required[] = (string) $key;
				} else {
					$failed_optional[] = (string) $key;
				}
			} elseif ( 'skipped' === $state ) {
				$skipped[] = (string) $key;
			} elseif ( 'partial' === $state ) {
				$partial[] = (string) $key;
			}
			if ( in_array( $key, $required, true ) ) {
				$required_states[ $key ] = $state;
			}
		}

		$verdict       = (string) ( $risk['verdict'] ?? 'insufficient_data' );
		$required_ok   = empty( $failed_required ) && $this->scan_has_sufficient_coverage( $source_status, $target_type );
		$coverage_tier = 'verified';

		if ( ! $required_ok || 'insufficient_data' === $verdict ) {
			$coverage_tier = 'incomplete';
		} elseif ( ! empty( $failed_optional ) || ! empty( $partial ) ) {
			$coverage_tier = 'partial';
		}

		$reliable = 'verified' === $coverage_tier;

		if ( $reliable ) {
			$message = 'Required intelligence sources responded successfully.';
		} elseif ( 'partial' === $coverage_tier ) {
			$gaps = array_merge( $failed_optional, $partial, $skipped );
			$message = 'Partial intelligence — core sources verified'
				. ( ! empty( $gaps ) ? '; unavailable: ' . implode( ', ', $gaps ) : '' )
				. '. Risk score reflects verified sources only.';
		} elseif ( ! empty( $failed_required ) ) {
			$message = 'Required sources failed (' . implode( ', ', $failed_required ) . ') — risk score and verdict are not verified.';
		} else {
			$message = 'Insufficient intelligence coverage — do not treat this target as verified safe.';
		}

		return [
			'reliable'              => $reliable,
			'coverage_tier'         => $coverage_tier,
			'failed_sources'        => array_values( array_unique( array_merge( $failed_required, $failed_optional ) ) ),
			'failed_required'       => $failed_required,
			'failed_optional'       => $failed_optional,
			'skipped_sources'       => $skipped,
			'partial_sources'       => $partial,
			'required_sources'      => $required_states,
			'contributing_sources'  => (array) ( $risk['contributing_sources'] ?? [] ),
			'message'               => $message,
		];
	}

	/**
	 * @param array<string, array{state?:string}> $source_status
	 */
	public function compute_confidence( array $source_status, string $target_type = 'domain', string $coverage_tier = 'verified' ): int {
		$weights = match ( $target_type ) {
			'ip'    => [ 'ip_network' => 20, 'reverse_dns' => 10, 'geo' => 25, 'threat' => 25, 'abuseipdb' => 20 ],
			'hash'  => [ 'threat' => 70, 'virustotal' => 30 ],
			'email' => [ 'rdap' => 20, 'dns' => 35, 'ssl' => 15, 'threat' => 30 ],
			'url'   => [ 'rdap' => 15, 'dns' => 15, 'ssl' => 15, 'threat' => 35, 'url_forensics' => 20 ],
			default => [ 'rdap' => 25, 'dns' => 25, 'ssl' => 25, 'threat' => 25 ],
		};

		$required = $this->required_sources_for( $target_type );
		$total    = 0;
		foreach ( $weights as $key => $weight ) {
			$state = $source_status[ $key ]['state'] ?? 'error';
			if ( 'ok' === $state ) {
				$total += $weight;
			} elseif ( 'partial' === $state ) {
				$total += (int) round( $weight * 0.5 );
			} elseif ( 'skipped' === $state && ! in_array( $key, $required, true ) ) {
				$total += (int) round( $weight * 0.15 );
			}
		}

		$total = min( 100, max( 0, $total ) );

		if ( 'incomplete' === $coverage_tier ) {
			return min( $total, 20 );
		}
		if ( 'partial' === $coverage_tier ) {
			return min( $total, 85 );
		}

		return $total;
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

		if ( ( $status['rdap']['state'] ?? '' ) === 'error' && 'ip' !== ( $report['target_type'] ?? '' ) ) {
			$recs[] = 'WHOIS/RDAP lookup failed; registration ownership could not be verified.';
		} elseif ( ( $status['rdap']['state'] ?? '' ) === 'skipped' && 'ip' !== ( $report['target_type'] ?? '' ) ) {
			$recs[] = 'RDAP is unavailable for this TLD — registration age and ownership were not verified.';
		}

		$coverage = (string) ( $report['report_quality']['coverage_tier'] ?? 'verified' );
		if ( 'incomplete' === $coverage ) {
			$recs[] = 'Do not treat this assessment as verified — required intelligence sources did not all respond.';
		} elseif ( 'partial' === $coverage ) {
			$recs[] = 'Partial assessment — review provider status below; some optional sources were unavailable.';
		}
		if ( ( $status['ip_network']['state'] ?? '' ) === 'error' && 'ip' === ( $report['target_type'] ?? '' ) ) {
			$recs[] = 'IP network registration lookup failed; ASN/CIDR ownership could not be verified.';
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

			// NVD miss is expected for many keyword searches — fall through to CIRCL without logging.
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
				'target'          => $raw_target,
				'target_raw'      => $raw_target,
				'ports'           => [],
				'vulns'           => [],
				'vulnerabilities' => [],
				'dns'             => [],
				'subdomains'      => [],
				'services'        => [],
				'technologies'    => [],
				'score'           => 0,
				'summary'         => $resolved->get_error_message(),
				'source'          => [],
				'provider_status' => [],
				'error'           => $resolved->get_error_message(),
				'scan_complete'   => false,
			];
		}

		$domain = PDX_Target::api_host( $resolved );
		$type   = (string) ( $resolved['type'] ?? 'domain' );
		$key    = trim( (string) $this->settings->get( 'api_keys.shodan', '' ) );

		$result = [
			'target'          => $domain,
			'target_raw'      => $resolved['raw'],
			'target_type'     => $type,
			'resolved_ip'     => null,
			'ports'           => [],
			'services'        => [],
			'vulns'           => [],
			'vulnerabilities' => [],
			'subdomains'      => [],
			'technologies'    => [],
			'dns'             => [],
			'os'              => null,
			'org'             => null,
			'country'         => null,
			'score'           => 0,
			'summary'         => '',
			'source'          => [],
			'provider_status' => [],
			'api_keys'        => [ 'shodan' => '' !== $key ],
			'scan_complete'   => true,
		];

		// ── DNS (independent of Shodan) ─────────────────
		if ( in_array( $type, [ 'domain', 'url', 'email' ], true ) ) {
			$dns_out = $this->fetch_dns( $domain );
			$result['provider_status']['dns'] = $dns_out['status'];
			if ( ! empty( $dns_out['data'] ) ) {
				$dns_data = $dns_out['data'];
				foreach ( [ 'A' => 'a', 'AAAA' => 'aaaa', 'MX' => 'mx', 'TXT' => 'txt', 'NS' => 'ns', 'CAA' => 'caa' ] as $label => $key_name ) {
					foreach ( (array) ( $dns_data[ $key_name ] ?? [] ) as $val ) {
						$result['dns'][] = [ 'type' => $label, 'value' => (string) $val ];
					}
				}
				if ( ! empty( $dns_data['spf'] ) ) {
					$result['dns'][] = [ 'type' => 'SPF', 'value' => (string) $dns_data['spf'] ];
				}
				if ( ! empty( $dns_data['dmarc'] ) ) {
					$result['dns'][] = [ 'type' => 'DMARC', 'value' => (string) $dns_data['dmarc'] ];
				}
				$result['source'][] = 'DNS';
				$result['resolved_ip'] = $dns_data['a'][0] ?? $this->resolve_host_ip( $domain );
			}
		} elseif ( 'ip' === $type ) {
			$result['resolved_ip'] = $domain;
		}

		// ── Subdomain discovery ─────────────────────────
		if ( in_array( $type, [ 'domain', 'url', 'email' ], true ) ) {
			$enum = $this->enumerate_subdomains( $domain );
			$result['provider_status']['subdomains_ct'] = $enum['status'];
			$merged_subs = $enum['subdomains'];

			if ( '' !== $key ) {
				$shodan_dns = $this->fetch_shodan_dns_subdomains( $domain );
				$result['provider_status']['shodan_dns'] = [
					'state'   => $shodan_dns['status']['state'] ?? 'error',
					'message' => (string) ( $shodan_dns['status']['message'] ?? '' ),
					'http'    => $shodan_dns['http_code'] ?? 0,
				];
				foreach ( $shodan_dns['subdomains'] as $sub ) {
					$merged_subs[] = [ 'subdomain' => $sub, 'ip' => null, 'source' => 'shodan-dns' ];
				}
				if ( ! empty( $shodan_dns['subdomains'] ) ) {
					$result['source'][] = 'Shodan DNS';
				}
			}

			$by_name = [];
			foreach ( $merged_subs as $row ) {
				$name = is_array( $row ) ? (string) ( $row['subdomain'] ?? '' ) : (string) $row;
				if ( $name && ! isset( $by_name[ $name ] ) ) {
					$by_name[ $name ] = is_array( $row ) ? $row : [ 'subdomain' => $name, 'ip' => null, 'source' => 'merged' ];
				}
			}
			$result['subdomains'] = array_values( $by_name );
			if ( ! empty( $result['subdomains'] ) && ! in_array( 'Subdomain enum', $result['source'], true ) ) {
				$result['source'][] = 'Subdomain enum';
			}
		}

		// ── Shodan host scan (requires IP + API key) ──────
		$shodan_target = $result['resolved_ip'] ?: $domain;
		$shodan_out    = $this->fetch_shodan_with_status( $shodan_target );
		$result['provider_status']['shodan'] = array_merge(
			$shodan_out['status'],
			[
				'http'        => $shodan_out['http_code'] ?? 0,
				'resolved_ip' => $shodan_out['resolved_ip'] ?? null,
				'key_loaded'  => '' !== $key,
			]
		);
		if ( ! empty( $shodan_out['resolved_ip'] ) ) {
			$result['resolved_ip'] = $shodan_out['resolved_ip'];
		}

		if ( ! empty( $shodan_out['data'] ) ) {
			$shodan = $shodan_out['data'];
			$result['ports']           = $shodan['ports'] ?? [];
			$result['vulns']           = $shodan['vulns'] ?? [];
			$result['vulnerabilities'] = array_map(
				static fn( $id ) => [ 'id' => $id, 'cve' => $id, 'severity' => 'medium', 'source' => 'Shodan' ],
				$result['vulns']
			);
			$result['services']        = $shodan['services'] ?? [];
			$result['technologies']    = $shodan['technologies'] ?? [];
			$result['os']              = $shodan['os'] ?? null;
			$result['org']             = $shodan['org'] ?? null;
			$result['country']         = $shodan['country'] ?? null;
			$result['source'][]        = 'Shodan';
		}

		$result['source'] = array_values( array_unique( $result['source'] ) );

		// ── Risk score ────────────────────────────────────
		$port_count = count( $result['ports'] );
		$vuln_count = count( $result['vulns'] );
		$score      = 0;
		if ( $port_count > 20 ) {
			$score += 30;
		} elseif ( $port_count > 10 ) {
			$score += 20;
		} elseif ( $port_count > 5 ) {
			$score += 10;
		}
		$score += min( 50, $vuln_count * 10 );
		$risky = array_intersect( $result['ports'], [ 21, 23, 445, 3389, 5900, 6379, 27017 ] );
		$score += count( $risky ) * 5;
		$result['score'] = min( 100, $score );

		// ── Summary & warnings ──────────────────────────
		$parts    = [];
		$warnings = [];

		if ( $port_count ) {
			$parts[] = "{$port_count} open port" . ( 1 !== $port_count ? 's' : '' );
		}
		if ( $vuln_count ) {
			$parts[] = "{$vuln_count} known CVE" . ( 1 !== $vuln_count ? 's' : '' );
		}
		if ( count( $result['subdomains'] ) ) {
			$parts[] = count( $result['subdomains'] ) . ' subdomain(s)';
		}
		if ( $result['os'] ) {
			$parts[] = 'OS: ' . $result['os'];
		}

		foreach ( $result['provider_status'] as $provider => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			if ( 'error' === ( $meta['state'] ?? '' ) ) {
				$warnings[] = ucfirst( str_replace( '_', ' ', $provider ) ) . ': ' . ( $meta['message'] ?? 'failed' );
			}
		}

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		if ( '' === $key ) {
			$result['summary'] = 'Shodan API key not configured — DNS and subdomain enumeration ran, but port/service data requires a key in Admin → API Keys.';
			$result['scan_complete'] = ! empty( $result['dns'] ) || ! empty( $result['subdomains'] );
		} elseif ( $parts ) {
			$result['summary'] = 'Attack surface analysis complete. Found: ' . implode( ', ', $parts ) . '.';
		} elseif ( ! empty( $warnings ) ) {
			$result['summary'] = 'Scan completed with provider errors: ' . implode( '; ', $warnings );
			$result['scan_complete'] = false;
		} else {
			$result['summary'] = 'No Shodan scan data for this target yet. DNS/subdomain enumeration may still have returned records below.';
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'[PDX] attack_surface ' . $domain
				. ' shodan_key=' . ( '' !== $key ? 'yes' : 'no' )
				. ' ip=' . ( $result['resolved_ip'] ?? 'none' )
				. ' ports=' . $port_count
				. ' subs=' . count( $result['subdomains'] )
				. ' providers=' . wp_json_encode( array_map(
					static fn( $m ) => is_array( $m ) ? ( $m['state'] ?? '?' ) . '@' . ( $m['http'] ?? '' ) : '?',
					$result['provider_status']
				) )
			);
		}

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
