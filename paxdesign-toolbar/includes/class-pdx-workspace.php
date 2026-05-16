<?php
/**
 * PDX_Workspace — persistent saved projects, investigations, and AI memory.
 *
 * Table: {prefix}pdx_workspaces
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   ws_id       VARCHAR(64)  NOT NULL UNIQUE
 *   user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0
 *   session_id  VARCHAR(64)  DEFAULT NULL
 *   module      VARCHAR(80)  NOT NULL
 *   ws_type     VARCHAR(80)  NOT NULL  (scan, investigation, pipeline, builder, chat)
 *   title       VARCHAR(255) NOT NULL DEFAULT 'Untitled'
 *   status      VARCHAR(20)  NOT NULL DEFAULT 'active'  (active, archived, shared)
 *   data        LONGTEXT     DEFAULT NULL  (JSON)
 *   tags        VARCHAR(500) DEFAULT NULL  (comma-separated)
 *   is_pinned   TINYINT(1)   NOT NULL DEFAULT 0
 *   created_at  DATETIME     NOT NULL
 *   updated_at  DATETIME     NOT NULL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Workspace {

	const TABLE    = 'pdx_workspaces';
	const MAX_PER_USER = 500;

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ws_id      VARCHAR(64)     NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_id VARCHAR(64)     DEFAULT NULL,
			module     VARCHAR(80)     NOT NULL,
			ws_type    VARCHAR(80)     NOT NULL,
			title      VARCHAR(255)    NOT NULL DEFAULT 'Untitled',
			status     VARCHAR(20)     NOT NULL DEFAULT 'active',
			data       LONGTEXT        DEFAULT NULL,
			tags       VARCHAR(500)    DEFAULT NULL,
			is_pinned  TINYINT(1)      NOT NULL DEFAULT 0,
			created_at DATETIME        NOT NULL,
			updated_at DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_ws_id   (ws_id),
			KEY idx_user    (user_id),
			KEY idx_module  (module),
			KEY idx_status  (status),
			KEY idx_updated (updated_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ── Write ──────────────────────────────────────────── */

	public static function create(
		string $module,
		string $ws_type,
		string $title  = 'Untitled',
		array  $data   = [],
		array  $tags   = []
	): string {
		global $wpdb;

		$ws_id     = self::generate_id();
		$user_id   = is_user_logged_in() ? get_current_user_id() : 0;
		$session   = sanitize_text_field( $_COOKIE['pdx_guest'] ?? '' );
		$now       = current_time( 'mysql' );

		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			[
				'ws_id'      => $ws_id,
				'user_id'    => $user_id,
				'session_id' => $session ?: null,
				'module'     => sanitize_key( $module ),
				'ws_type'    => sanitize_key( $ws_type ),
				'title'      => sanitize_text_field( $title ),
				'status'     => 'active',
				'data'       => ! empty( $data ) ? wp_json_encode( $data ) : null,
				'tags'       => ! empty( $tags ) ? implode( ',', array_map( 'sanitize_text_field', $tags ) ) : null,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $ws_id;
	}

	public static function update( string $ws_id, array $fields ): bool {
		global $wpdb;

		$allowed = [ 'title', 'status', 'data', 'tags', 'is_pinned' ];
		$update  = [ 'updated_at' => current_time( 'mysql' ) ];
		$formats = [ '%s' ];

		foreach ( $allowed as $f ) {
			if ( ! array_key_exists( $f, $fields ) ) continue;
			if ( $f === 'data' && is_array( $fields[$f] ) ) {
				$update[$f] = wp_json_encode( $fields[$f] );
				$formats[]  = '%s';
			} elseif ( $f === 'tags' && is_array( $fields[$f] ) ) {
				$update[$f] = implode( ',', array_map( 'sanitize_text_field', $fields[$f] ) );
				$formats[]  = '%s';
			} elseif ( $f === 'is_pinned' ) {
				$update[$f] = (int) $fields[$f];
				$formats[]  = '%d';
			} else {
				$update[$f] = sanitize_text_field( $fields[$f] );
				$formats[]  = '%s';
			}
		}

		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			$update,
			[ 'ws_id' => $ws_id ],
			$formats,
			[ '%s' ]
		);
	}

	public static function append_data( string $ws_id, string $key, $value ): bool {
		$ws = self::get( $ws_id );
		if ( ! $ws ) return false;

		$data = $ws['data'] ?? [];
		if ( ! is_array( $data ) ) $data = [];

		if ( ! isset( $data[ $key ] ) ) {
			$data[ $key ] = [];
		}
		if ( is_array( $data[ $key ] ) ) {
			$data[ $key ][] = $value;
			// Keep last 200 entries per key
			$data[ $key ] = array_slice( $data[ $key ], -200 );
		} else {
			$data[ $key ] = $value;
		}

		return self::update( $ws_id, [ 'data' => $data ] );
	}

	/* ── Read ───────────────────────────────────────────── */

	public static function get( string $ws_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE ws_id = %s LIMIT 1",
				$ws_id
			),
			ARRAY_A
		);
		if ( ! $row ) return null;
		if ( $row['data'] ) $row['data'] = json_decode( $row['data'], true );
		if ( $row['tags'] ) $row['tags'] = explode( ',', $row['tags'] );
		return $row;
	}

	public static function get_user_workspaces(
		string $module = '',
		string $status = 'active',
		int    $limit  = 50,
		int    $offset = 0
	): array {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$session = sanitize_text_field( $_COOKIE['pdx_guest'] ?? '' );

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

		if ( $status ) { $where[] = 'status = %s'; $values[] = $status; }
		if ( $module ) { $where[] = 'module = %s'; $values[] = $module; }

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$values[]  = $limit;
		$values[]  = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ws_id, module, ws_type, title, status, tags, is_pinned, created_at, updated_at
				 FROM {$table} {$where_sql}
				 ORDER BY is_pinned DESC, updated_at DESC
				 LIMIT %d OFFSET %d",
				...$values
			),
			ARRAY_A
		) ?: [];

		foreach ( $rows as &$row ) {
			if ( $row['tags'] ) $row['tags'] = explode( ',', $row['tags'] );
		}

		return $rows;
	}

	public static function search( string $query, int $limit = 20 ): array {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return [];

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ws_id, module, ws_type, title, tags, updated_at
				 FROM {$table}
				 WHERE user_id = %d AND status = 'active'
				 AND (title LIKE %s OR tags LIKE %s)
				 ORDER BY updated_at DESC LIMIT %d",
				$user_id, $like, $like, $limit
			),
			ARRAY_A
		) ?: [];
	}

	/* ── AI Memory ──────────────────────────────────────── */

	/**
	 * Store a memory entry for the AI copilot (per-user persistent context).
	 */
	public static function store_memory( string $key, $value, string $module = 'global' ): void {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return;

		$opt_key = "pdx_memory_{$user_id}_{$module}";
		$memory  = get_user_meta( $user_id, $opt_key, true );
		if ( ! is_array( $memory ) ) $memory = [];

		$memory[ $key ] = [
			'value' => $value,
			'ts'    => time(),
		];

		// Keep last 100 memory entries
		if ( count( $memory ) > 100 ) {
			uasort( $memory, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );
			$memory = array_slice( $memory, -100, null, true );
		}

		update_user_meta( $user_id, $opt_key, $memory );
	}

	public static function get_memory( string $module = 'global' ): array {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return [];

		$opt_key = "pdx_memory_{$user_id}_{$module}";
		$memory  = get_user_meta( $user_id, $opt_key, true );
		return is_array( $memory ) ? $memory : [];
	}

	/* ── Helpers ────────────────────────────────────────── */

	private static function generate_id(): string {
		return 'ws-' . substr( bin2hex( random_bytes( 12 ) ), 0, 20 );
	}
}
