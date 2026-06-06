<?php
/**
 * PDX_Billing — SaaS subscription plans, usage quotas, credit system.
 *
 * Tables:
 *   {prefix}pdx_subscriptions  — user plan assignments
 *   {prefix}pdx_usage          — metered usage records
 *   {prefix}pdx_credits        — credit ledger
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Billing {

	const T_SUBS    = 'pdx_subscriptions';
	const T_USAGE   = 'pdx_usage';
	const T_CREDITS = 'pdx_credits';

	/** No-arg constructor — all methods are static; instance only used for DI container. */
	public function __construct() {}

	/* ── Plans ──────────────────────────────────────────── */

	public static function plans(): array {
		return [
			'free' => [
				'id'          => 'free',
				'name'        => 'Free',
				'label'       => 'Free',
				'price_month' => 0,
				'price_year'  => 0,
				'currency'    => 'USD',
				'color'       => '#8b8b8b',
				'quotas'      => [
					'scans_per_day'     => 5,
					'ai_messages_per_day' => 10,
					'workspaces'        => 3,
					'pipeline_runs'     => 2,
					'builder_runs'      => 2,
					'automation_tasks'  => 1,
					'api_calls_per_min' => 10,
					'team_members'      => 1,
					'storage_mb'        => 50,
				],
				'features'    => [ 'trust_check', 'basic_osint', 'ai_chat_preview', 'workspace_basic' ],
			],
			'pro' => [
				'id'          => 'pro',
				'name'        => 'Pro',
				'label'       => 'Pro',
				'price_month' => 29,
				'price_year'  => 290,
				'currency'    => 'USD',
				'color'       => '#7e7e7e',
				'quotas'      => [
					'scans_per_day'     => 100,
					'ai_messages_per_day' => 500,
					'workspaces'        => 50,
					'pipeline_runs'     => 50,
					'builder_runs'      => 50,
					'automation_tasks'  => 20,
					'api_calls_per_min' => 60,
					'team_members'      => 5,
					'storage_mb'        => 2048,
				],
				'features'    => [ 'full_osint', 'threat_intel', 'ai_personas', 'builder', 'pipeline', 'automation', 'connectors', 'webhooks', 'audit_log', 'workspace_full' ],
			],
			'team' => [
				'id'          => 'team',
				'name'        => 'Team',
				'label'       => 'Team',
				'price_month' => 99,
				'price_year'  => 990,
				'currency'    => 'USD',
				'color'       => '#ffffff',
				'quotas'      => [
					'scans_per_day'     => 1000,
					'ai_messages_per_day' => 5000,
					'workspaces'        => 500,
					'pipeline_runs'     => 500,
					'builder_runs'      => 500,
					'automation_tasks'  => 200,
					'api_calls_per_min' => 300,
					'team_members'      => 25,
					'storage_mb'        => 20480,
				],
				'features'    => [ 'everything_in_pro', 'team_workspaces', 'shared_investigations', 'case_management', 'worker_nodes', 'advanced_analytics', 'priority_support', 'sso_ready' ],
			],
			'enterprise' => [
				'id'          => 'enterprise',
				'name'        => 'Enterprise',
				'label'       => 'Enterprise',
				'price_month' => 0,
				'price_year'  => 0,
				'currency'    => 'USD',
				'color'       => '#555555',
				'quotas'      => [
					'scans_per_day'     => -1,
					'ai_messages_per_day' => -1,
					'workspaces'        => -1,
					'pipeline_runs'     => -1,
					'builder_runs'      => -1,
					'automation_tasks'  => -1,
					'api_calls_per_min' => -1,
					'team_members'      => -1,
					'storage_mb'        => -1,
				],
				'features'    => [ 'unlimited_everything', 'custom_integrations', 'dedicated_workers', 'sla', 'custom_contracts', 'on_premise_option' ],
			],
		];
	}

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_SUBS . " (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			plan_id      VARCHAR(40)     NOT NULL DEFAULT 'free',
			billing_cycle VARCHAR(10)    NOT NULL DEFAULT 'month',
			status       VARCHAR(20)     NOT NULL DEFAULT 'active',
			stripe_sub_id VARCHAR(100)   DEFAULT NULL,
			stripe_cus_id VARCHAR(100)   DEFAULT NULL,
			current_period_start DATETIME DEFAULT NULL,
			current_period_end   DATETIME DEFAULT NULL,
			cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
			created_at   DATETIME        NOT NULL,
			updated_at   DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_user (user_id),
			KEY idx_plan (plan_id),
			KEY idx_status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_USAGE . " (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL,
			metric     VARCHAR(80)     NOT NULL,
			module     VARCHAR(80)     NOT NULL DEFAULT '',
			quantity   INT             NOT NULL DEFAULT 1,
			recorded_at DATETIME       NOT NULL,
			PRIMARY KEY (id),
			KEY idx_user_metric (user_id, metric),
			KEY idx_recorded (recorded_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::T_CREDITS . " (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id     BIGINT UNSIGNED NOT NULL,
			delta       INT             NOT NULL,
			reason      VARCHAR(200)    NOT NULL DEFAULT '',
			reference   VARCHAR(100)    DEFAULT NULL,
			created_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY idx_user (user_id),
			KEY idx_created (created_at)
		) {$charset};" );
	}

	/* ── Subscription management ────────────────────────── */

	public static function get_subscription( int $user_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::T_SUBS . " WHERE user_id = %d", $user_id ),
			ARRAY_A
		);
		if ( ! $row ) return self::default_subscription( $user_id );
		return $row;
	}

	public static function set_plan( int $user_id, string $plan_id, string $cycle = 'month', array $stripe = [] ): void {
		global $wpdb;
		$plans = self::plans();
		if ( ! isset( $plans[ $plan_id ] ) ) return;
		$now = current_time( 'mysql' );

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::T_SUBS . " WHERE user_id = %d", $user_id
		) );

		$data = [
			'plan_id'       => $plan_id,
			'billing_cycle' => $cycle,
			'status'        => 'active',
			'updated_at'    => $now,
		];
		if ( ! empty( $stripe['sub_id'] ) )  $data['stripe_sub_id'] = $stripe['sub_id'];
		if ( ! empty( $stripe['cus_id'] ) )  $data['stripe_cus_id'] = $stripe['cus_id'];
		if ( ! empty( $stripe['period_start'] ) ) $data['current_period_start'] = $stripe['period_start'];
		if ( ! empty( $stripe['period_end'] ) )   $data['current_period_end']   = $stripe['period_end'];

		if ( $existing ) {
			$wpdb->update( $wpdb->prefix . self::T_SUBS, $data, [ 'user_id' => $user_id ] );
		} else {
			$wpdb->insert( $wpdb->prefix . self::T_SUBS, array_merge( $data, [ 'user_id' => $user_id, 'created_at' => $now ] ) );
		}

		PDX_EventBus::fire( 'billing.plan_changed', [ 'user_id' => $user_id, 'plan_id' => $plan_id ] );
		PDX_Audit::log( 'billing', 'plan_changed', [ 'user_id' => $user_id, 'plan_id' => $plan_id ] );
	}

	private static function default_subscription( int $user_id ): array {
		return [ 'user_id' => $user_id, 'plan_id' => 'free', 'status' => 'active', 'billing_cycle' => 'month' ];
	}

	/* ── Quota enforcement ──────────────────────────────── */

	public static function get_plan_for_user( int $user_id ): array {
		$sub   = self::get_subscription( $user_id );
		$plans = self::plans();
		return $plans[ $sub['plan_id'] ] ?? $plans['free'];
	}

	public static function check_quota( int $user_id, string $metric ): array {
		$plan  = self::get_plan_for_user( $user_id );
		$limit = $plan['quotas'][ $metric ] ?? 0;

		if ( $limit === -1 ) return [ 'allowed' => true, 'used' => 0, 'limit' => -1, 'remaining' => -1 ];

		$used = self::usage_today( $user_id, $metric );
		return [
			'allowed'   => $used < $limit,
			'used'      => $used,
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $used ),
			'plan'      => $plan['id'],
		];
	}

	public static function record_usage( int $user_id, string $metric, int $qty = 1, string $module = '' ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . self::T_USAGE, [
			'user_id'     => $user_id,
			'metric'      => sanitize_key( $metric ),
			'module'      => sanitize_key( $module ),
			'quantity'    => $qty,
			'recorded_at' => current_time( 'mysql' ),
		], [ '%d', '%s', '%s', '%d', '%s' ] );
	}

	public static function usage_today( int $user_id, string $metric ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(quantity),0) FROM {$wpdb->prefix}" . self::T_USAGE . "
			 WHERE user_id = %d AND metric = %s AND DATE(recorded_at) = CURDATE()",
			$user_id, $metric
		) );
	}

	public static function usage_summary( int $user_id, int $days = 30 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT metric, module, SUM(quantity) as total, DATE(recorded_at) as day
			 FROM {$wpdb->prefix}" . self::T_USAGE . "
			 WHERE user_id = %d AND recorded_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY metric, module, day ORDER BY day DESC",
			$user_id, $days
		), ARRAY_A ) ?: [];
	}

	/* ── Credits ────────────────────────────────────────── */

	public static function credit_balance( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(delta),0) FROM {$wpdb->prefix}" . self::T_CREDITS . " WHERE user_id = %d",
			$user_id
		) );
	}

	public static function add_credits( int $user_id, int $amount, string $reason = '', string $ref = '' ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . self::T_CREDITS, [
			'user_id'    => $user_id,
			'delta'      => $amount,
			'reason'     => sanitize_text_field( $reason ),
			'reference'  => sanitize_text_field( $ref ),
			'created_at' => current_time( 'mysql' ),
		], [ '%d', '%d', '%s', '%s', '%s' ] );
	}

	public static function spend_credits( int $user_id, int $amount, string $reason = '' ): bool {
		if ( self::credit_balance( $user_id ) < $amount ) return false;
		self::add_credits( $user_id, -$amount, $reason );
		return true;
	}

	/* ── Stripe architecture ────────────────────────────── */

	public static function create_stripe_checkout( int $user_id, string $plan_id, string $cycle = 'month' ): array {
		// Read from PDX_Settings if available, fall back to raw option
		$key = '';
		if ( class_exists( 'PDX_Settings', false ) && function_exists( 'pdx_settings' ) ) {
			$key = pdx_settings()->get( 'stripe.secret_key', '' );
		}
		if ( ! $key ) $key = get_option( 'pdx_stripe_secret_key', '' );
		if ( ! $key ) return [ 'error' => 'Stripe not configured. Add your Stripe secret key in Admin → Billing.' ];

		$plans = self::plans();
		$plan  = $plans[ $plan_id ] ?? null;
		if ( ! $plan ) return [ 'error' => 'Invalid plan.' ];

		$price = $cycle === 'year' ? $plan['price_year'] : $plan['price_month'];
		if ( $price <= 0 ) return [ 'error' => 'Free plan requires no payment.' ];

		$user  = get_userdata( $user_id );
		$email = $user ? $user->user_email : '';

		$resp = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/x-www-form-urlencoded' ],
			'body'    => http_build_query( [
				'mode'                                => 'subscription',
				'customer_email'                      => $email,
				'line_items[0][price_data][currency]' => strtolower( $plan['currency'] ),
				'line_items[0][price_data][product_data][name]' => 'PaxDesign ' . $plan['label'],
				'line_items[0][price_data][unit_amount]' => $price * 100,
				'line_items[0][price_data][recurring][interval]' => $cycle === 'year' ? 'year' : 'month',
				'line_items[0][quantity]'             => 1,
				'metadata[user_id]'                   => $user_id,
				'metadata[plan_id]'                   => $plan_id,
				'success_url'                         => add_query_arg( [ 'pdx_stripe_success' => '1', 'plan' => $plan_id ], home_url( '/' ) ),
				'cancel_url'                          => home_url( '/' ),
			] ),
		] );

		if ( is_wp_error( $resp ) ) return [ 'error' => $resp->get_error_message() ];
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! empty( $data['error'] ) ) return [ 'error' => $data['error']['message'] ?? 'Stripe error.' ];

		return [ 'checkout_url' => $data['url'] ?? '', 'session_id' => $data['id'] ?? '' ];
	}

	/* ── Analytics ──────────────────────────────────────── */

	public static function plan_distribution(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT plan_id, COUNT(*) as users FROM {$wpdb->prefix}" . self::T_SUBS . " GROUP BY plan_id",
			ARRAY_A
		) ?: [];
		$out = [];
		foreach ( $rows as $r ) {
			$out[ $r['plan_id'] ] = (int) $r['users'];
		}
		return $out;
	}

	public static function mrr(): float {
		global $wpdb;
		$plans = self::plans();
		$rows  = $wpdb->get_results(
			"SELECT plan_id, billing_cycle, COUNT(*) as cnt FROM {$wpdb->prefix}" . self::T_SUBS . "
			 WHERE status = 'active' GROUP BY plan_id, billing_cycle",
			ARRAY_A
		) ?: [];

		$mrr = 0.0;
		foreach ( $rows as $r ) {
			$p   = $plans[ $r['plan_id'] ] ?? null;
			if ( ! $p ) continue;
			$monthly = $r['billing_cycle'] === 'year' ? $p['price_year'] / 12 : $p['price_month'];
			$mrr    += $monthly * (int) $r['cnt'];
		}
		return round( $mrr, 2 );
	}
}
