<?php
/**
 * PDX_Audit — structured audit log for all platform operations.
 *
 * Table: {prefix}pdx_audit
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   ts          DATETIME NOT NULL
 *   actor_id    BIGINT UNSIGNED NOT NULL DEFAULT 0   (WP user ID; 0 = guest)
 *   actor_email VARCHAR(200) NOT NULL DEFAULT ''
 *   actor_ip    VARCHAR(45)  NOT NULL DEFAULT ''
 *   module      VARCHAR(80)  NOT NULL
 *   action      VARCHAR(120) NOT NULL
 *   severity    ENUM('info','warn','error','critical') NOT NULL DEFAULT 'info'
 *   payload     LONGTEXT     DEFAULT NULL  (JSON)
 *   session_id  VARCHAR(64)  DEFAULT NULL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Audit {

	const TABLE    = 'pdx_audit';
	const MAX_ROWS = 50000;

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts          DATETIME        NOT NULL,
			actor_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_email VARCHAR(200)    NOT NULL DEFAULT '',
			actor_ip    VARCHAR(45)     NOT NULL DEFAULT '',
			module      VARCHAR(80)     NOT NULL,
			action      VARCHAR(120)    NOT NULL,
			severity    VARCHAR(20)     NOT NULL DEFAULT 'info',
			payload     LONGTEXT        DEFAULT NULL,
			session_id  VARCHAR(64)     DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_ts       (ts),
			KEY idx_module   (module),
			KEY idx_actor    (actor_id),
			KEY idx_severity (severity)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ── Write ──────────────────────────────────────────── */

	public static function log(
		string $module,
		string $action,
		array  $payload  = [],
		string $severity = 'info'
	): int|false {
		global $wpdb;

		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$email   = $user_id ? ( get_userdata( $user_id )->user_email ?? '' ) : '';
		$ip      = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
		$session = sanitize_text_field( $_COOKIE['pdx_guest'] ?? session_id() ?: '' );

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			[
				'ts'          => current_time( 'mysql' ),
				'actor_id'    => $user_id,
				'actor_email' => sanitize_email( $email ),
				'actor_ip'    => $ip,
				'module'      => sanitize_key( $module ),
				'action'      => sanitize_text_field( $action ),
				'severity'    => in_array( $severity, [ 'info', 'warn', 'error', 'critical' ], true ) ? $severity : 'info',
				'payload'     => ! empty( $payload ) ? wp_json_encode( $payload ) : null,
				'session_id'  => $session ?: null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		// Prune oldest rows when table exceeds limit
		if ( $inserted && $wpdb->insert_id % 500 === 0 ) {
			self::prune();
		}

		return $inserted ? $wpdb->insert_id : false;
	}

	/* ── Read ───────────────────────────────────────────── */

	public static function get_recent(
		int    $limit    = 100,
		int    $offset   = 0,
		string $module   = '',
		string $severity = '',
		string $search   = ''
	): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$where  = [];
		$values = [];

		if ( $module ) {
			$where[]  = 'module = %s';
			$values[] = $module;
		}
		if ( $severity ) {
			$where[]  = 'severity = %s';
			$values[] = $severity;
		}
		if ( $search ) {
			$where[]  = '(action LIKE %s OR actor_email LIKE %s OR actor_ip LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$values[]  = $limit;
		$values[]  = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY ts DESC LIMIT %d OFFSET %d",
				...$values
			),
			ARRAY_A
		) ?: [];
	}

	public static function count( string $module = '', string $severity = '' ): int {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$where  = [];
		$values = [];

		if ( $module ) { $where[] = 'module = %s'; $values[] = $module; }
		if ( $severity ) { $where[] = 'severity = %s'; $values[] = $severity; }

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";

		return (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) ) : $wpdb->get_var( $sql ) );
	}

	public static function stats_by_module(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT module, COUNT(*) as total,
			        SUM(severity='error') as errors,
			        SUM(severity='warn') as warnings,
			        MAX(ts) as last_seen
			 FROM {$wpdb->prefix}" . self::TABLE . "
			 GROUP BY module ORDER BY total DESC",
			ARRAY_A
		) ?: [];
	}

	public static function stats_by_hour( int $hours = 24 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(ts, '%%Y-%%m-%%d %%H:00:00') as hour, COUNT(*) as total
				 FROM {$wpdb->prefix}" . self::TABLE . "
				 WHERE ts >= DATE_SUB(NOW(), INTERVAL %d HOUR)
				 GROUP BY hour ORDER BY hour ASC",
				$hours
			),
			ARRAY_A
		) ?: [];
	}

	/* ── Maintenance ────────────────────────────────────── */

	public static function prune( int $keep = self::MAX_ROWS ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > $keep ) {
			$delete = $count - $keep;
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} ORDER BY ts ASC LIMIT %d",
				$delete
			) );
		}
	}

	public static function clear_module( string $module ): int {
		global $wpdb;
		return (int) $wpdb->delete(
			$wpdb->prefix . self::TABLE,
			[ 'module' => sanitize_key( $module ) ],
			[ '%s' ]
		);
	}
}
