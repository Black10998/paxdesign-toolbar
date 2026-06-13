<?php
/**
 * PDX_Access — per-user access records stored in a custom DB table.
 *
 * Table: {prefix}pdx_access
 *   id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   user_id       BIGINT UNSIGNED NOT NULL  (0 = guest identified by email)
 *   email         VARCHAR(200) NOT NULL
 *   module_id     VARCHAR(80)  NOT NULL
 *   tier          ENUM('free','preview','paid','subscription') NOT NULL DEFAULT 'free'
 *   status        ENUM('active','expired','refunded','pending') NOT NULL DEFAULT 'pending'
 *   paypal_order  VARCHAR(100) DEFAULT NULL
 *   amount        DECIMAL(10,2) DEFAULT 0.00
 *   currency      VARCHAR(10)  DEFAULT 'USD'
 *   expires_at    DATETIME DEFAULT NULL  (NULL = lifetime)
 *   created_at    DATETIME NOT NULL
 *   updated_at    DATETIME NOT NULL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Access {

	const TABLE = 'pdx_access';

	/* ── Schema install ─────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email         VARCHAR(200)    NOT NULL DEFAULT '',
			module_id     VARCHAR(80)     NOT NULL,
			tier          VARCHAR(20)     NOT NULL DEFAULT 'free',
			status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
			paypal_order  VARCHAR(100)    DEFAULT NULL,
			amount        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			currency      VARCHAR(10)     NOT NULL DEFAULT 'USD',
			expires_at    DATETIME        DEFAULT NULL,
			created_at    DATETIME        NOT NULL,
			updated_at    DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY idx_user_module  (user_id, module_id),
			KEY idx_email_module (email(100), module_id),
			KEY idx_paypal_order (paypal_order(50)),
			KEY idx_status       (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ── Write ──────────────────────────────────────────── */

	/**
	 * Create a pending access record when a PayPal order is initiated.
	 */
	public static function create_pending(
		string $module_id,
		string $paypal_order_id,
		float  $amount,
		string $currency = 'USD',
		int    $user_id  = 0,
		string $email    = ''
	): int|false {
		global $wpdb;

		if ( ! $user_id && is_user_logged_in() ) {
			$user_id = get_current_user_id();
		}
		if ( ! $email && $user_id ) {
			$email = get_userdata( $user_id )->user_email ?? '';
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			[
				'user_id'      => $user_id,
				'email'        => sanitize_email( $email ),
				'module_id'    => sanitize_key( $module_id ),
				'tier'         => 'paid',
				'status'       => 'pending',
				'paypal_order' => sanitize_text_field( $paypal_order_id ),
				'amount'       => round( $amount, 2 ),
				'currency'     => strtoupper( $currency ),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ]
		);

		return $wpdb->insert_id ?: false;
	}

	/**
	 * Activate access after successful PayPal capture.
	 */
	public static function activate(
		string $paypal_order_id,
		?string $email_from_paypal = null
	): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );

		$data   = [ 'status' => 'active', 'updated_at' => $now ];
		$format = [ '%s', '%s' ];

		if ( $email_from_paypal ) {
			$data['email']   = sanitize_email( $email_from_paypal );
			$format[]        = '%s';
		}

		$rows = $wpdb->update(
			$table,
			$data,
			[ 'paypal_order' => $paypal_order_id, 'status' => 'pending' ],
			$format,
			[ '%s', '%s' ]
		);

		return $rows > 0;
	}

	/* ── Read ───────────────────────────────────────────── */

	/**
	 * Check if the current visitor has active access to a module.
	 * Checks by user_id (logged in) or session token (guest).
	 */
	public static function has_access( string $module_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$where_parts = [ "module_id = %s", "status = 'active'" ];
		$values      = [ $module_id ];

		if ( is_user_logged_in() ) {
			$where_parts[] = 'user_id = %d';
			$values[]      = get_current_user_id();
		} else {
			$token = self::guest_token();
			if ( ! $token ) {
				return false;
			}
			return (bool) get_transient( 'pdx_access_' . $token . '_' . $module_id );
		}

		$where = implode( ' AND ', $where_parts );
		$sql   = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE {$where}
			 AND (expires_at IS NULL OR expires_at > %s)
			 LIMIT 1",
			array_merge( $values, [ current_time( 'mysql' ) ] )
		);

		return (bool) $wpdb->get_var( $sql );
	}

	/**
	 * Get all access records for a user (for the frontend status display).
	 */
	public static function get_user_access( int $user_id = 0 ): array {
		global $wpdb;
		if ( ! $user_id ) $user_id = get_current_user_id();
		if ( ! $user_id ) return [];

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT module_id, tier, status, amount, currency, expires_at, created_at
				 FROM {$wpdb->prefix}" . self::TABLE . "
				 WHERE user_id = %d
				 ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: [];
	}

	/** All payment/order rows for a customer account. */
	public static function get_user_orders( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return [];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		) ?: [];
	}

	/** Fetch one order owned by the user (by PayPal order id or internal PDX id). */
	public static function get_order_for_user( int $user_id, string $order_ref ): ?array {
		global $wpdb;
		if ( ! $user_id || '' === $order_ref ) {
			return null;
		}

		if ( str_starts_with( $order_ref, 'PDX-' ) ) {
			$id = (int) substr( $order_ref, 4 );
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d AND user_id = %d LIMIT 1",
					$id,
					$user_id
				),
				ARRAY_A
			);
			return $row ?: null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE paypal_order = %s AND user_id = %d LIMIT 1",
				sanitize_text_field( $order_ref ),
				$user_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get a single access record by PayPal order ID.
	 */
	public static function get_by_order( string $order_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE paypal_order = %s LIMIT 1",
				$order_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/* ── Admin queries ──────────────────────────────────── */

	public static function get_all_orders( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.user_login, u.display_name
				 FROM {$wpdb->prefix}" . self::TABLE . " a
				 LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
				 ORDER BY a.created_at DESC
				 LIMIT %d OFFSET %d",
				$limit, $offset
			),
			ARRAY_A
		) ?: [];
	}

	public static function count_orders(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE );
	}

	public static function revenue_total(): float {
		global $wpdb;
		return (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}" . self::TABLE . " WHERE status = 'active'"
		);
	}

	public static function revenue_by_module(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT module_id, COUNT(*) as orders, SUM(amount) as revenue
			 FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE status = 'active'
			 GROUP BY module_id
			 ORDER BY revenue DESC",
			ARRAY_A
		) ?: [];
	}

	/* ── Guest token ────────────────────────────────────── */

	public static function guest_token(): string {
		if ( isset( $_COOKIE['pdx_guest'] ) ) {
			return sanitize_text_field( $_COOKIE['pdx_guest'] );
		}
		return '';
	}

	/**
	 * Store guest access in a transient after successful payment.
	 */
	public static function grant_guest_access( string $token, string $module_id, int $ttl = 0 ): void {
		/* 0 = lifetime (1 year) */
		set_transient( 'pdx_access_' . $token . '_' . $module_id, '1', $ttl ?: YEAR_IN_SECONDS );
	}

	/* ── Status label helper ────────────────────────────── */

	public static function status_label( string $status ): string {
		return match ( $status ) {
			'active'       => 'Active',
			'pending'      => 'Pending',
			'expired'      => 'Expired',
			'refunded'     => 'Refunded',
			default        => ucfirst( $status ),
		};
	}

	public static function tier_label( string $tier ): string {
		return match ( $tier ) {
			'free'         => 'Free',
			'preview'      => 'Preview',
			'paid'         => 'Paid',
			'subscription' => 'Subscription',
			default        => ucfirst( $tier ),
		};
	}
}
