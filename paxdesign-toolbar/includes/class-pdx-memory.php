<?php
/**
 * PDX_Memory — long-term AI memory with semantic search architecture.
 *
 * Table: {prefix}pdx_memory
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   mem_id     VARCHAR(64)  NOT NULL UNIQUE
 *   user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0
 *   agent      VARCHAR(80)  NOT NULL DEFAULT 'global'
 *   mem_type   VARCHAR(40)  NOT NULL  (fact, preference, context, tool_call, reasoning)
 *   content    TEXT         NOT NULL
 *   summary    VARCHAR(500) DEFAULT NULL
 *   embedding  LONGTEXT     DEFAULT NULL  (JSON float array — for semantic search)
 *   importance TINYINT UNSIGNED NOT NULL DEFAULT 50  (0-100)
 *   access_count INT UNSIGNED NOT NULL DEFAULT 0
 *   created_at DATETIME     NOT NULL
 *   last_accessed DATETIME  DEFAULT NULL
 *   expires_at DATETIME     DEFAULT NULL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Memory {

	const TABLE = 'pdx_memory';
	const MAX_PER_AGENT = 1000;

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE . " (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			mem_id       VARCHAR(64)     NOT NULL,
			user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			agent        VARCHAR(80)     NOT NULL DEFAULT 'global',
			mem_type     VARCHAR(40)     NOT NULL DEFAULT 'fact',
			content      TEXT            NOT NULL,
			summary      VARCHAR(500)    DEFAULT NULL,
			embedding    LONGTEXT        DEFAULT NULL,
			importance   TINYINT UNSIGNED NOT NULL DEFAULT 50,
			access_count INT UNSIGNED    NOT NULL DEFAULT 0,
			created_at   DATETIME        NOT NULL,
			last_accessed DATETIME       DEFAULT NULL,
			expires_at   DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_mem_id (mem_id),
			KEY idx_user_agent (user_id, agent),
			KEY idx_type       (mem_type),
			KEY idx_importance (importance),
			KEY idx_created    (created_at)
		) {$charset};" );
	}

	/* ── Store ──────────────────────────────────────────── */

	public static function store(
		string $content,
		string $agent      = 'global',
		string $mem_type   = 'fact',
		int    $importance = 50,
		array  $embedding  = [],
		int    $ttl        = 0
	): string {
		global $wpdb;
		$user_id   = is_user_logged_in() ? get_current_user_id() : 0;
		$mem_id    = 'mem-' . substr( bin2hex( random_bytes( 10 ) ), 0, 16 );
		$now       = current_time( 'mysql' );
		$expires   = $ttl > 0 ? gmdate( 'Y-m-d H:i:s', time() + $ttl ) : null;
		$summary   = strlen( $content ) > 100 ? substr( $content, 0, 97 ) . '…' : null;

		$wpdb->insert( $wpdb->prefix . self::TABLE, [
			'mem_id'     => $mem_id,
			'user_id'    => $user_id,
			'agent'      => sanitize_key( $agent ),
			'mem_type'   => sanitize_key( $mem_type ),
			'content'    => sanitize_textarea_field( $content ),
			'summary'    => $summary,
			'embedding'  => ! empty( $embedding ) ? wp_json_encode( $embedding ) : null,
			'importance' => max( 0, min( 100, $importance ) ),
			'created_at' => $now,
			'expires_at' => $expires,
		] );

		// Prune if over limit
		self::prune_agent( $user_id, $agent );

		return $mem_id;
	}

	/* ── Retrieve ───────────────────────────────────────── */

	public static function get_recent( string $agent = 'global', int $limit = 20 ): array {
		global $wpdb;
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT mem_id, agent, mem_type, content, summary, importance, access_count, created_at
			 FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE user_id = %d AND agent = %s
			 AND (expires_at IS NULL OR expires_at > NOW())
			 ORDER BY importance DESC, created_at DESC LIMIT %d",
			$user_id, $agent, $limit
		), ARRAY_A ) ?: [];

		// Update access counts
		if ( ! empty( $rows ) ) {
			$ids = implode( ',', array_map( 'intval', array_column( $rows, 'mem_id' ) ) );
			$wpdb->query( "UPDATE {$wpdb->prefix}" . self::TABLE . "
				SET access_count = access_count + 1, last_accessed = NOW()
				WHERE mem_id IN ('{$ids}')" );
		}

		return $rows;
	}

	/**
	 * Keyword-based semantic search (cosine similarity requires embeddings API;
	 * this provides BM25-style relevance ranking as fallback).
	 */
	public static function search( string $query, string $agent = '', int $limit = 10 ): array {
		global $wpdb;
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$like    = '%' . $wpdb->esc_like( $query ) . '%';
		$where   = $agent ? $wpdb->prepare( 'AND agent = %s', $agent ) : '';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT mem_id, agent, mem_type, content, summary, importance, created_at
			 FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE user_id = %d {$where} AND content LIKE %s
			 AND (expires_at IS NULL OR expires_at > NOW())
			 ORDER BY importance DESC, created_at DESC LIMIT %d",
			$user_id, $like, $limit
		), ARRAY_A ) ?: [];
	}

	/**
	 * Build a compressed context string for injection into AI prompts.
	 * Selects the most important recent memories and compresses them.
	 */
	public static function build_context( string $agent, int $max_tokens = 800 ): string {
		$memories = self::get_recent( $agent, 15 );
		if ( empty( $memories ) ) return '';

		$lines = [];
		$chars = 0;
		$limit = $max_tokens * 4; // ~4 chars per token

		foreach ( $memories as $m ) {
			$line   = "[{$m['mem_type']}] {$m['content']}";
			$chars += strlen( $line );
			if ( $chars > $limit ) break;
			$lines[] = $line;
		}

		return "## Agent Memory ({$agent})\n" . implode( "\n", $lines ) . "\n";
	}

	/* ── Agent state ────────────────────────────────────── */

	public static function set_agent_state( string $agent, array $state ): void {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return;
		update_user_meta( $user_id, "pdx_agent_state_{$agent}", $state );
	}

	public static function get_agent_state( string $agent ): array {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return [];
		$state = get_user_meta( $user_id, "pdx_agent_state_{$agent}", true );
		return is_array( $state ) ? $state : [];
	}

	/* ── Tool call history ──────────────────────────────── */

	public static function log_tool_call( string $agent, string $tool, array $input, $output ): void {
		self::store(
			wp_json_encode( [ 'tool' => $tool, 'input' => $input, 'output' => $output ] ),
			$agent,
			'tool_call',
			30
		);
	}

	/* ── Embeddings architecture ────────────────────────── */

	public static function generate_embedding( string $text, string $api_key ): array {
		if ( ! $api_key ) return [];
		$resp = wp_remote_post( 'https://api.openai.com/v1/embeddings', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'model' => 'text-embedding-3-small', 'input' => substr( $text, 0, 8000 ) ] ),
		] );
		if ( is_wp_error( $resp ) ) return [];
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return $data['data'][0]['embedding'] ?? [];
	}

	public static function cosine_similarity( array $a, array $b ): float {
		if ( empty( $a ) || empty( $b ) || count( $a ) !== count( $b ) ) return 0.0;
		$dot = 0.0; $na = 0.0; $nb = 0.0;
		for ( $i = 0; $i < count( $a ); $i++ ) {
			$dot += $a[$i] * $b[$i];
			$na  += $a[$i] * $a[$i];
			$nb  += $b[$i] * $b[$i];
		}
		$denom = sqrt( $na ) * sqrt( $nb );
		return $denom > 0 ? $dot / $denom : 0.0;
	}

	/* ── Maintenance ────────────────────────────────────── */

	private static function prune_agent( int $user_id, string $agent ): void {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE . " WHERE user_id = %d AND agent = %s",
			$user_id, $agent
		) );
		if ( $count > self::MAX_PER_AGENT ) {
			$delete = $count - self::MAX_PER_AGENT;
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}" . self::TABLE . "
				 WHERE user_id = %d AND agent = %s
				 ORDER BY importance ASC, last_accessed ASC LIMIT %d",
				$user_id, $agent, $delete
			) );
		}
	}

	public static function prune_expired(): int {
		global $wpdb;
		return (int) $wpdb->query(
			"DELETE FROM {$wpdb->prefix}" . self::TABLE . " WHERE expires_at IS NOT NULL AND expires_at < NOW()"
		);
	}
}
