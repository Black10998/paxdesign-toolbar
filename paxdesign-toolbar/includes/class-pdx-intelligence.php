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
}
