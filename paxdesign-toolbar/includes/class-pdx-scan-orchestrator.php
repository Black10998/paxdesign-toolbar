<?php
/**
 * Unified intelligence scan orchestration — TrustCheck, OSINT, ingest, forensics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-pdx-phishing-heuristics.php';

class PDX_Scan_Orchestrator {

	public function __construct(
		private PDX_Intelligence $intel,
		private PDX_Settings $settings
	) {}

	/**
	 * Deep platform scan (v8).
	 *
	 * @return array<string, mixed>
	 */
	public function run( string $raw_target, bool $paid, string $module = 'trust' ): array {
		$cache_key_target = strtolower( trim( $raw_target ) ) . '|' . ( $paid ? '1' : '0' );
		$cached           = PDX_Cache::get_scan( $cache_key_target, $module );
		if ( is_array( $cached ) && ! empty( $cached['target'] ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$report = $this->intel->full_scan( $raw_target, $paid );

		if ( empty( $report['target'] ) || ! empty( $report['source_status']['normalize']['state'] ) ) {
			return $report;
		}

		$resolved = is_array( $report['target_meta'] ?? null ) ? $report['target_meta'] : [];

		// URL forensics (redirects, HTML, phishing heuristics).
		$url_forensics = PDX_Url_Analyzer::analyze( $raw_target, $resolved );
		$report['sources']['url_forensics']     = $url_forensics;
		$report['source_status']['url_forensics'] = $url_forensics['status'] ?? [ 'state' => 'skipped' ];

		// Infrastructure correlation block.
		$report['forensics'] = $this->build_forensics( $report, $url_forensics );
		$report['behavioral_signals'] = PDX_Intelligence::behavioral_score( $report );
		$report['infrastructure']     = PDX_Phishing_Heuristics::infrastructure_fingerprint( $report );
		$report['forensics']['infrastructure_score']      = (int) ( $report['infrastructure']['score'] ?? 0 );
		$report['forensics']['infrastructure_fingerprint'] = (string) ( $report['infrastructure']['fingerprint'] ?? '' );
		$report['forensics']['infrastructure_relationships'] = (array) ( $report['infrastructure']['relationships'] ?? [] );

		// Re-score with forensic factors.
		$report['risk'] = $this->intel->compute_risk(
			$report['sources'],
			$report['source_status'],
			$report['forensics']
		);

		$narrative = $this->intel->build_narrative( $report['target'], $report );
		$report['ai_summary']      = $narrative['summary'];
		$report['recommendations'] = $narrative['recommendations'];
		$report['module']          = $module;
		$report['engine']          = 'pdx-v8';

		// Persist IOCs for investigation graph / threat intel.
		if ( class_exists( 'PDX_Correlation', false ) ) {
			PDX_Correlation::ingest_report( $report );
		}

		// Extended timeline from forensics + scan.
		$report['timeline'] = array_merge(
			$report['timeline'] ?? [],
			$this->forensic_timeline( $report )
		);
		usort(
			$report['timeline'],
			static fn( $a, $b ) => strcmp( (string) ( $b['ts'] ?? '' ), (string) ( $a['ts'] ?? '' ) )
		);

		PDX_Audit::log( $module, 'deep_scan_completed', [
			'target'  => $report['target'],
			'score'   => $report['risk']['score'] ?? 0,
			'verdict' => $report['risk']['verdict'] ?? '',
		] );

		PDX_Cache::set_scan( $cache_key_target, $module, $report, 3600 );

		return $report;
	}

	/**
	 * @param array<string, mixed> $report
	 * @param array<string, mixed> $url_forensics
	 * @return array<string, mixed>
	 */
	private function build_forensics( array $report, array $url_forensics ): array {
		$sources = $report['sources'] ?? [];
		$dns     = $sources['dns'] ?? [];
		$geo     = $sources['geo'] ?? $sources['geolocation'] ?? [];
		$rdap    = $sources['rdap'] ?? [];

		$phish = $url_forensics['phishing'] ?? [];

		return [
			'redirect_hops'       => (int) ( $url_forensics['redirect_count'] ?? 0 ),
			'redirect_intent'     => (string) ( $phish['redirect_intent'] ?? $url_forensics['redirect_intent']['intent'] ?? 'direct' ),
			'phishing_score'      => (int) ( $phish['score'] ?? 0 ),
			'phishing_verdict'    => $phish['verdict'] ?? 'low',
			'phishing_reasons'    => $phish['reasons'] ?? [],
			'path_risk_score'     => (int) ( $url_forensics['target_heuristics']['score'] ?? 0 ),
			'landing_risk_score'  => (int) ( $url_forensics['landing_heuristics']['score'] ?? 0 ),
			'has_login_form'      => ! empty( $url_forensics['page_signals']['password_fields'] ),
			'has_form_bait'       => ! empty( $url_forensics['page_signals']['forms'] ) && ! empty( $url_forensics['page_signals']['password_fields'] ),
			'external_form_action'=> ! empty( $url_forensics['page_signals']['external_form_action'] ),
			'malware_indicators'  => (array) ( $phish['malware_indicators'] ?? [] ),
			'asn'                 => $geo['asn'] ?? null,
			'org'                 => $geo['org'] ?? $geo['isp'] ?? null,
			'registrar'           => $rdap['registrar'] ?? null,
			'mx_configured'       => ! empty( $dns['mx'] ),
			'spf_configured'      => ! empty( $dns['spf'] ),
			'dmarc_configured'    => ! empty( $dns['dmarc'] ),
		];
	}

	/**
	 * @param array<string, mixed> $report
	 * @return list<array<string, mixed>>
	 */
	private function forensic_timeline( array $report ): array {
		$events = [];
		$now    = gmdate( 'c' );

		$events[] = [
			'ts'     => $now,
			'event'  => 'deep_scan_completed',
			'source' => 'platform',
			'detail' => 'Engine v8 forensic pass',
		];

		foreach ( $report['sources']['url_forensics']['redirect_chain'] ?? [] as $i => $hop ) {
			$events[] = [
				'ts'     => $now,
				'event'  => 'http_redirect',
				'source' => 'url_forensics',
				'detail' => sprintf( 'Hop %d: HTTP %s → %s', $i + 1, $hop['code'] ?? '?', $hop['url'] ?? '' ),
			];
		}

		return $events;
	}
}
