<?php
/**
 * PDX_Team — team/org management, roles, shared investigations, case management.
 *
 * Tables:
 *   {prefix}pdx_teams        — organizations
 *   {prefix}pdx_team_members — user-team membership with roles
 *   {prefix}pdx_cases        — investigation cases
 *   {prefix}pdx_case_notes   — case comments/evidence
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Team {

	const T_TEAMS   = 'pdx_teams';
	const T_MEMBERS = 'pdx_team_members';
	const T_CASES   = 'pdx_cases';
	const T_NOTES   = 'pdx_case_notes';

	const ROLES = [ 'owner', 'admin', 'analyst', 'viewer' ];

	const ROLE_CAPS = [
		'owner'   => [ 'manage_team', 'manage_billing', 'create_case', 'edit_case', 'delete_case', 'view_all', 'invite_members', 'remove_members' ],
		'admin'   => [ 'manage_team', 'create_case', 'edit_case', 'delete_case', 'view_all', 'invite_members' ],
		'analyst' => [ 'create_case', 'edit_case', 'view_all' ],
		'viewer'  => [ 'view_all' ],
	];

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_TEAMS . " (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			team_id     VARCHAR(64)     NOT NULL,
			name        VARCHAR(200)    NOT NULL,
			slug        VARCHAR(100)    NOT NULL,
			plan_id     VARCHAR(40)     NOT NULL DEFAULT 'free',
			owner_id    BIGINT UNSIGNED NOT NULL,
			settings    LONGTEXT        DEFAULT NULL,
			created_at  DATETIME        NOT NULL,
			updated_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_team_id (team_id),
			UNIQUE KEY idx_slug    (slug),
			KEY idx_owner (owner_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_MEMBERS . " (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			team_id    VARCHAR(64)     NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			role       VARCHAR(20)     NOT NULL DEFAULT 'viewer',
			invited_by BIGINT UNSIGNED DEFAULT NULL,
			joined_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_team_user (team_id, user_id),
			KEY idx_team (team_id),
			KEY idx_user (user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_CASES . " (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			case_id     VARCHAR(64)     NOT NULL,
			team_id     VARCHAR(64)     NOT NULL,
			title       VARCHAR(300)    NOT NULL,
			description TEXT            DEFAULT NULL,
			status      VARCHAR(20)     NOT NULL DEFAULT 'open',
			priority    VARCHAR(20)     NOT NULL DEFAULT 'medium',
			assignee_id BIGINT UNSIGNED DEFAULT NULL,
			created_by  BIGINT UNSIGNED NOT NULL,
			tags        VARCHAR(500)    DEFAULT NULL,
			evidence    LONGTEXT        DEFAULT NULL,
			created_at  DATETIME        NOT NULL,
			updated_at  DATETIME        NOT NULL,
			closed_at   DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_case_id (case_id),
			KEY idx_team   (team_id),
			KEY idx_status (status),
			KEY idx_priority (priority)
		) {$charset};" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_NOTES . " (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			case_id    VARCHAR(64)     NOT NULL,
			author_id  BIGINT UNSIGNED NOT NULL,
			note_type  VARCHAR(20)     NOT NULL DEFAULT 'comment',
			content    TEXT            NOT NULL,
			attachment LONGTEXT        DEFAULT NULL,
			created_at DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY idx_case (case_id),
			KEY idx_author (author_id)
		) {$charset};" );
	}

	/* ── Team management ────────────────────────────────── */

	public static function create_team( string $name, int $owner_id ): string {
		global $wpdb;
		$team_id = 'team-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$slug    = sanitize_title( $name ) . '-' . substr( $team_id, -6 );
		$now     = current_time( 'mysql' );

		$wpdb->insert( $wpdb->prefix . self::T_TEAMS, [
			'team_id'    => $team_id,
			'name'       => sanitize_text_field( $name ),
			'slug'       => $slug,
			'owner_id'   => $owner_id,
			'created_at' => $now,
			'updated_at' => $now,
		] );

		// Add owner as member
		self::add_member( $team_id, $owner_id, 'owner' );
		PDX_Audit::log( 'team', 'team_created', [ 'team_id' => $team_id, 'name' => $name, 'owner_id' => $owner_id ] );
		PDX_EventBus::fire( 'team.created', [ 'team_id' => $team_id ] );

		return $team_id;
	}

	public static function get_team( string $team_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::T_TEAMS . " WHERE team_id = %s", $team_id
		), ARRAY_A );
		if ( $row && $row['settings'] ) $row['settings'] = json_decode( $row['settings'], true );
		return $row ?: null;
	}

	public static function get_user_teams( int $user_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT t.*, m.role FROM {$wpdb->prefix}" . self::T_TEAMS . " t
			 JOIN {$wpdb->prefix}" . self::T_MEMBERS . " m ON t.team_id = m.team_id
			 WHERE m.user_id = %d ORDER BY t.created_at DESC",
			$user_id
		), ARRAY_A ) ?: [];
	}

	/* ── Members ────────────────────────────────────────── */

	public static function add_member( string $team_id, int $user_id, string $role = 'viewer', int $invited_by = 0 ): bool {
		global $wpdb;
		$ok = (bool) $wpdb->insert( $wpdb->prefix . self::T_MEMBERS, [
			'team_id'    => $team_id,
			'user_id'    => $user_id,
			'role'       => in_array( $role, self::ROLES, true ) ? $role : 'viewer',
			'invited_by' => $invited_by ?: null,
			'joined_at'  => current_time( 'mysql' ),
		] );
		if ( $ok ) PDX_Audit::log( 'team', 'member_added', [ 'team_id' => $team_id, 'user_id' => $user_id, 'role' => $role ] );
		return $ok;
	}

	public static function remove_member( string $team_id, int $user_id ): bool {
		global $wpdb;
		$ok = (bool) $wpdb->delete( $wpdb->prefix . self::T_MEMBERS, [ 'team_id' => $team_id, 'user_id' => $user_id ] );
		if ( $ok ) PDX_Audit::log( 'team', 'member_removed', [ 'team_id' => $team_id, 'user_id' => $user_id ] );
		return $ok;
	}

	public static function get_members( string $team_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT m.*, u.user_email, u.display_name
			 FROM {$wpdb->prefix}" . self::T_MEMBERS . " m
			 LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID
			 WHERE m.team_id = %s ORDER BY m.joined_at ASC",
			$team_id
		), ARRAY_A ) ?: [];
	}

	public static function user_role( string $team_id, int $user_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT role FROM {$wpdb->prefix}" . self::T_MEMBERS . " WHERE team_id = %s AND user_id = %d",
			$team_id, $user_id
		) );
	}

	public static function user_can( string $team_id, int $user_id, string $cap ): bool {
		$role = self::user_role( $team_id, $user_id );
		if ( ! $role ) return false;
		return in_array( $cap, self::ROLE_CAPS[ $role ] ?? [], true );
	}

	/* ── Cases ──────────────────────────────────────────── */

	public static function create_case( string $team_id, string $title, string $description = '', string $priority = 'medium', array $tags = [] ): string {
		global $wpdb;
		$case_id = 'case-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$now     = current_time( 'mysql' );

		$wpdb->insert( $wpdb->prefix . self::T_CASES, [
			'case_id'     => $case_id,
			'team_id'     => $team_id,
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_textarea_field( $description ),
			'status'      => 'open',
			'priority'    => in_array( $priority, [ 'low', 'medium', 'high', 'critical' ], true ) ? $priority : 'medium',
			'created_by'  => $user_id,
			'tags'        => ! empty( $tags ) ? implode( ',', array_map( 'sanitize_key', $tags ) ) : null,
			'created_at'  => $now,
			'updated_at'  => $now,
		] );

		PDX_Audit::log( 'team', 'case_created', [ 'case_id' => $case_id, 'team_id' => $team_id, 'title' => $title ] );
		PDX_EventBus::fire( 'case.created', [ 'case_id' => $case_id, 'team_id' => $team_id ] );
		return $case_id;
	}

	public static function get_cases( string $team_id, string $status = '', int $limit = 50 ): array {
		global $wpdb;
		$where = $status ? $wpdb->prepare( 'AND status = %s', $status ) : '';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::T_CASES . "
			 WHERE team_id = %s {$where} ORDER BY priority DESC, created_at DESC LIMIT %d",
			$team_id, $limit
		), ARRAY_A ) ?: [];
	}

	public static function add_note( string $case_id, string $content, string $type = 'comment', array $attachment = [] ): bool {
		global $wpdb;
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$ok = (bool) $wpdb->insert( $wpdb->prefix . self::T_NOTES, [
			'case_id'    => $case_id,
			'author_id'  => $user_id,
			'note_type'  => sanitize_key( $type ),
			'content'    => sanitize_textarea_field( $content ),
			'attachment' => ! empty( $attachment ) ? wp_json_encode( $attachment ) : null,
			'created_at' => current_time( 'mysql' ),
		] );
		if ( $ok ) {
			$wpdb->update( $wpdb->prefix . self::T_CASES, [ 'updated_at' => current_time( 'mysql' ) ], [ 'case_id' => $case_id ] );
		}
		return $ok;
	}

	public static function get_notes( string $case_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT n.*, u.display_name, u.user_email
			 FROM {$wpdb->prefix}" . self::T_NOTES . " n
			 LEFT JOIN {$wpdb->users} u ON n.author_id = u.ID
			 WHERE n.case_id = %s ORDER BY n.created_at ASC",
			$case_id
		), ARRAY_A ) ?: [];
		foreach ( $rows as &$r ) {
			if ( $r['attachment'] ) $r['attachment'] = json_decode( $r['attachment'], true );
		}
		return $rows;
	}

	public static function update_case_status( string $case_id, string $status ): bool {
		global $wpdb;
		$valid = [ 'open', 'in_progress', 'resolved', 'closed', 'archived' ];
		if ( ! in_array( $status, $valid, true ) ) return false;
		$data = [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ];
		if ( in_array( $status, [ 'resolved', 'closed' ], true ) ) $data['closed_at'] = current_time( 'mysql' );
		return (bool) $wpdb->update( $wpdb->prefix . self::T_CASES, $data, [ 'case_id' => $case_id ] );
	}
}
