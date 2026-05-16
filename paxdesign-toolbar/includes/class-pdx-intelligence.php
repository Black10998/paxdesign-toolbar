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
	public function full_scan( string $target, bool $paid = false ): array {
		$start  = microtime( true );
		$report = [
			'target'     => $target,
			'scan_id'    => 'scan-' . substr( bin2hex( random_bytes( 6 ) ), 0, 10 ),
			'timestamp'  => gmdate( 'c' ),
			'paid'       => $paid,
			'sources'    => [],
			'risk'       => [],
			'timeline'   => [],
			'indicators' => [],
		];

		// Always-free sources
		$rdap = $this->fetch_rdap( $target );
		if ( $rdap ) {
			$report['sources']['rdap'] = $this->parse_rdap( $rdap );
			$report['timeline']        = array_merge( $report['timeline'], $this->extract_timeline( $rdap ) );
		}

		$ssl = $this->fetch_ssl( $target );
		if ( $ssl ) {
			$report['sources']['ssl'] = $this->parse_ssl( $ssl );
		}

		// Paid sources
		if ( $paid ) {
			$geo = $this->fetch_geo( $target );
			if ( $geo ) $report['sources']['geolocation'] = $geo;

			$vt = $this->fetch_virustotal( $target );
			if ( $vt ) {
				$report['sources']['virustotal'] = $vt;
				$report['indicators'] = array_merge( $report['indicators'], $this->extract_iocs( $vt ) );
			}

			$shodan = $this->fetch_shodan( $target );
			if ( $shodan ) $report['sources']['shodan'] = $shodan;

			$hunter = $this->fetch_hunter( $target );
			if ( $hunter ) $report['sources']['hunter'] = $hunter;
		}

		// Risk scoring
		$report['risk']     = $this->compute_risk( $report['sources'] );
		$report['duration'] = round( microtime( true ) - $start, 3 );

		return $report;
	}

	/* ── RDAP ───────────────────────────────────────────── */

	public function fetch_rdap( string $domain ): ?array {
		$resp = wp_remote_get(
			'https://rdap.org/domain/' . rawurlencode( $domain ),
			[ 'timeout' => 10 ]
		);
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : null;
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
		$url  = 'https://api.ssllabs.com/api/v3/analyze?host=' . rawurlencode( $domain ) . '&fromCache=on&maxAge=24&all=done';
		$resp = wp_remote_get( $url, [ 'timeout' => 12 ] );
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : null;
	}

	private function parse_ssl( array $ssl ): array {
		$endpoints = [];
		foreach ( $ssl['endpoints'] ?? [] as $ep ) {
			$endpoints[] = [
				'ip'      => $ep['ipAddress']    ?? null,
				'grade'   => $ep['grade']         ?? 'N/A',
				'status'  => $ep['statusMessage'] ?? 'Unknown',
				'has_warnings' => ! empty( $ep['hasWarnings'] ),
			];
		}

		$best_grade = 'N/A';
		foreach ( $endpoints as $ep ) {
			if ( $ep['grade'] !== 'N/A' ) { $best_grade = $ep['grade']; break; }
		}

		return [
			'label'     => 'SSL / TLS',
			'status'    => $ssl['status'] ?? 'UNKNOWN',
			'grade'     => $best_grade,
			'endpoints' => $endpoints,
			'protocol'  => $ssl['protocol'] ?? null,
		];
	}

	/* ── Geolocation ────────────────────────────────────── */

	public function fetch_geo( string $target ): ?array {
		$resp = wp_remote_get(
			'http://ip-api.com/json/' . rawurlencode( $target ) . '?fields=status,country,countryCode,regionName,city,zip,lat,lon,isp,org,as,query,hosting',
			[ 'timeout' => 6 ]
		);
		if ( is_wp_error( $resp ) ) return null;
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
	 */
	public function compute_risk( array $sources ): array {
		$score    = 0;
		$factors  = [];
		$verdict  = 'unknown';

		// SSL grade
		$ssl_grade = $sources['ssl']['grade'] ?? 'N/A';
		$grade_map = [ 'A+' => 0, 'A' => 5, 'A-' => 8, 'B' => 20, 'C' => 35, 'D' => 50, 'E' => 65, 'F' => 80, 'T' => 70, 'M' => 60, 'N/A' => 15 ];
		$ssl_risk  = $grade_map[ $ssl_grade ] ?? 15;
		$score    += $ssl_risk;
		if ( $ssl_risk > 0 ) {
			$factors[] = [ 'factor' => 'SSL Grade', 'value' => $ssl_grade, 'risk' => $ssl_risk, 'weight' => 'medium' ];
		}

		// Domain age
		$age_days = $sources['rdap']['age_days'] ?? null;
		if ( $age_days !== null ) {
			if ( $age_days < 30 )       { $score += 30; $factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 30, 'weight' => 'high' ]; }
			elseif ( $age_days < 180 )  { $score += 15; $factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 15, 'weight' => 'medium' ]; }
			elseif ( $age_days < 365 )  { $score += 5;  $factors[] = [ 'factor' => 'Domain Age', 'value' => "{$age_days}d", 'risk' => 5,  'weight' => 'low' ]; }
		}

		// VirusTotal
		if ( isset( $sources['virustotal'] ) ) {
			$mal = (int) $sources['virustotal']['malicious'];
			$sus = (int) $sources['virustotal']['suspicious'];
			if ( $mal > 5 )      { $score += 40; $factors[] = [ 'factor' => 'VirusTotal Detections', 'value' => "{$mal} malicious", 'risk' => 40, 'weight' => 'critical' ]; }
			elseif ( $mal > 0 )  { $score += 25; $factors[] = [ 'factor' => 'VirusTotal Detections', 'value' => "{$mal} malicious", 'risk' => 25, 'weight' => 'high' ]; }
			elseif ( $sus > 0 )  { $score += 10; $factors[] = [ 'factor' => 'VirusTotal Suspicious', 'value' => "{$sus} suspicious", 'risk' => 10, 'weight' => 'medium' ]; }
		}

		// Shodan open ports / vulns
		if ( isset( $sources['shodan'] ) ) {
			$vulns = count( $sources['shodan']['vulns'] ?? [] );
			$ports = count( $sources['shodan']['ports'] ?? [] );
			if ( $vulns > 0 )   { $score += min( 30, $vulns * 8 ); $factors[] = [ 'factor' => 'Known CVEs', 'value' => "{$vulns} CVEs", 'risk' => min( 30, $vulns * 8 ), 'weight' => 'critical' ]; }
			if ( $ports > 20 )  { $score += 10; $factors[] = [ 'factor' => 'Open Ports', 'value' => "{$ports} ports", 'risk' => 10, 'weight' => 'medium' ]; }
		}

		// Hosting flag
		if ( ! empty( $sources['geolocation']['hosting'] ) ) {
			$score += 5;
			$factors[] = [ 'factor' => 'Hosting Provider IP', 'value' => 'Yes', 'risk' => 5, 'weight' => 'low' ];
		}

		$score = min( 100, max( 0, $score ) );

		if ( $score >= 75 )      $verdict = 'critical';
		elseif ( $score >= 50 )  $verdict = 'high';
		elseif ( $score >= 25 )  $verdict = 'medium';
		elseif ( $score >= 10 )  $verdict = 'low';
		else                     $verdict = 'clean';

		return [
			'score'   => $score,
			'verdict' => $verdict,
			'factors' => $factors,
			'label'   => ucfirst( $verdict ),
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
	public function fetch_attack_surface( string $target ): array {
		$target = trim( $target );
		$result = [
			'target'   => $target,
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
				$dns_resp = wp_remote_get(
					'https://dns.google/resolve?name=' . rawurlencode( $target ) . '&type=' . $type,
					[ 'timeout' => 6, 'headers' => [ 'Accept' => 'application/dns-json' ] ]
				);
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
