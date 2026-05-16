<?php
/**
 * PDX_Correlation — cross-source intelligence correlation engine.
 *
 * Correlates IOCs across scan results, builds entity relationship graphs,
 * clusters threats, scores confidence, and generates AI investigation summaries.
 *
 * Table: {prefix}pdx_iocs
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   ioc_type   VARCHAR(40)  NOT NULL  (domain, ip, email, hash, url, asn, org)
 *   ioc_value  VARCHAR(500) NOT NULL
 *   source     VARCHAR(80)  NOT NULL
 *   confidence TINYINT UNSIGNED NOT NULL DEFAULT 50  (0-100)
 *   severity   VARCHAR(20)  NOT NULL DEFAULT 'medium'
 *   tags       VARCHAR(500) DEFAULT NULL
 *   first_seen DATETIME     NOT NULL
 *   last_seen  DATETIME     NOT NULL
 *   scan_count INT UNSIGNED NOT NULL DEFAULT 1
 *   meta       LONGTEXT     DEFAULT NULL  (JSON)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Correlation {

	const T_IOCS = 'pdx_iocs';

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_IOCS . " (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ioc_type   VARCHAR(40)     NOT NULL,
			ioc_value  VARCHAR(500)    NOT NULL,
			source     VARCHAR(80)     NOT NULL,
			confidence TINYINT UNSIGNED NOT NULL DEFAULT 50,
			severity   VARCHAR(20)     NOT NULL DEFAULT 'medium',
			tags       VARCHAR(500)    DEFAULT NULL,
			first_seen DATETIME        NOT NULL,
			last_seen  DATETIME        NOT NULL,
			scan_count INT UNSIGNED    NOT NULL DEFAULT 1,
			meta       LONGTEXT        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_ioc (ioc_type, ioc_value(200), source),
			KEY idx_type      (ioc_type),
			KEY idx_severity  (severity),
			KEY idx_confidence(confidence),
			KEY idx_last_seen (last_seen)
		) {$charset};" );
	}

	/* ── IOC ingestion ──────────────────────────────────── */

	public static function ingest( string $type, string $value, string $source, int $confidence = 50, string $severity = 'medium', array $meta = [], array $tags = [] ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::T_IOCS;
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, scan_count, confidence FROM {$table} WHERE ioc_type=%s AND ioc_value=%s AND source=%s",
			$type, $value, $source
		), ARRAY_A );

		if ( $existing ) {
			$wpdb->update( $table, [
				'last_seen'  => $now,
				'scan_count' => (int) $existing['scan_count'] + 1,
				'confidence' => min( 100, max( (int) $existing['confidence'], $confidence ) ),
				'severity'   => $severity,
				'meta'       => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			], [ 'id' => $existing['id'] ] );
		} else {
			$wpdb->insert( $table, [
				'ioc_type'   => sanitize_key( $type ),
				'ioc_value'  => sanitize_text_field( $value ),
				'source'     => sanitize_key( $source ),
				'confidence' => max( 0, min( 100, $confidence ) ),
				'severity'   => $severity,
				'tags'       => ! empty( $tags ) ? implode( ',', array_map( 'sanitize_key', $tags ) ) : null,
				'first_seen' => $now,
				'last_seen'  => $now,
				'scan_count' => 1,
				'meta'       => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			] );
		}
	}

	/**
	 * Ingest all IOCs from a full intelligence report.
	 */
	public static function ingest_report( array $report ): void {
		$target  = $report['target'] ?? '';
		$sources = $report['sources'] ?? [];

		// Domain IOC
		if ( $target ) {
			$severity = 'info';
			$conf     = 80;
			if ( isset( $report['risk']['score'] ) ) {
				$score    = (int) $report['risk']['score'];
				$severity = $score >= 75 ? 'critical' : ( $score >= 50 ? 'high' : ( $score >= 25 ? 'medium' : 'low' ) );
				$conf     = min( 100, 50 + $score );
			}
			self::ingest( 'domain', $target, 'platform', $conf, $severity, $report['risk'] ?? [] );
		}

		// IP from geolocation
		if ( ! empty( $sources['geolocation']['ip'] ) ) {
			self::ingest( 'ip', $sources['geolocation']['ip'], 'ip-api', 70, 'info', $sources['geolocation'] );
		}

		// VirusTotal IOCs
		if ( ! empty( $sources['virustotal'] ) ) {
			$vt  = $sources['virustotal'];
			$mal = (int) ( $vt['malicious'] ?? 0 );
			if ( $mal > 0 ) {
				self::ingest( 'domain', $target, 'virustotal', min( 100, 50 + $mal * 5 ), $mal > 5 ? 'critical' : 'high', $vt, [ 'malicious' ] );
			}
		}

		// Shodan CVEs
		if ( ! empty( $sources['shodan']['vulns'] ) ) {
			foreach ( $sources['shodan']['vulns'] as $cve ) {
				self::ingest( 'cve', $cve, 'shodan', 85, 'high', [ 'target' => $target ], [ 'cve', 'shodan' ] );
			}
		}

		// Email addresses from Hunter
		if ( ! empty( $sources['hunter']['emails'] ) ) {
			foreach ( array_slice( $sources['hunter']['emails'], 0, 5 ) as $e ) {
				if ( ! empty( $e['email'] ) ) {
					self::ingest( 'email', $e['email'], 'hunter', 75, 'info', $e );
				}
			}
		}
	}

	/* ── Correlation ────────────────────────────────────── */

	/**
	 * Find all IOCs related to a given value (same IP, same ASN, same registrar, etc.)
	 */
	public static function correlate( string $ioc_value, string $ioc_type = '' ): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::T_IOCS;
		$where  = $ioc_type ? $wpdb->prepare( 'WHERE ioc_value = %s AND ioc_type = %s', $ioc_value, $ioc_type )
		                    : $wpdb->prepare( 'WHERE ioc_value = %s', $ioc_value );

		$direct = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY confidence DESC", ARRAY_A ) ?: [];

		// Find related IOCs by shared meta fields
		$related = [];
		foreach ( $direct as $ioc ) {
			$meta = json_decode( $ioc['meta'] ?? '{}', true );
			if ( ! empty( $meta['asn'] ) ) {
				$related = array_merge( $related, self::find_by_meta_value( 'asn', $meta['asn'] ) );
			}
			if ( ! empty( $meta['org'] ) ) {
				$related = array_merge( $related, self::find_by_meta_value( 'org', $meta['org'] ) );
			}
		}

		// Deduplicate
		$seen = [];
		$unique_related = [];
		foreach ( $related as $r ) {
			$k = $r['ioc_type'] . ':' . $r['ioc_value'];
			if ( ! isset( $seen[$k] ) && $r['ioc_value'] !== $ioc_value ) {
				$seen[$k] = true;
				$unique_related[] = $r;
			}
		}

		return [
			'target'  => $ioc_value,
			'direct'  => $direct,
			'related' => array_slice( $unique_related, 0, 20 ),
			'graph'   => self::build_graph( $ioc_value, $direct, $unique_related ),
		];
	}

	private static function find_by_meta_value( string $field, string $value ): array {
		global $wpdb;
		$like = '%"' . $wpdb->esc_like( $field ) . '":"' . $wpdb->esc_like( $value ) . '"%';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::T_IOCS . " WHERE meta LIKE %s LIMIT 10",
			$like
		), ARRAY_A ) ?: [];
	}

	/**
	 * Build a graph structure for visualization.
	 */
	public static function build_graph( string $root, array $direct, array $related ): array {
		$nodes = [ [ 'id' => $root, 'type' => 'root', 'label' => $root, 'size' => 20 ] ];
		$edges = [];

		foreach ( $direct as $ioc ) {
			$node_id = $ioc['ioc_type'] . ':' . $ioc['ioc_value'];
			$nodes[] = [
				'id'         => $node_id,
				'type'       => $ioc['ioc_type'],
				'label'      => $ioc['ioc_value'],
				'source'     => $ioc['source'],
				'confidence' => (int) $ioc['confidence'],
				'severity'   => $ioc['severity'],
				'size'       => 12,
			];
			$edges[] = [ 'from' => $root, 'to' => $node_id, 'type' => 'direct', 'weight' => (int) $ioc['confidence'] ];
		}

		foreach ( $related as $ioc ) {
			$node_id = $ioc['ioc_type'] . ':' . $ioc['ioc_value'];
			$nodes[] = [
				'id'         => $node_id,
				'type'       => $ioc['ioc_type'],
				'label'      => $ioc['ioc_value'],
				'source'     => $ioc['source'],
				'confidence' => (int) $ioc['confidence'],
				'severity'   => $ioc['severity'],
				'size'       => 8,
			];
			$edges[] = [ 'from' => $root, 'to' => $node_id, 'type' => 'related', 'weight' => (int) $ioc['confidence'] / 2 ];
		}

		return [ 'nodes' => $nodes, 'edges' => $edges ];
	}

	/* ── Threat clustering ──────────────────────────────── */

	public static function cluster_threats( int $limit = 50 ): array {
		global $wpdb;
		$iocs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::T_IOCS . "
				 WHERE severity IN ('high','critical') AND confidence >= 60
				 ORDER BY last_seen DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: [];

		$clusters = [];
		foreach ( $iocs as $ioc ) {
			$meta = json_decode( $ioc['meta'] ?? '{}', true );
			$key  = $meta['asn'] ?? $meta['org'] ?? $ioc['source'];
			if ( ! isset( $clusters[ $key ] ) ) {
				$clusters[ $key ] = [ 'cluster_key' => $key, 'iocs' => [], 'max_severity' => 'low', 'avg_confidence' => 0 ];
			}
			$clusters[ $key ]['iocs'][] = $ioc;
		}

		// Score each cluster
		foreach ( $clusters as &$cluster ) {
			$confs = array_column( $cluster['iocs'], 'confidence' );
			$cluster['avg_confidence'] = round( array_sum( $confs ) / count( $confs ) );
			$sevs  = array_column( $cluster['iocs'], 'severity' );
			$cluster['max_severity'] = in_array( 'critical', $sevs ) ? 'critical' : ( in_array( 'high', $sevs ) ? 'high' : 'medium' );
			$cluster['ioc_count'] = count( $cluster['iocs'] );
		}

		usort( $clusters, fn( $a, $b ) => $b['avg_confidence'] <=> $a['avg_confidence'] );
		return array_values( $clusters );
	}

	/* ── Timeline reconstruction ────────────────────────── */

	public static function timeline( string $target, int $days = 90 ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $target ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ioc_type, ioc_value, source, severity, confidence, first_seen, last_seen, scan_count
			 FROM {$wpdb->prefix}" . self::T_IOCS . "
			 WHERE (ioc_value LIKE %s OR meta LIKE %s)
			 AND first_seen >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 ORDER BY first_seen ASC",
			$like, $like, $days
		), ARRAY_A ) ?: [];

		return array_map( static function( $r ) {
			return [
				'ts'         => $r['first_seen'],
				'event'      => "IOC discovered: {$r['ioc_type']} via {$r['source']}",
				'value'      => $r['ioc_value'],
				'severity'   => $r['severity'],
				'confidence' => (int) $r['confidence'],
				'seen_count' => (int) $r['scan_count'],
			];
		}, $rows );
	}

	/* ── AI investigation summary ───────────────────────── */

	public static function ai_summary( string $target, array $correlation_data, string $api_key ): string {
		if ( ! $api_key ) return '';

		$ioc_count = count( $correlation_data['direct'] ?? [] );
		$related   = count( $correlation_data['related'] ?? [] );
		$graph_nodes = count( $correlation_data['graph']['nodes'] ?? [] );

		$context = "Target: {$target}\nDirect IOCs: {$ioc_count}\nRelated entities: {$related}\nGraph nodes: {$graph_nodes}\n\n";

		foreach ( array_slice( $correlation_data['direct'] ?? [], 0, 10 ) as $ioc ) {
			$context .= "- [{$ioc['ioc_type']}] {$ioc['ioc_value']} (source: {$ioc['source']}, confidence: {$ioc['confidence']}%, severity: {$ioc['severity']})\n";
		}

		$prompt = "You are a cyber threat intelligence analyst. Based on the following IOC correlation data, write a concise investigation summary (3-5 sentences) covering: threat level, key findings, infrastructure relationships, and recommended actions.\n\n{$context}";

		$resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'model' => 'gpt-4o-mini', 'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ], 'max_tokens' => 300, 'temperature' => 0.3 ] ),
		] );

		if ( is_wp_error( $resp ) ) return '';
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return $body['choices'][0]['message']['content'] ?? '';
	}

	/* ── Queries ────────────────────────────────────────── */

	public static function search( string $q, int $limit = 20 ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::T_IOCS . " WHERE ioc_value LIKE %s ORDER BY confidence DESC, last_seen DESC LIMIT %d",
			$like, $limit
		), ARRAY_A ) ?: [];
	}

	public static function stats(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT ioc_type, severity, COUNT(*) as total, AVG(confidence) as avg_conf
			 FROM {$wpdb->prefix}" . self::T_IOCS . " GROUP BY ioc_type, severity ORDER BY total DESC",
			ARRAY_A
		) ?: [];
	}
}
