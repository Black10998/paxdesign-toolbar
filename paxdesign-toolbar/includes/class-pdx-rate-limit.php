<?php
/**
 * PDX_RateLimit — token-bucket rate limiting per user/IP/endpoint.
 *
 * Table: {prefix}pdx_rate_limits
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   bucket_key VARCHAR(128) NOT NULL UNIQUE
 *   tokens     FLOAT        NOT NULL DEFAULT 0
 *   last_refill DATETIME    NOT NULL
 *   hits_total BIGINT UNSIGNED NOT NULL DEFAULT 0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_RateLimit {

	const TABLE = 'pdx_rate_limits';

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket_key  VARCHAR(128)    NOT NULL,
			tokens      FLOAT           NOT NULL DEFAULT 0,
			last_refill DATETIME        NOT NULL,
			hits_total  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY idx_key (bucket_key)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ── Check ──────────────────────────────────────────── */

	/**
	 * Attempt to consume tokens from a bucket.
	 *
	 * @param string $key       Bucket identifier (e.g. "scan:user:42")
	 * @param float  $capacity  Max tokens in bucket
	 * @param float  $refill    Tokens added per second
	 * @param float  $cost      Tokens consumed per request
	 * @return array { allowed: bool, remaining: float, retry_after: int }
	 */
	public static function check(
		string $key,
		float  $capacity = 10.0,
		float  $refill   = 1.0,
		float  $cost     = 1.0
	): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = microtime( true );

		// Upsert bucket
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE bucket_key = %s FOR UPDATE", $key ),
			ARRAY_A
		);

		if ( ! $row ) {
			$wpdb->insert( $table, [
				'bucket_key'  => $key,
				'tokens'      => $capacity,
				'last_refill' => current_time( 'mysql' ),
				'hits_total'  => 0,
			], [ '%s', '%f', '%s', '%d' ] );
			$row = [ 'tokens' => $capacity, 'last_refill' => current_time( 'mysql' ), 'hits_total' => 0 ];
		}

		// Refill tokens based on elapsed time
		$elapsed  = $now - strtotime( $row['last_refill'] );
		$tokens   = min( $capacity, (float) $row['tokens'] + $elapsed * $refill );
		$allowed  = $tokens >= $cost;

		if ( $allowed ) {
			$tokens -= $cost;
		}

		$wpdb->update( $table, [
			'tokens'      => $tokens,
			'last_refill' => current_time( 'mysql' ),
			'hits_total'  => (int) $row['hits_total'] + 1,
		], [ 'bucket_key' => $key ], [ '%f', '%s', '%d' ], [ '%s' ] );

		$retry_after = $allowed ? 0 : (int) ceil( ( $cost - $tokens ) / $refill );

		return [
			'allowed'     => $allowed,
			'remaining'   => round( $tokens, 2 ),
			'capacity'    => $capacity,
			'retry_after' => $retry_after,
		];
	}

	/* ── Helpers ────────────────────────────────────────── */

	public static function key_for_user( string $action ): string {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		$ip      = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		return $action . ':' . ( $user_id ?: 'ip:' . md5( $ip ) );
	}

	public static function check_scan( string $module ): array {
		return self::check( self::key_for_user( "scan:{$module}" ), 20, 0.5, 1 );
	}

	public static function check_ai( string $module ): array {
		return self::check( self::key_for_user( "ai:{$module}" ), 30, 1.0, 1 );
	}

	public static function check_api(): array {
		return self::check( self::key_for_user( 'api' ), 60, 2.0, 1 );
	}

	public static function stats(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT bucket_key, tokens, hits_total, last_refill
			 FROM {$wpdb->prefix}" . self::TABLE . "
			 ORDER BY hits_total DESC LIMIT 50",
			ARRAY_A
		) ?: [];
	}

	public static function prune_stale( int $hours = 24 ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}" . self::TABLE . " WHERE last_refill < DATE_SUB(NOW(), INTERVAL %d HOUR)",
			$hours
		) );
	}
}
