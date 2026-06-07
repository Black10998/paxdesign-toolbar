<?php
/**
 * PDX_Queue — persistent async job queue for long-running operations.
 *
 * Table: {prefix}pdx_queue
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   job_id      VARCHAR(64)  NOT NULL UNIQUE   (UUID-style)
 *   module      VARCHAR(80)  NOT NULL
 *   job_type    VARCHAR(80)  NOT NULL
 *   status      ENUM('queued','running','done','failed','cancelled') DEFAULT 'queued'
 *   priority    TINYINT UNSIGNED NOT NULL DEFAULT 5  (1=highest, 10=lowest)
 *   payload     LONGTEXT     DEFAULT NULL  (JSON input)
 *   result      LONGTEXT     DEFAULT NULL  (JSON output)
 *   error       TEXT         DEFAULT NULL
 *   progress    TINYINT UNSIGNED NOT NULL DEFAULT 0  (0-100)
 *   user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0
 *   session_id  VARCHAR(64)  DEFAULT NULL
 *   attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0
 *   queued_at   DATETIME     NOT NULL
 *   started_at  DATETIME     DEFAULT NULL
 *   finished_at DATETIME     DEFAULT NULL
 *   expires_at  DATETIME     DEFAULT NULL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Queue {

	const TABLE    = 'pdx_queue';
	const MAX_ROWS = 10000;

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id      VARCHAR(64)     NOT NULL,
			module      VARCHAR(80)     NOT NULL,
			job_type    VARCHAR(80)     NOT NULL,
			status      VARCHAR(20)     NOT NULL DEFAULT 'queued',
			priority    TINYINT UNSIGNED NOT NULL DEFAULT 5,
			payload     LONGTEXT        DEFAULT NULL,
			result      LONGTEXT        DEFAULT NULL,
			error       TEXT            DEFAULT NULL,
			progress    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_id  VARCHAR(64)     DEFAULT NULL,
			attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			queued_at   DATETIME        NOT NULL,
			started_at  DATETIME        DEFAULT NULL,
			finished_at DATETIME        DEFAULT NULL,
			expires_at  DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_job_id  (job_id),
			KEY idx_status   (status),
			KEY idx_module   (module),
			KEY idx_user     (user_id),
			KEY idx_priority (priority, queued_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ── Write ──────────────────────────────────────────── */

	/**
	 * Enqueue a new job. Returns the job_id.
	 */
	public static function enqueue(
		string $module,
		string $job_type,
		array  $payload  = [],
		int    $priority = 5,
		int    $ttl      = 3600
	): string {
		global $wpdb;

		$job_id    = self::generate_id();
		$user_id   = is_user_logged_in() ? get_current_user_id() : 0;
		$session   = class_exists( 'PDX_Security', false )
			? PDX_Security::ensure_guest_session()
			: sanitize_text_field( $_COOKIE['pdx_guest'] ?? '' );
		$now       = current_time( 'mysql' );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			[
				'job_id'     => $job_id,
				'module'     => sanitize_key( $module ),
				'job_type'   => sanitize_key( $job_type ),
				'status'     => 'queued',
				'priority'   => max( 1, min( 10, $priority ) ),
				'payload'    => wp_json_encode( $payload ),
				'user_id'    => $user_id,
				'session_id' => $session ?: null,
				'queued_at'  => $now,
				'expires_at' => $expires,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
		);

		return $job_id;
	}

	/**
	 * Mark a job as running and increment attempt counter.
	 */
	public static function start( string $job_id ): bool {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}" . self::TABLE . "
				 SET status='running', started_at=%s, attempts=attempts+1
				 WHERE job_id=%s AND status IN ('queued','failed')",
				current_time( 'mysql' ), $job_id
			)
		);
	}

	/**
	 * Mark a job as complete with result data.
	 */
	public static function complete( string $job_id, array $result ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			[
				'status'      => 'done',
				'result'      => wp_json_encode( $result ),
				'progress'    => 100,
				'finished_at' => current_time( 'mysql' ),
			],
			[ 'job_id' => $job_id ],
			[ '%s', '%s', '%d', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Mark a job as failed.
	 */
	public static function fail( string $job_id, string $error ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			[
				'status'      => 'failed',
				'error'       => $error,
				'finished_at' => current_time( 'mysql' ),
			],
			[ 'job_id' => $job_id ],
			[ '%s', '%s', '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Update progress percentage (0-100).
	 */
	public static function update_progress( string $job_id, int $pct ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			[ 'progress' => max( 0, min( 100, $pct ) ) ],
			[ 'job_id' => $job_id ],
			[ '%d' ], [ '%s' ]
		);
	}

	/* ── Read ───────────────────────────────────────────── */

	public static function get( string $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE job_id = %s LIMIT 1",
				$job_id
			),
			ARRAY_A
		);
		if ( ! $row ) return null;
		if ( $row['payload'] ) $row['payload'] = json_decode( $row['payload'], true );
		if ( $row['result']  ) $row['result']  = json_decode( $row['result'],  true );
		return $row;
	}

	public static function user_can_access( string $job_id ): bool {
		$job = self::get( $job_id );
		return $job ? PDX_Security::actor_owns_row( $job ) : false;
	}

	/**
	 * Get jobs for the current user/session.
	 */
	public static function get_user_jobs(
		string $module = '',
		int    $limit  = 20,
		int    $offset = 0
	): array {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$session = class_exists( 'PDX_Security', false )
			? PDX_Security::ensure_guest_session()
			: sanitize_text_field( $_COOKIE['pdx_guest'] ?? '' );

		$where  = [];
		$values = [];

		if ( $user_id ) {
			$where[]  = 'user_id = %d';
			$values[] = $user_id;
		} elseif ( $session ) {
			$where[]  = 'session_id = %s';
			$values[] = $session;
		} else {
			return [];
		}

		if ( $module ) {
			$where[]  = 'module = %s';
			$values[] = $module;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$values[]  = $limit;
		$values[]  = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, module, job_type, status, progress, error, queued_at, started_at, finished_at
				 FROM {$table} {$where_sql}
				 ORDER BY queued_at DESC LIMIT %d OFFSET %d",
				...$values
			),
			ARRAY_A
		) ?: [];

		return $rows;
	}

	/**
	 * Get next queued job for processing (admin/cron use).
	 */
	public static function next_queued( string $module = '' ): ?array {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$where  = "status = 'queued' AND (expires_at IS NULL OR expires_at > NOW())";
		$values = [];

		if ( $module ) {
			$where   .= ' AND module = %s';
			$values[] = $module;
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY priority ASC, queued_at ASC LIMIT 1";
		$row = $values
			? $wpdb->get_row( $wpdb->prepare( $sql, ...$values ), ARRAY_A )
			: $wpdb->get_row( $sql, ARRAY_A );

		if ( ! $row ) return null;
		if ( $row['payload'] ) $row['payload'] = json_decode( $row['payload'], true );
		return $row;
	}

	public static function queue_stats(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT module, status, COUNT(*) as total
			 FROM {$wpdb->prefix}" . self::TABLE . "
			 GROUP BY module, status",
			ARRAY_A
		) ?: [];
	}

	/* ── Maintenance ────────────────────────────────────── */

	public static function prune_expired(): int {
		global $wpdb;
		return (int) $wpdb->query(
			"DELETE FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE expires_at IS NOT NULL AND expires_at < NOW()
			 AND status IN ('done','failed','cancelled')"
		);
	}

	/* ── Helpers ────────────────────────────────────────── */

	private static function generate_id(): string {
		return sprintf(
			'pdx-%s-%s',
			base_convert( (string) time(), 10, 36 ),
			substr( bin2hex( random_bytes( 8 ) ), 0, 12 )
		);
	}
}
