<?php
/**
 * REST API — all internal endpoints consumed by the JS dock.
 * Base: /wp-json/pdx/v1/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_REST_API {

	public function __construct(
		private PDX_Settings        $settings,
		private PDX_Module_Registry $modules,
		private PDX_Commerce        $commerce,
		private PDX_Intelligence    $intel
	) {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	private function rate_limit_check( string $action ): ?WP_REST_Response {
		$result = PDX_RateLimit::check( PDX_RateLimit::key_for_user( $action ) );
		if ( ! $result['allowed'] ) {
			return new WP_REST_Response( [
				'error'       => 'rate_limited',
				'message'     => 'Too many requests. Please slow down.',
				'retry_after' => $result['retry_after'],
				'remaining'   => $result['remaining'],
			], 429 );
		}
		return null;
	}

	/**
	 * Normalize user input before any intelligence module runs.
	 *
	 * @return array{resolved:array<string,mixed>,host:string,raw:string}|WP_REST_Response
	 */
	private function resolve_target_param( string $value, string $label = 'target' ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return new WP_REST_Response( [ 'error' => ucfirst( $label ) . ' is required.' ], 400 );
		}

		$resolved = PDX_Target::resolve( $value );
		if ( is_wp_error( $resolved ) ) {
			return new WP_REST_Response(
				[
					'error'      => $resolved->get_error_message(),
					'code'       => $resolved->get_error_code(),
					'target_raw' => $value,
				],
				400
			);
		}

		return [
			'resolved' => $resolved,
			'host'     => PDX_Target::api_host( $resolved ),
			'raw'      => $value,
		];
	}

	private function check_module_access( string $module_id ): ?WP_REST_Response {
		$mod  = $this->modules->get_with_pricing( $module_id, $this->settings );
		$tier = $mod['tier'] ?? 'paid';

		if ( 'free' === $tier || PDX_Access::has_access( $module_id ) ) {
			return null;
		}

		if ( 'subscription' === $tier ) {
			$user_id = is_user_logged_in() ? get_current_user_id() : 0;
			if ( $user_id && PDX_Billing::subscription_covers_module( $user_id, $module_id ) ) {
				return null;
			}
			return new WP_REST_Response(
				[
					'error'     => 'subscription_required',
					'module_id' => $module_id,
					'plans'     => rest_url( 'pdx/v1/billing/plans' ),
				],
				402
			);
		}

		$preview_limit = (int) ( $mod['preview_lines'] ?? 0 );
		$session_key   = 'pdx_preview_' . $module_id . '_' . ( $_COOKIE['pdx_guest'] ?? md5( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$used          = (int) get_transient( $session_key );

		if ( $preview_limit > 0 && $used < $preview_limit ) {
			set_transient( $session_key, $used + 1, HOUR_IN_SECONDS );
			return null;
		}

		return new WP_REST_Response(
			[
				'error'        => 'payment_required',
				'preview_used' => $used,
				'preview_max'  => $preview_limit,
				'module_id'    => $module_id,
				'price'        => $mod['price'],
				'currency'     => $mod['currency'],
			],
			402
		);
	}

	private function quota_check( string $metric ): ?WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return null;
		$q = PDX_Billing::check_quota( $user_id, $metric );
		if ( ! $q['allowed'] ) {
			return new WP_REST_Response( [
				'error'     => 'quota_exceeded',
				'message'   => "Daily {$metric} limit reached ({$q['used']}/{$q['limit']}).",
				'quota'     => $q,
				'upgrade'   => rest_url( 'pdx/v1/billing/plans' ),
			], 429 );
		}
		return null;
	}

	private function require_logged_in(): ?WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response( [ 'error' => 'Authentication required.' ], 401 );
		}
		return null;
	}

	private function require_actor(): ?WP_REST_Response {
		if ( ! PDX_Security::require_actor() ) {
			return new WP_REST_Response( [ 'error' => 'Session required. Reload the page and try again.' ], 401 );
		}
		return null;
	}

	private function deny_unless( bool $allowed, string $message = 'Access denied.' ): ?WP_REST_Response {
		if ( ! $allowed ) {
			return new WP_REST_Response( [ 'error' => $message ], 403 );
		}
		return null;
	}

	private function track_usage( string $metric, string $module = '' ): void {
		if ( is_user_logged_in() ) {
			PDX_Billing::record_usage( get_current_user_id(), $metric, 1, $module );
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function upstream_error_status( array $payload ): int {
		$source = $payload['source'] ?? null;
		if ( 'error' === $source ) {
			return 503;
		}
		if ( is_array( $source ) && empty( $source ) && ! empty( $payload['error'] ) ) {
			return 503;
		}
		return 200;
	}

	private function bind_guest_order( string $order_id ): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$guest = PDX_Security::ensure_guest_session();
		if ( ! $guest ) {
			return;
		}
		set_transient( 'pdx_order_owner_' . $order_id, $guest, DAY_IN_SECONDS );
	}

	private function guest_owns_order( string $order_id ): bool {
		if ( is_user_logged_in() ) {
			return true;
		}
		$guest = sanitize_text_field( $_COOKIE['pdx_guest'] ?? '' );
		$owner = get_transient( 'pdx_order_owner_' . $order_id );
		if ( ! $owner ) {
			return false;
		}
		return hash_equals( (string) $owner, $guest );
	}

	/**
	 * Administrator-only REST routes (manage_options).
	 *
	 * @return true|WP_Error
	 */
	public function rest_admin_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'pdx_rest_unauthorized',
				'You must be logged in to access this administrator endpoint.',
				[ 'status' => 401, 'required_capability' => PDX_CAP ]
			);
		}
		if ( ! current_user_can( PDX_CAP ) ) {
			return new WP_Error(
				'pdx_rest_forbidden',
				sprintf(
					'Missing capability "%s". Only WordPress administrators can run platform audits.',
					PDX_CAP
				),
				[ 'status' => 403, 'required_capability' => PDX_CAP ]
			);
		}
		return true;
	}

	public function register_routes(): void {
		$ns  = 'pdx/v1';
		$pub = '__return_true';
		$adm = static fn() => current_user_can( PDX_CAP );

		// Scans
		register_rest_route( $ns, '/trust',          [ 'methods' => 'GET',   'callback' => [ $this, 'trust_check'       ], 'permission_callback' => $pub, 'args' => [ 'domain' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => [ 'PDX_Target', 'rest_validate_indicator' ] ] ] ] );
		register_rest_route( $ns, '/osint/scan',     [ 'methods' => 'POST',  'callback' => [ $this, 'osint_scan'        ], 'permission_callback' => $pub ] );

		// AI
		register_rest_route( $ns, '/ai/chat',        [ 'methods' => 'POST',  'callback' => [ $this, 'ai_chat'           ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/ai/memory',      [ [ 'methods' => 'GET',  'callback' => [ $this, 'ai_memory_get'   ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'ai_memory_store' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/ai/conversations', [ 'methods' => 'GET', 'callback' => [ $this, 'ai_conversations_list' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/ai/conversations/(?P<thread_id>[a-z0-9\-]+)', [ 'methods' => 'GET', 'callback' => [ $this, 'ai_conversation_get' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/ai/export',      [ 'methods' => 'POST', 'callback' => [ $this, 'ai_export'          ], 'permission_callback' => $pub ] );

		// Automation
		register_rest_route( $ns, '/automation/submit', [ 'methods' => 'POST', 'callback' => [ $this, 'automation_submit' ], 'permission_callback' => $pub ] );

		// Connectors
		register_rest_route( $ns, '/connectors/test', [ 'methods' => 'POST', 'callback' => [ $this, 'connectors_test'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/connectors/list', [ 'methods' => 'GET',  'callback' => [ $this, 'connectors_list'  ], 'permission_callback' => $pub ] );

		// AI Builder
		register_rest_route( $ns, '/builder/run',       [ 'methods' => 'POST', 'callback' => [ $this, 'builder_run'       ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/builder/templates', [ 'methods' => 'GET',  'callback' => [ $this, 'builder_templates' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/builder/flows',     [ [ 'methods' => 'GET', 'callback' => [ $this, 'builder_flows_list' ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'builder_flow_save' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/builder/flows/(?P<flow_id>[a-z0-9\-]+)', [ [ 'methods' => 'GET', 'callback' => [ $this, 'builder_flow_get' ], 'permission_callback' => $pub ], [ 'methods' => 'DELETE', 'callback' => [ $this, 'builder_flow_delete' ], 'permission_callback' => $pub ] ] );

		// Agent Pipeline
		register_rest_route( $ns, '/pipeline/run',       [ 'methods' => 'POST', 'callback' => [ $this, 'pipeline_run'       ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/pipeline/templates', [ 'methods' => 'GET',  'callback' => [ $this, 'pipeline_templates' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/pipeline/flows',     [ [ 'methods' => 'GET', 'callback' => [ $this, 'pipeline_flows_list' ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'pipeline_flow_save' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/pipeline/flows/(?P<flow_id>[a-z0-9\-]+)', [ [ 'methods' => 'GET', 'callback' => [ $this, 'pipeline_flow_get' ], 'permission_callback' => $pub ], [ 'methods' => 'DELETE', 'callback' => [ $this, 'pipeline_flow_delete' ], 'permission_callback' => $pub ] ] );

		// Queue
		register_rest_route( $ns, '/queue/jobs',  [ 'methods' => 'GET', 'callback' => [ $this, 'queue_jobs'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/queue/job',   [ 'methods' => 'GET', 'callback' => [ $this, 'job_status'  ], 'permission_callback' => $pub, 'args' => [ 'job_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ] ] ] );
		register_rest_route( $ns, '/queue/stats', [ 'methods' => 'GET', 'callback' => [ $this, 'queue_stats_endpoint' ], 'permission_callback' => $adm ] );

		// Workspaces
		register_rest_route( $ns, '/workspace',                          [ [ 'methods' => 'GET',   'callback' => [ $this, 'workspace_list'   ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'workspace_create' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/workspace/search',                   [ 'methods' => 'GET',   'callback' => [ $this, 'workspace_search'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/workspace/(?P<ws_id>[a-z0-9\-]+)',  [ [ 'methods' => 'GET',   'callback' => [ $this, 'workspace_get'    ], 'permission_callback' => $pub ], [ 'methods' => 'PATCH', 'callback' => [ $this, 'workspace_update' ], 'permission_callback' => $pub ] ] );

		// Cache purge (admin only)
		register_rest_route( $ns, '/cache/purge', [ 'methods' => 'POST', 'callback' => [ $this, 'cache_purge' ], 'permission_callback' => $adm ] );

		// Audit (admin only)
		register_rest_route( $ns, '/audit', [ 'methods' => 'GET', 'callback' => [ $this, 'audit_log' ], 'permission_callback' => $adm ] );

		// Webhooks (admin only)
		register_rest_route( $ns, '/webhooks',                        [ [ 'methods' => 'GET',  'callback' => [ $this, 'webhooks_list'   ], 'permission_callback' => $adm ], [ 'methods' => 'POST', 'callback' => [ $this, 'webhooks_create' ], 'permission_callback' => $adm ] ] );
		register_rest_route( $ns, '/webhooks/(?P<id>[a-z0-9\-]+)',   [ [ 'methods' => 'PATCH', 'callback' => [ $this, 'webhooks_update' ], 'permission_callback' => $adm ], [ 'methods' => 'DELETE', 'callback' => [ $this, 'webhooks_delete' ], 'permission_callback' => $adm ] ] );

		// Commerce
		register_rest_route( $ns, '/pay/create',  [ 'methods' => 'POST', 'callback' => [ $this, 'pay_create'  ], 'permission_callback' => $pub, 'args' => [ 'module_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ] ] ] );
		register_rest_route( $ns, '/pay/capture', [ 'methods' => 'POST', 'callback' => [ $this, 'pay_capture' ], 'permission_callback' => $pub, 'args' => [ 'order_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ], 'module_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ] ] ] );
		register_rest_route( $ns, '/pay/status',  [ 'methods' => 'GET',  'callback' => [ $this, 'pay_status'  ], 'permission_callback' => $pub ] );

		// Misc
		register_rest_route( $ns, '/brief/submit', [ 'methods' => 'POST', 'callback' => [ $this, 'brief_submit' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/event',        [ 'methods' => 'POST', 'callback' => [ $this, 'log_event'    ], 'permission_callback' => $pub, 'args' => [ 'module' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ], 'action' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ], 'meta' => [ 'required' => false, 'default' => [] ] ] ] );
		register_rest_route( $ns, '/settings',     [ [ 'methods' => 'GET', 'callback' => [ $this, 'get_settings' ], 'permission_callback' => $adm ], [ 'methods' => 'POST', 'callback' => [ $this, 'update_settings' ], 'permission_callback' => $adm ] ] );

		// (live_config / polling endpoint removed — /pay/status is the live source of truth)

		// Billing
		register_rest_route( $ns, '/billing/plans',    [ 'methods' => 'GET',  'callback' => [ $this, 'billing_plans'    ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/billing/status',   [ 'methods' => 'GET',  'callback' => [ $this, 'billing_status'   ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/billing/checkout', [ 'methods' => 'POST', 'callback' => [ $this, 'billing_checkout' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/billing/usage',    [ 'methods' => 'GET',  'callback' => [ $this, 'billing_usage'    ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/billing/credits',  [ 'methods' => 'GET',  'callback' => [ $this, 'billing_credits'  ], 'permission_callback' => $pub ] );

		// Correlation / IOC — accept both GET and POST (dock.js uses POST)
		register_rest_route( $ns, '/intel/correlate',  [ 'methods' => [ 'GET', 'POST' ], 'callback' => [ $this, 'intel_correlate'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/intel/timeline',   [ 'methods' => 'GET',             'callback' => [ $this, 'intel_timeline'   ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/intel/clusters',   [ 'methods' => 'GET',             'callback' => [ $this, 'intel_clusters'   ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/intel/search',     [ 'methods' => 'GET',             'callback' => [ $this, 'intel_search'     ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/intel/stats',      [ 'methods' => 'GET',             'callback' => [ $this, 'intel_stats'      ], 'permission_callback' => $adm ] );

		// Workers — /worker/* (internal) + /workers alias (dock.js GET)
		register_rest_route( $ns, '/worker/register',  [ 'methods' => 'POST', 'callback' => [ $this, 'worker_register'  ], 'permission_callback' => $adm ] );
		register_rest_route( $ns, '/worker/heartbeat', [ 'methods' => 'POST', 'callback' => [ $this, 'worker_heartbeat' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/worker/callback',  [ 'methods' => 'POST', 'callback' => [ $this, 'worker_callback'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/worker/list',      [ 'methods' => 'GET',  'callback' => [ $this, 'worker_list'      ], 'permission_callback' => $adm ] );
		register_rest_route( $ns, '/worker/profiles',  [ 'methods' => 'GET',  'callback' => [ $this, 'worker_profiles'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/worker/(?P<worker_id>[a-z0-9\-]+)', [ 'methods' => 'DELETE', 'callback' => [ $this, 'worker_delete' ], 'permission_callback' => $adm ] );
		// Alias: dock.js calls /workers (plural) — admin only.
		register_rest_route( $ns, '/workers', [ 'methods' => 'GET', 'callback' => [ $this, 'worker_list' ], 'permission_callback' => $adm ] );

		// AI Memory
		register_rest_route( $ns, '/memory/store',   [ 'methods' => 'POST', 'callback' => [ $this, 'memory_store'   ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/memory/search',  [ 'methods' => 'GET',  'callback' => [ $this, 'memory_search'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/memory/context', [ 'methods' => 'GET',  'callback' => [ $this, 'memory_context' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/memory/recent',  [ 'methods' => 'GET',  'callback' => [ $this, 'memory_recent'  ], 'permission_callback' => $pub ] );

		// Teams — /team/* (internal) + /teams alias (dock.js)
		register_rest_route( $ns, '/team',                                    [ [ 'methods' => 'GET',  'callback' => [ $this, 'team_list'         ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'team_create'     ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/teams',                                   [ [ 'methods' => 'GET',  'callback' => [ $this, 'team_list'         ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'team_create'     ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/team/(?P<team_id>[a-z0-9\-]+)/members',  [ [ 'methods' => 'GET',  'callback' => [ $this, 'team_members'      ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'team_add_member' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/teams/(?P<team_id>[a-z0-9\-]+)/members', [ [ 'methods' => 'GET',  'callback' => [ $this, 'team_members'      ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'team_add_member' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/team/(?P<team_id>[a-z0-9\-]+)/cases',    [ [ 'methods' => 'GET',  'callback' => [ $this, 'team_cases'        ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'team_create_case'], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/teams/(?P<team_id>[a-z0-9\-]+)/cases',   [ [ 'methods' => 'GET',  'callback' => [ $this, 'team_cases'        ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'team_create_case'], 'permission_callback' => $pub ] ] );
		// Case notes — dock.js calls /cases/{id}/notes
		register_rest_route( $ns, '/team/case/(?P<case_id>[a-z0-9\-]+)/notes', [ [ 'methods' => 'GET', 'callback' => [ $this, 'case_notes'    ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'case_add_note' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/cases/(?P<case_id>[a-z0-9\-]+)/notes',     [ [ 'methods' => 'GET', 'callback' => [ $this, 'case_notes'    ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'case_add_note' ], 'permission_callback' => $pub ] ] );

		// Command palette
		register_rest_route( $ns, '/command/search', [ 'methods' => 'GET', 'callback' => [ $this, 'command_search' ], 'permission_callback' => $pub ] );

		// Developer tokens
		register_rest_route( $ns, '/dev/token',                              [ [ 'methods' => 'GET', 'callback' => [ $this, 'dev_token_list'   ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'dev_token_create' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/dev/token/(?P<token_id>[a-z0-9\-]+)',   [ 'methods' => 'DELETE', 'callback' => [ $this, 'dev_token_delete' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/dev/tokens',                             [ [ 'methods' => 'GET', 'callback' => [ $this, 'dev_token_list'   ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'dev_token_create' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/dev/tokens/(?P<token_id>[a-z0-9\-]+)',  [ 'methods' => 'DELETE', 'callback' => [ $this, 'dev_token_delete' ], 'permission_callback' => $pub ] );

		// Platform stats (admin dashboard)
		register_rest_route( $ns, '/platform/stats', [ 'methods' => 'GET', 'callback' => [ $this, 'platform_stats' ], 'permission_callback' => [ $this, 'rest_admin_permission' ] ] );
		register_rest_route( $ns, '/platform/integration-audit', [ 'methods' => 'GET', 'callback' => [ $this, 'platform_integration_audit' ], 'permission_callback' => [ $this, 'rest_admin_permission' ] ] );

		// Threat Intel — CVE lookup + attack surface mapping
		register_rest_route( $ns, '/threat/cve',     [ 'methods' => 'GET', 'callback' => [ $this, 'threat_cve'     ], 'permission_callback' => $pub, 'args' => [ 'q' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ] ] ] );
		register_rest_route( $ns, '/threat/surface', [ 'methods' => 'GET', 'callback' => [ $this, 'threat_surface' ], 'permission_callback' => $pub, 'args' => [ 'domain' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ] ] ] );
		register_rest_route( $ns, '/threat/feeds',   [ 'methods' => 'GET', 'callback' => [ $this, 'threat_feeds'   ], 'permission_callback' => $pub, 'args' => [ 'domain' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ] ] ] );

		// Authentication & account
		register_rest_route( $ns, '/auth/register',          [ 'methods' => 'POST', 'callback' => [ $this, 'auth_register' ],          'permission_callback' => $pub ] );
		register_rest_route( $ns, '/auth/login',             [ 'methods' => 'POST', 'callback' => [ $this, 'auth_login' ],             'permission_callback' => $pub ] );
		register_rest_route( $ns, '/auth/logout',            [ 'methods' => 'POST', 'callback' => [ $this, 'auth_logout' ],            'permission_callback' => $pub ] );
		register_rest_route( $ns, '/auth/me',                [ 'methods' => 'GET',  'callback' => [ $this, 'auth_me' ],                'permission_callback' => $pub ] );
		register_rest_route( $ns, '/auth/forgot-password',   [ 'methods' => 'POST', 'callback' => [ $this, 'auth_forgot_password' ],   'permission_callback' => $pub ] );
		register_rest_route( $ns, '/auth/reset-password',    [ 'methods' => 'POST', 'callback' => [ $this, 'auth_reset_password' ],    'permission_callback' => $pub ] );
		register_rest_route( $ns, '/auth/resend-verification',[ 'methods' => 'POST', 'callback' => [ $this, 'auth_resend_verification' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/auth/verify',            [ 'methods' => 'POST', 'callback' => [ $this, 'auth_verify' ],            'permission_callback' => $pub ] );
		register_rest_route( $ns, '/account/dashboard',      [ 'methods' => 'GET',  'callback' => [ $this, 'account_dashboard' ],      'permission_callback' => $pub ] );
		register_rest_route( $ns, '/account/profile',        [ 'methods' => 'POST', 'callback' => [ $this, 'account_update_profile' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/account/api-keys',       [ 'methods' => 'POST', 'callback' => [ $this, 'account_update_api_key' ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/account/api-keys/validate', [ 'methods' => 'POST', 'callback' => [ $this, 'account_validate_api_key' ], 'permission_callback' => $pub ] );
	}

	/**
	 * v8 deep scan orchestration (TrustCheck / OSINT).
	 *
	 * @return array<string, mixed>
	 */
	private function run_deep_scan( string $raw, bool $paid, string $module ): array {
		$orchestrator = new PDX_Scan_Orchestrator( $this->intel, $this->settings );
		return $orchestrator->run( $raw, $paid, $module );
	}

	/* ── Trust check ─────────────────────────────────────── */

	public function trust_check( WP_REST_Request $req ): WP_REST_Response {
		$rl = $this->rate_limit_check( 'trust_scan' );
		if ( $rl ) {
			return $rl;
		}

		$quota = $this->quota_check( 'scans_per_day' );
		if ( $quota ) {
			return $quota;
		}

		$raw    = (string) $req->get_param( 'domain' );
		$norm   = $this->resolve_target_param( $raw, 'domain' );
		if ( $norm instanceof WP_REST_Response ) {
			return $norm;
		}

		$domain = $norm['host'];
		$paid   = PDX_Access::has_access( 'trust' );

		$report = $this->run_deep_scan( $norm['raw'], $paid, 'trust' );

		// Anomaly detection against scan history
		$anomalies = PDX_Intelligence::detect_anomalies( $domain, $report['risk'] );
		$report['anomalies'] = $anomalies;

		// Behavioral signals
		$report['behavioral'] = PDX_Intelligence::behavioral_score( $report );

		// Save to workspace history
		$ws_id = PDX_Workspace::create( 'trust', 'scan', "Trust scan: {$domain}", $report );
		$report['workspace_id'] = $ws_id;

		// Audit
		PDX_Audit::log( 'trust', 'scan_completed', [ 'domain' => $domain, 'score' => $report['risk']['score'] ] );

		// Webhook
		PDX_Webhook::dispatch( 'scan.completed', [ 'module' => 'trust', 'domain' => $domain, 'risk' => $report['risk'] ] );

		$this->track_usage( 'scans_per_day', 'trust' );

		return new WP_REST_Response( $report, 200 );
	}

	/* ── AI chat ─────────────────────────────────────────── */

	public function ai_chat( WP_REST_Request $req ): WP_REST_Response {
		$module_id = sanitize_key( $req->get_param( 'module_id' ) ?? 'personas' );
		$message   = sanitize_textarea_field( $req->get_param( 'message' ) ?? '' );
		$persona   = sanitize_key( $req->get_param( 'persona' ) ?? 'assistant' );
		$stream    = (bool) $req->get_param( 'stream' );

		if ( ! $message ) {
			return new WP_REST_Response( [ 'error' => 'Message required.' ], 400 );
		}

		$denied = $this->check_module_access( $module_id );
		if ( $denied ) {
			return $denied;
		}

		$quota = $this->quota_check( 'ai_messages_per_day' );
		if ( $quota ) {
			return $quota;
		}

		$thread_id = sanitize_text_field( $req->get_param( 'thread_id' ) ?? '' );
		if ( ! $thread_id ) {
			$thread_id = PDX_Conversation::get_or_create( $persona );
		}

		$history = (array) ( $req->get_param( 'history' ) ?? [] );
		if ( empty( $history ) ) {
			$history = PDX_Conversation::get_messages( $thread_id );
		}

		$memory_agent = 'persona_' . $persona;
		$memory_ctx   = PDX_Memory::build_context( $memory_agent, 600 );
		$messages     = PDX_AI_Service::build_persona_messages( $persona, $message, $history, $memory_ctx );

		$result = PDX_AI_Service::chat_completion( $this->settings, $messages, [ 'max_tokens' => 900, 'temperature' => 0.7 ] );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 503 );
		}

		PDX_Conversation::append( $thread_id, 'user', $message );
		PDX_Conversation::append( $thread_id, 'assistant', $result['content'] );
		PDX_Memory::store(
			'User: ' . substr( $message, 0, 400 ) . "\nAssistant: " . substr( $result['content'], 0, 400 ),
			$memory_agent,
			'context',
			45,
			[],
			DAY_IN_SECONDS * 14
		);

		PDX_Audit::log( $module_id, 'ai_chat', [ 'persona' => $persona, 'thread_id' => $thread_id ] );

		$this->track_usage( 'ai_messages_per_day', $module_id );

		$payload = [
			'reply'       => $result['content'],
			'model'       => $result['model'],
			'thread_id'   => $thread_id,
			'tokens_used' => $result['tokens_used'],
		];

		if ( $stream ) {
			$payload['stream_chunks'] = PDX_AI_Service::chunk_for_stream( $result['content'] );
		}

		return new WP_REST_Response( $payload, 200 );
	}

	public function ai_conversations_list( WP_REST_Request $req ): WP_REST_Response {
		$persona = sanitize_key( $req->get_param( 'persona' ) ?? '' );
		return new WP_REST_Response( [ 'threads' => PDX_Conversation::list_threads( $persona ?: null ) ], 200 );
	}

	public function ai_conversation_get( WP_REST_Request $req ): WP_REST_Response {
		$thread_id = sanitize_text_field( $req->get_param( 'thread_id' ) ?? '' );
		$export    = PDX_Conversation::export_thread( $thread_id );
		if ( ! $export ) {
			return new WP_REST_Response( [ 'error' => 'Thread not found.' ], 404 );
		}
		return new WP_REST_Response( $export, 200 );
	}

	public function ai_export( WP_REST_Request $req ): WP_REST_Response {
		$thread_id = sanitize_text_field( $req->get_param( 'thread_id' ) ?? '' );
		if ( ! $thread_id ) {
			return new WP_REST_Response( [ 'error' => 'thread_id required.' ], 400 );
		}
		$export = PDX_Conversation::export_thread( $thread_id );
		if ( ! $export ) {
			return new WP_REST_Response( [ 'error' => 'Thread not found.' ], 404 );
		}
		return new WP_REST_Response( $export, 200 );
	}

	/* ── OSINT scan ──────────────────────────────────────── */

	public function osint_scan( WP_REST_Request $req ): WP_REST_Response {
		$rl = $this->rate_limit_check( 'osint_scan' );
		if ( $rl ) {
			return $rl;
		}

		$quota = $this->quota_check( 'scans_per_day' );
		if ( $quota ) {
			return $quota;
		}

		$raw       = sanitize_text_field( $req->get_param( 'target' ) ?? '' );
		$norm      = $this->resolve_target_param( $raw, 'target' );
		if ( $norm instanceof WP_REST_Response ) {
			return $norm;
		}
		$target    = $norm['raw'];
		$module_id = 'osint';

		$mod  = $this->modules->get_with_pricing( $module_id, $this->settings );
		$paid = PDX_Access::has_access( $module_id );

		if ( ! $paid && ( $mod['tier'] ?? 'paid' ) !== 'free' ) {
			// Preview: free sources only
			$report = $this->run_deep_scan( $target, false, 'osint' );
			$report['paywall'] = [
				'module_id' => $module_id,
				'price'     => $mod['price'],
				'currency'  => $mod['currency'],
				'message'   => 'Unlock full report: IP geolocation, VirusTotal, Shodan, email intelligence, behavioral scoring.',
				'locked_sources' => [ 'geolocation', 'virustotal', 'shodan', 'hunter', 'behavioral' ],
			];
			PDX_Audit::log( $module_id, 'preview_scan', [ 'target' => $target ] );
			$this->track_usage( 'scans_per_day', $module_id );
			return new WP_REST_Response( $report, 200 );
		}

		// Full paid scan
		$report = $this->run_deep_scan( $target, true, 'osint' );
		$report['anomalies'] = PDX_Intelligence::detect_anomalies( $target, $report['risk'] );
		$report['behavioral'] = PDX_Intelligence::behavioral_score( $report );

		// Save workspace
		$ws_id = PDX_Workspace::create( $module_id, 'scan', "OSINT: {$target}", $report );
		$report['workspace_id'] = $ws_id;

		PDX_Audit::log( $module_id, 'full_scan_completed', [ 'target' => $target, 'score' => $report['risk']['score'] ] );
		PDX_Webhook::dispatch( 'scan.completed', [ 'module' => $module_id, 'target' => $target, 'risk' => $report['risk'] ] );

		$this->track_usage( 'scans_per_day', $module_id );

		return new WP_REST_Response( $report, 200 );
	}

	/* ── Project brief ───────────────────────────────────── */

	public function brief_submit( WP_REST_Request $req ): WP_REST_Response {
		$name    = sanitize_text_field(     $req->get_param( 'name' )    ?? '' );
		$email   = sanitize_email(          $req->get_param( 'email' )   ?? '' );
		$type    = sanitize_text_field(     $req->get_param( 'type' )    ?? '' );
		$budget  = sanitize_text_field(     $req->get_param( 'budget' )  ?? '' );
		$details = sanitize_textarea_field( $req->get_param( 'details' ) ?? '' );

		if ( ! $name || ! $email || ! $details ) return new WP_REST_Response( [ 'error' => 'Name, email, and details are required.' ], 400 );
		if ( ! is_email( $email ) ) return new WP_REST_Response( [ 'error' => 'Invalid email address.' ], 400 );

		wp_mail(
			get_option( 'admin_email' ),
			'[PaxDesign] New Project Brief from ' . $name,
			"Name: {$name}\nEmail: {$email}\nType: {$type}\nBudget: {$budget}\n\nDetails:\n{$details}",
			[ 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email ]
		);

		$log   = get_option( 'pdx_briefs', [] );
		$log[] = [ 'ts' => time(), 'name' => $name, 'email' => $email, 'type' => $type, 'budget' => $budget, 'details' => substr( $details, 0, 500 ) ];
		update_option( 'pdx_briefs', array_slice( $log, -200 ) );

		return new WP_REST_Response( [ 'ok' => true, 'message' => "Brief received. We'll be in touch within 24 hours." ], 200 );
	}

	/* ── PayPal: create order ────────────────────────────── */

	public function pay_create( WP_REST_Request $req ): WP_REST_Response {
		$module_id = $req->get_param( 'module_id' );
		if ( ! $this->commerce->is_configured() ) return new WP_REST_Response( [ 'error' => 'Payment system not configured.' ], 503 );

		$mod = $this->modules->get_with_pricing( $module_id, $this->settings );
		if ( ! $mod ) return new WP_REST_Response( [ 'error' => 'Unknown module.' ], 400 );
		if ( (float) $mod['price'] <= 0 ) return new WP_REST_Response( [ 'error' => 'This module is free.' ], 400 );

		$order = $this->commerce->create_order(
			$module_id, (float) $mod['price'], $mod['currency'],
			'PaxDesign — ' . $mod['label'],
			add_query_arg( [ 'pdx_capture' => '1', 'pdx_module' => $module_id ], home_url( '/' ) ),
			home_url( '/' )
		);

		if ( is_wp_error( $order ) ) return new WP_REST_Response( [ 'error' => $order->get_error_message() ], 502 );

		PDX_Access::create_pending( $module_id, $order['id'], (float) $mod['price'], $mod['currency'] );
		$this->bind_guest_order( (string) $order['id'] );

		$approve_url = '';
		foreach ( $order['links'] ?? [] as $link ) {
			if ( $link['rel'] === 'approve' ) { $approve_url = $link['href']; break; }
		}

		return new WP_REST_Response( [
			'order_id'    => $order['id'],
			'approve_url' => $approve_url,
			'amount'      => $mod['price'],
			'currency'    => $mod['currency'],
		], 200 );
	}

	/* ── PayPal: capture order ───────────────────────────── */

	public function pay_capture( WP_REST_Request $req ): WP_REST_Response {
		$order_id  = $req->get_param( 'order_id' );
		$module_id = $req->get_param( 'module_id' );
		if ( ! $this->commerce->is_configured() ) return new WP_REST_Response( [ 'error' => 'Payment system not configured.' ], 503 );

		$pending = PDX_Access::get_by_order( (string) $order_id );
		if ( ! $pending || 'pending' !== ( $pending['status'] ?? '' ) ) {
			return new WP_REST_Response( [ 'error' => 'No pending order found for this payment.' ], 404 );
		}
		if ( ( $pending['module_id'] ?? '' ) !== $module_id ) {
			return new WP_REST_Response( [ 'error' => 'Module does not match the pending order.' ], 400 );
		}

		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( $user_id && (int) ( $pending['user_id'] ?? 0 ) !== $user_id ) {
			return new WP_REST_Response( [ 'error' => 'This order belongs to another account.' ], 403 );
		}
		if ( ! $user_id && ! $this->guest_owns_order( (string) $order_id ) ) {
			return new WP_REST_Response( [ 'error' => 'This payment session does not match the order initiator.' ], 403 );
		}

		$capture = $this->commerce->capture_order( $order_id );
		if ( is_wp_error( $capture ) ) return new WP_REST_Response( [ 'error' => $capture->get_error_message() ], 502 );
		if ( ( $capture['status'] ?? '' ) !== 'COMPLETED' ) return new WP_REST_Response( [ 'error' => 'Payment not completed.', 'status' => $capture['status'] ?? '' ], 402 );

		$payer_email  = $capture['payer']['email_address'] ?? '';
		$module_granted = (string) $pending['module_id'];
		PDX_Access::activate( $order_id, $payer_email ?: null );

		if ( ! is_user_logged_in() ) {
			$token = PDX_Access::guest_token();
			if ( ! $token ) {
				$token = wp_generate_password( 32, false );
				setcookie( 'pdx_guest', $token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
			PDX_Access::grant_guest_access( $token, $module_granted );
		}

		return new WP_REST_Response( [ 'ok' => true, 'status' => 'active', 'module_id' => $module_granted, 'message' => 'Payment confirmed. Access unlocked.' ], 200 );
	}

	/* ── Access status ───────────────────────────────────── */

	public function pay_status(): WP_REST_Response {
		$modules = $this->modules->all_with_pricing( $this->settings );
		$result  = [];
		foreach ( $modules as $id => $mod ) {
			if ( $mod['tier'] === 'free' || ( isset( $mod['price'] ) && (float) $mod['price'] <= 0 ) ) {
				$result[ $id ] = [
					'status'      => 'active',
					'tier'        => 'free',
					'label'       => 'Free',
					'price'       => 0,
					'currency'    => $mod['currency'] ?? 'USD',
					'description' => $mod['description'] ?? '',
				];
			} else {
				$has = PDX_Access::has_access( $id );
				if ( ! $has && 'subscription' === ( $mod['tier'] ?? '' ) && is_user_logged_in() ) {
					$has = PDX_Billing::subscription_covers_module( get_current_user_id(), $id );
				}
				$result[ $id ] = [
					'status'      => $has ? 'active' : 'locked',
					'tier'        => $mod['tier'],
					'label'       => $has ? 'Unlocked' : ( 'preview' === $mod['tier'] ? 'Preview Available' : ( 'subscription' === $mod['tier'] ? 'Subscription Required' : 'Locked' ) ),
					'price'       => (float) $mod['price'],
					'currency'    => $mod['currency'] ?? 'USD',
					'description' => $mod['description'] ?? '',
				];
			}
		}
		$response = new WP_REST_Response( $result, 200 );
		// Prevent all caching — access state must always reflect current admin settings.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		return $response;
	}

	/* ── Analytics ───────────────────────────────────────── */

	public function log_event( WP_REST_Request $req ): WP_REST_Response {
		if ( ! $this->settings->get( 'analytics_enabled' ) ) return new WP_REST_Response( [ 'ok' => false ], 200 );
		$log   = get_option( 'pdx_event_log', [] );
		$log[] = [ 'ts' => time(), 'module' => $req->get_param( 'module' ), 'action' => $req->get_param( 'action' ), 'meta' => (array) $req->get_param( 'meta' ), 'ip' => $this->settings->get( 'gdpr_mode' ) ? null : sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) ];
		$days  = (int) $this->settings->get( 'data_retention_days', 30 );
		$log   = array_values( array_filter( $log, static fn( $e ) => $e['ts'] >= time() - $days * DAY_IN_SECONDS ) );
		update_option( 'pdx_event_log', $log );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->all(), 200 );
	}

	public function update_settings( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		$clean = PDX_Security::sanitize_rest_settings( $body );
		if ( empty( $clean ) ) {
			return new WP_REST_Response( [ 'error' => 'No allowed settings keys in payload.' ], 400 );
		}
		$this->settings->save( $clean );
		return new WP_REST_Response( [ 'ok' => true, 'settings' => $this->settings->all() ], 200 );
	}


	/* ── AI Memory ───────────────────────────────────────── */

	public function ai_memory_get( WP_REST_Request $req ): WP_REST_Response {
		$module = sanitize_key( $req->get_param( 'module' ) ?? 'global' );
		return new WP_REST_Response( [ 'memory' => PDX_Workspace::get_memory( $module ) ], 200 );
	}

	public function ai_memory_store( WP_REST_Request $req ): WP_REST_Response {
		$key    = sanitize_key( $req->get_param( 'key' ) ?? '' );
		$value  = $req->get_param( 'value' );
		$module = sanitize_key( $req->get_param( 'module' ) ?? 'global' );
		if ( ! $key ) return new WP_REST_Response( [ 'error' => 'Key required.' ], 400 );
		PDX_Workspace::store_memory( $key, $value, $module );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/* ── Automation ──────────────────────────────────────── */

	public function automation_submit( WP_REST_Request $req ): WP_REST_Response {
		$module_id = 'automation';
		$mod       = $this->modules->get_with_pricing( $module_id, $this->settings );
		if ( ! PDX_Access::has_access( $module_id ) && ( $mod['tier'] ?? 'paid' ) !== 'free' ) {
			return new WP_REST_Response( [ 'error' => 'payment_required', 'module_id' => $module_id, 'price' => $mod['price'], 'currency' => $mod['currency'] ], 402 );
		}

		$quota = $this->quota_check( 'automation_tasks' );
		if ( $quota ) {
			return $quota;
		}

		$raw_url = trim( (string) ( $req->get_param( 'url' ) ?? '' ) );
		$task    = sanitize_textarea_field( $req->get_param( 'task' ) ?? '' );
		if ( ! $raw_url || ! $task ) {
			return new WP_REST_Response( [ 'error' => 'URL and task are required.' ], 400 );
		}

		$norm = $this->resolve_target_param( $raw_url, 'url' );
		if ( $norm instanceof WP_REST_Response ) {
			return $norm;
		}

		$url = $norm['resolved']['url'] ?? ( 'https://' . $norm['host'] );
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return new WP_REST_Response( [ 'error' => 'Could not build a valid URL from input.' ], 400 );
		}
		if ( is_wp_error( PDX_AI_Service::api_key( $this->settings ) ) ) {
			return new WP_REST_Response( [ 'error' => 'OpenAI API key not configured.' ], 503 );
		}

		$job_id = PDX_Queue::enqueue( $module_id, 'browser_automation', [ 'url' => $url, 'task' => $task ], 3, 7200 );
		PDX_Queue::start( $job_id );
		PDX_Queue::update_progress( $job_id, 15 );

		$result = PDX_Browser_Automation::execute( $url, $task, $this->settings );
		PDX_Queue::update_progress( $job_id, 85 );

		$dispatch = PDX_Worker::dispatch_job( $job_id );
		if ( ( $dispatch['status'] ?? '' ) !== 'dispatched' ) {
			PDX_Queue::complete( $job_id, $result );
		}

		PDX_Audit::log( $module_id, 'task_submitted', [ 'url' => $url, 'job_id' => $job_id, 'sandbox' => $result['sandbox'] ?? [] ] );
		PDX_Webhook::dispatch( 'job.completed', [ 'module' => $module_id, 'job_id' => $job_id ] );
		$ws_id = PDX_Workspace::create( $module_id, 'automation', 'Automation: ' . parse_url( $url, PHP_URL_HOST ), $result );

		$this->track_usage( 'automation_tasks', $module_id );

		$job = PDX_Queue::get( $job_id );

		return new WP_REST_Response(
			[
				'job_id'       => $job_id,
				'workspace_id' => $ws_id,
				'status'       => $job['status'] ?? 'done',
				'progress'     => (int) ( $job['progress'] ?? 100 ),
				'result'       => $result,
				'worker'       => $dispatch,
			],
			200
		);
	}

	/* ── Connectors ──────────────────────────────────────── */

	public function connectors_list( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( [ 'connectors' => $this->get_connector_definitions() ], 200 );
	}

	public function connectors_test( WP_REST_Request $req ): WP_REST_Response {
		$module_id = 'connectors';
		$mod       = $this->modules->get_with_pricing( $module_id, $this->settings );
		if ( ! PDX_Access::has_access( $module_id ) && ( $mod['tier'] ?? 'paid' ) !== 'free' ) {
			return new WP_REST_Response( [ 'error' => 'payment_required', 'module_id' => $module_id, 'price' => $mod['price'], 'currency' => $mod['currency'] ], 402 );
		}
		$type     = sanitize_key( $req->get_param( 'type' ) ?? '' );
		$endpoint = esc_url_raw( $req->get_param( 'endpoint' ) ?? '' );
		$auth     = sanitize_text_field( $req->get_param( 'auth_token' ) ?? '' );
		if ( ! $type || ! $endpoint ) return new WP_REST_Response( [ 'error' => 'Connector type and endpoint required.' ], 400 );

		$url_err = PDX_Security::validate_outbound_url( $endpoint );
		if ( $url_err ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $url_err, 'type' => $type ], 400 );
		}

		$rl = $this->rate_limit_check( 'connector_test' );
		if ( $rl ) {
			return $rl;
		}

		$quota = $this->quota_check( 'api_calls_per_min' );
		if ( $quota ) {
			return $quota;
		}

		$start  = microtime( true );
		$result = $this->test_connector( $type, $endpoint, $auth );
		$result['latency_ms'] = round( ( microtime( true ) - $start ) * 1000 );
		PDX_Audit::log( $module_id, 'connector_tested', [ 'type' => $type, 'endpoint' => $endpoint, 'ok' => $result['ok'] ] );
		PDX_Webhook::dispatch( 'connector.test', [ 'type' => $type, 'ok' => $result['ok'] ] );
		$this->track_usage( 'api_calls_per_min', $module_id );
		return new WP_REST_Response( $result, 200 );
	}

	private function test_connector( string $type, string $endpoint, string $auth ): array {
		$headers = $auth ? [ 'Authorization' => 'Bearer ' . $auth ] : [];
		$resp    = wp_remote_get( $endpoint, [ 'timeout' => 10, 'headers' => $headers ] );
		if ( is_wp_error( $resp ) ) return [ 'ok' => false, 'error' => $resp->get_error_message(), 'type' => $type ];
		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$json = json_decode( $body, true );
		return [
			'ok'          => $code >= 200 && $code < 300,
			'type'        => $type,
			'status_code' => $code,
			'response'    => $json ?? substr( $body, 0, 500 ),
			'headers'     => array_intersect_key( wp_remote_retrieve_headers( $resp )->getAll(), array_flip( [ 'content-type', 'x-ratelimit-remaining', 'x-request-id' ] ) ),
		];
	}

	private function get_connector_definitions(): array {
		return [
			[ 'id' => 'rest_api',   'label' => 'REST API',       'icon' => 'connector-rest',    'description' => 'Connect to any REST endpoint with Bearer auth.' ],
			[ 'id' => 'webhook',    'label' => 'Webhook',         'icon' => 'connector-webhook', 'description' => 'Send events to external webhook URLs.' ],
			[ 'id' => 'openai',     'label' => 'OpenAI',          'icon' => 'connector-openai',  'description' => 'GPT-4 and embedding models.' ],
			[ 'id' => 'slack',      'label' => 'Slack',           'icon' => 'connector-slack',   'description' => 'Post messages to Slack channels.' ],
			[ 'id' => 'airtable',   'label' => 'Airtable',        'icon' => 'connector-airtable','description' => 'Read/write Airtable bases.' ],
			[ 'id' => 'notion',     'label' => 'Notion',          'icon' => 'connector-notion',  'description' => 'Sync data with Notion databases.' ],
			[ 'id' => 'github',     'label' => 'GitHub',          'icon' => 'connector-github',  'description' => 'Trigger workflows and read repo data.' ],
			[ 'id' => 'zapier',     'label' => 'Zapier',          'icon' => 'connector-zapier',  'description' => 'Trigger Zapier zaps via webhooks.' ],
		];
	}

	/* ── AI Builder ──────────────────────────────────────── */

	public function builder_templates( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( [ 'templates' => $this->get_builder_templates() ], 200 );
	}

	public function builder_run( WP_REST_Request $req ): WP_REST_Response {
		$module_id = 'builder';
		$denied    = $this->check_module_access( $module_id );
		if ( $denied ) {
			return $denied;
		}
		if ( is_wp_error( PDX_AI_Service::api_key( $this->settings ) ) ) {
			return new WP_REST_Response( [ 'error' => 'OpenAI API key not configured.' ], 503 );
		}

		$quota = $this->quota_check( 'builder_runs' );
		if ( $quota ) {
			return $quota;
		}

		$flow_name = sanitize_text_field( $req->get_param( 'flow_name' ) ?? 'Untitled Flow' );
		$steps     = (array) ( $req->get_param( 'steps' ) ?? [] );
		$input     = sanitize_textarea_field( $req->get_param( 'input' ) ?? '' );
		if ( empty( $steps ) ) {
			return new WP_REST_Response( [ 'error' => 'At least one step required.' ], 400 );
		}

		$job_id = PDX_Queue::enqueue( $module_id, 'flow_run', [ 'flow_name' => $flow_name, 'steps' => $steps, 'input' => $input ], 2, 3600 );
		PDX_Queue::start( $job_id );
		$result = PDX_Workflow_Engine::run_builder_flow( $this->settings, $steps, $input, $job_id );
		PDX_Queue::complete( $job_id, $result );

		PDX_Audit::log( $module_id, 'flow_executed', [ 'flow_name' => $flow_name, 'steps' => count( $steps ), 'job_id' => $job_id ] );
		PDX_Webhook::dispatch( 'builder.deploy', [ 'flow_name' => $flow_name, 'job_id' => $job_id ] );
		$ws_id = PDX_Workspace::create( $module_id, 'builder', $flow_name, [ 'steps' => $steps, 'result' => $result ] );

		$this->track_usage( 'builder_runs', $module_id );

		return new WP_REST_Response(
			[
				'job_id'       => $job_id,
				'workspace_id' => $ws_id,
				'flow_name'    => $flow_name,
				'result'       => $result,
			],
			200
		);
	}

	public function builder_flows_list(): WP_REST_Response {
		return new WP_REST_Response( [ 'flows' => PDX_Flow_Store::list( 'builder' ) ], 200 );
	}

	public function builder_flow_save( WP_REST_Request $req ): WP_REST_Response {
		$name = sanitize_text_field( $req->get_param( 'name' ) ?? 'Saved Flow' );
		$def  = [
			'steps' => (array) ( $req->get_param( 'steps' ) ?? [] ),
			'input' => sanitize_textarea_field( $req->get_param( 'input' ) ?? '' ),
		];
		$id = PDX_Flow_Store::save( 'builder', $name, $def );
		return new WP_REST_Response( [ 'flow_id' => $id, 'ok' => true ], 201 );
	}

	public function builder_flow_get( WP_REST_Request $req ): WP_REST_Response {
		$flow = PDX_Flow_Store::get( sanitize_text_field( $req->get_param( 'flow_id' ) ?? '' ) );
		if ( ! $flow || ( $flow['type'] ?? '' ) !== 'builder' ) {
			return new WP_REST_Response( [ 'error' => 'Flow not found.' ], 404 );
		}
		return new WP_REST_Response( $flow, 200 );
	}

	public function builder_flow_delete( WP_REST_Request $req ): WP_REST_Response {
		$ok = PDX_Flow_Store::delete( sanitize_text_field( $req->get_param( 'flow_id' ) ?? '' ) );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	private function get_builder_templates(): array {
		return [
			[ 'id' => 'summarizer',    'label' => 'Content Summarizer',    'steps' => [ [ 'type' => 'llm', 'prompt' => 'Summarize the following content concisely:' ] ] ],
			[ 'id' => 'classifier',    'label' => 'Intent Classifier',     'steps' => [ [ 'type' => 'llm', 'prompt' => 'Classify the intent of this text. Return: category, confidence, reasoning.' ] ] ],
			[ 'id' => 'extractor',     'label' => 'Entity Extractor',      'steps' => [ [ 'type' => 'llm', 'prompt' => 'Extract all named entities (people, orgs, locations, dates) as JSON.' ] ] ],
			[ 'id' => 'sentiment',     'label' => 'Sentiment Analyzer',    'steps' => [ [ 'type' => 'llm', 'prompt' => 'Analyze sentiment. Return: score (-1 to 1), label, key phrases.' ] ] ],
			[ 'id' => 'qa_chain',      'label' => 'Q&A Chain',             'steps' => [ [ 'type' => 'llm', 'prompt' => 'Answer the question based on the provided context. Be precise.' ] ] ],
			[ 'id' => 'report_gen',    'label' => 'Report Generator',      'steps' => [ [ 'type' => 'llm', 'prompt' => 'Generate a professional structured report from this data:' ], [ 'type' => 'llm', 'prompt' => 'Add an executive summary and key recommendations to this report:' ] ] ],
		];
	}

	/* ── Agent Pipeline ──────────────────────────────────── */

	public function pipeline_templates( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( [ 'templates' => $this->get_pipeline_templates() ], 200 );
	}

	public function pipeline_run( WP_REST_Request $req ): WP_REST_Response {
		$module_id = 'pipeline';
		$denied    = $this->check_module_access( $module_id );
		if ( $denied ) {
			return $denied;
		}
		if ( is_wp_error( PDX_AI_Service::api_key( $this->settings ) ) ) {
			return new WP_REST_Response( [ 'error' => 'OpenAI API key not configured.' ], 503 );
		}

		$quota = $this->quota_check( 'pipeline_runs' );
		if ( $quota ) {
			return $quota;
		}

		$pipeline_name = sanitize_text_field( $req->get_param( 'pipeline_name' ) ?? 'Untitled Pipeline' );
		$agents        = (array) ( $req->get_param( 'agents' ) ?? [] );
		$objective     = sanitize_textarea_field( $req->get_param( 'objective' ) ?? '' );
		if ( empty( $agents ) || ! $objective ) {
			return new WP_REST_Response( [ 'error' => 'Agents and objective required.' ], 400 );
		}

		$job_id = PDX_Queue::enqueue( $module_id, 'pipeline_run', [ 'pipeline_name' => $pipeline_name, 'agents' => $agents, 'objective' => $objective ], 1, 3600 );
		PDX_Queue::start( $job_id );
		$result = PDX_Workflow_Engine::run_pipeline( $this->settings, $agents, $objective, $job_id );
		PDX_Queue::complete( $job_id, $result );

		PDX_Audit::log( $module_id, 'pipeline_executed', [ 'pipeline_name' => $pipeline_name, 'agents' => count( $agents ), 'job_id' => $job_id ] );
		PDX_Webhook::dispatch( 'pipeline.run', [ 'pipeline_name' => $pipeline_name, 'job_id' => $job_id ] );
		$ws_id = PDX_Workspace::create( $module_id, 'pipeline', $pipeline_name, [ 'agents' => $agents, 'result' => $result ] );

		$this->track_usage( 'pipeline_runs', $module_id );

		return new WP_REST_Response(
			[
				'job_id'         => $job_id,
				'workspace_id'   => $ws_id,
				'pipeline_name'  => $pipeline_name,
				'result'         => $result,
			],
			200
		);
	}

	public function pipeline_flows_list(): WP_REST_Response {
		return new WP_REST_Response( [ 'flows' => PDX_Flow_Store::list( 'pipeline' ) ], 200 );
	}

	public function pipeline_flow_save( WP_REST_Request $req ): WP_REST_Response {
		$name = sanitize_text_field( $req->get_param( 'name' ) ?? 'Saved Pipeline' );
		$def  = [
			'agents'    => (array) ( $req->get_param( 'agents' ) ?? [] ),
			'objective' => sanitize_textarea_field( $req->get_param( 'objective' ) ?? '' ),
		];
		$id = PDX_Flow_Store::save( 'pipeline', $name, $def );
		return new WP_REST_Response( [ 'flow_id' => $id, 'ok' => true ], 201 );
	}

	public function pipeline_flow_get( WP_REST_Request $req ): WP_REST_Response {
		$flow = PDX_Flow_Store::get( sanitize_text_field( $req->get_param( 'flow_id' ) ?? '' ) );
		if ( ! $flow || ( $flow['type'] ?? '' ) !== 'pipeline' ) {
			return new WP_REST_Response( [ 'error' => 'Pipeline not found.' ], 404 );
		}
		return new WP_REST_Response( $flow, 200 );
	}

	public function pipeline_flow_delete( WP_REST_Request $req ): WP_REST_Response {
		$ok = PDX_Flow_Store::delete( sanitize_text_field( $req->get_param( 'flow_id' ) ?? '' ) );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	private function get_pipeline_templates(): array {
		return [
			[ 'id' => 'research_report', 'label' => 'Research & Report', 'agents' => [ [ 'role' => 'researcher', 'name' => 'Researcher' ], [ 'role' => 'analyst', 'name' => 'Analyst' ], [ 'role' => 'writer', 'name' => 'Writer' ] ] ],
			[ 'id' => 'review_improve',  'label' => 'Review & Improve',  'agents' => [ [ 'role' => 'writer', 'name' => 'Drafter' ], [ 'role' => 'critic', 'name' => 'Critic' ], [ 'role' => 'writer', 'name' => 'Reviser' ] ] ],
			[ 'id' => 'intel_brief',     'label' => 'Intel Brief',       'agents' => [ [ 'role' => 'researcher', 'name' => 'Intel Gatherer' ], [ 'role' => 'analyst', 'name' => 'Threat Analyst' ], [ 'role' => 'coordinator', 'name' => 'Brief Writer' ] ] ],
			[ 'id' => 'code_review',     'label' => 'Code Review Chain', 'agents' => [ [ 'role' => 'analyst', 'name' => 'Code Reviewer' ], [ 'role' => 'critic', 'name' => 'Security Auditor' ], [ 'role' => 'writer', 'name' => 'Documentation Writer' ] ] ],
		];
	}

	/* ── Queue ───────────────────────────────────────────── */

	public function queue_jobs( WP_REST_Request $req ): WP_REST_Response {
		$module = sanitize_key( $req->get_param( 'module' ) ?? '' );
		$limit  = min( 50, (int) ( $req->get_param( 'limit' ) ?? 20 ) );
		$offset = (int) ( $req->get_param( 'offset' ) ?? 0 );
		return new WP_REST_Response( [ 'jobs' => PDX_Queue::get_user_jobs( $module, $limit, $offset ) ], 200 );
	}

	public function job_status( WP_REST_Request $req ): WP_REST_Response {
		$job_id = sanitize_text_field( $req->get_param( 'job_id' ) ?? '' );
		if ( ! $job_id ) return new WP_REST_Response( [ 'error' => 'job_id required.' ], 400 );

		$denied = $this->deny_unless( PDX_Queue::user_can_access( $job_id ) );
		if ( $denied ) {
			return $denied;
		}

		$job = PDX_Queue::get( $job_id );
		if ( ! $job ) return new WP_REST_Response( [ 'error' => 'Job not found.' ], 404 );
		return new WP_REST_Response( $job, 200 );
	}

	/* ── Workspaces ──────────────────────────────────────── */

	public function workspace_list( WP_REST_Request $req ): WP_REST_Response {
		$module = sanitize_key( $req->get_param( 'module' ) ?? '' );
		$status = sanitize_key( $req->get_param( 'status' ) ?? 'active' );
		$limit  = min( 100, (int) ( $req->get_param( 'limit' ) ?? 50 ) );
		$offset = (int) ( $req->get_param( 'offset' ) ?? 0 );
		return new WP_REST_Response( [ 'workspaces' => PDX_Workspace::get_user_workspaces( $module, $status, $limit, $offset ) ], 200 );
	}

	public function workspace_create( WP_REST_Request $req ): WP_REST_Response {
		$actor = $this->require_actor();
		if ( $actor ) {
			return $actor;
		}

		$quota = $this->quota_check( 'workspaces' );
		if ( $quota ) {
			return $quota;
		}

		$module  = sanitize_key( $req->get_param( 'module' ) ?? '' );
		$type    = sanitize_key( $req->get_param( 'type' ) ?? 'general' );
		$title   = sanitize_text_field( $req->get_param( 'title' ) ?? 'Untitled' );
		$data    = (array) ( $req->get_param( 'data' ) ?? [] );
		$tags    = (array) ( $req->get_param( 'tags' ) ?? [] );
		if ( ! $module ) return new WP_REST_Response( [ 'error' => 'Module required.' ], 400 );
		$ws_id = PDX_Workspace::create( $module, $type, $title, $data, $tags );
		PDX_Audit::log( $module, 'workspace_created', [ 'ws_id' => $ws_id, 'title' => $title ] );
		PDX_Webhook::dispatch( 'workspace.created', [ 'module' => $module, 'ws_id' => $ws_id, 'title' => $title ] );
		return new WP_REST_Response( [ 'ws_id' => $ws_id ], 201 );
	}

	public function workspace_get( WP_REST_Request $req ): WP_REST_Response {
		$ws_id = sanitize_text_field( $req->get_param( 'ws_id' ) ?? '' );
		$denied = $this->deny_unless( PDX_Workspace::user_can_access( $ws_id ) );
		if ( $denied ) {
			return $denied;
		}
		$ws    = PDX_Workspace::get( $ws_id );
		if ( ! $ws ) return new WP_REST_Response( [ 'error' => 'Workspace not found.' ], 404 );
		return new WP_REST_Response( $ws, 200 );
	}

	public function workspace_update( WP_REST_Request $req ): WP_REST_Response {
		$ws_id  = sanitize_text_field( $req->get_param( 'ws_id' ) ?? '' );
		$denied = $this->deny_unless( PDX_Workspace::user_can_access( $ws_id ) );
		if ( $denied ) {
			return $denied;
		}
		$fields = $req->get_json_params() ?? [];
		if ( ! $ws_id ) return new WP_REST_Response( [ 'error' => 'ws_id required.' ], 400 );
		$ok = PDX_Workspace::update( $ws_id, $fields );
		if ( $ok ) PDX_Webhook::dispatch( 'workspace.updated', [ 'ws_id' => $ws_id ] );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	public function workspace_search( WP_REST_Request $req ): WP_REST_Response {
		$q = sanitize_text_field( $req->get_param( 'q' ) ?? '' );
		if ( strlen( $q ) < 2 ) return new WP_REST_Response( [ 'results' => [] ], 200 );
		return new WP_REST_Response( [ 'results' => PDX_Workspace::search( $q ) ], 200 );
	}

	/* ── Audit log ───────────────────────────────────────── */

	public function cache_purge( WP_REST_Request $req ): WP_REST_Response {
		$results = PDX_CachePurge::purge_all();
		return new WP_REST_Response( [
			'success' => true,
			'version' => PDX_VERSION,
			'results' => $results,
		], 200 );
	}

	public function audit_log( WP_REST_Request $req ): WP_REST_Response {
		$module   = sanitize_key( $req->get_param( 'module' ) ?? '' );
		$severity = sanitize_key( $req->get_param( 'severity' ) ?? '' );
		$search   = sanitize_text_field( $req->get_param( 'search' ) ?? '' );
		$limit    = min( 200, (int) ( $req->get_param( 'limit' ) ?? 100 ) );
		$offset   = (int) ( $req->get_param( 'offset' ) ?? 0 );
		return new WP_REST_Response( [
			'logs'  => PDX_Audit::get_recent( $limit, $offset, $module, $severity, $search ),
			'total' => PDX_Audit::count( $module, $severity ),
			'stats' => PDX_Audit::stats_by_module(),
		], 200 );
	}

	/* ── Webhooks ────────────────────────────────────────── */

	public function webhooks_list( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( [
			'webhooks'        => array_values( PDX_Webhook::all() ),
			'available_events'=> PDX_Webhook::available_events(),
			'delivery_stats'  => PDX_Webhook::delivery_stats(),
		], 200 );
	}

	public function webhooks_create( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params() ?? [];
		if ( empty( $body['url'] ) ) return new WP_REST_Response( [ 'error' => 'URL required.' ], 400 );
		$id = PDX_Webhook::create( $body );
		PDX_Audit::log( 'webhooks', 'webhook_created', [ 'id' => $id, 'url' => $body['url'] ] );
		return new WP_REST_Response( [ 'id' => $id, 'webhook' => PDX_Webhook::get( $id ) ], 201 );
	}

	public function webhooks_update( WP_REST_Request $req ): WP_REST_Response {
		$id   = sanitize_text_field( $req->get_param( 'id' ) ?? '' );
		$body = $req->get_json_params() ?? [];
		$ok   = PDX_Webhook::update( $id, $body );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	public function webhooks_delete( WP_REST_Request $req ): WP_REST_Response {
		$id = sanitize_text_field( $req->get_param( 'id' ) ?? '' );
		$ok = PDX_Webhook::delete( $id );
		if ( $ok ) PDX_Audit::log( 'webhooks', 'webhook_deleted', [ 'id' => $id ] );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	public function queue_stats_endpoint(): WP_REST_Response {
		return new WP_REST_Response( PDX_Queue::queue_stats(), 200 );
	}

	/* ── Billing ─────────────────────────────────────────── */

	public function billing_plans(): WP_REST_Response {
		return new WP_REST_Response( [ 'plans' => PDX_Billing::plans() ], 200 );
	}

	public function billing_status(): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return new WP_REST_Response( [ 'plan' => 'free', 'authenticated' => false ], 200 );
		$sub  = PDX_Billing::get_subscription( $user_id );
		$plan = PDX_Billing::get_plan_for_user( $user_id );
		$quotas = [];
		foreach ( array_keys( $plan['quotas'] ?? [] ) as $metric ) {
			$quotas[ $metric ] = PDX_Billing::check_quota( $user_id, $metric );
		}
		return new WP_REST_Response( [
			'subscription' => $sub,
			'plan'         => $plan,
			'credits'      => PDX_Billing::credit_balance( $user_id ),
			'quotas'       => $quotas,
		], 200 );
	}

	public function billing_checkout( WP_REST_Request $req ): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return new WP_REST_Response( [ 'error' => 'Authentication required.' ], 401 );
		$plan_id = sanitize_key( $req->get_param( 'plan_id' ) ?? '' );
		$cycle   = sanitize_key( $req->get_param( 'cycle' ) ?? 'month' );
		$result  = PDX_Billing::create_stripe_checkout( $user_id, $plan_id, $cycle );
		return new WP_REST_Response( $result, isset( $result['error'] ) ? 400 : 200 );
	}

	public function billing_usage( WP_REST_Request $req ): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return new WP_REST_Response( [ 'usage' => [] ], 200 );
		$days = min( 90, (int) ( $req->get_param( 'days' ) ?? 30 ) );
		return new WP_REST_Response( [ 'usage' => PDX_Billing::usage_summary( $user_id, $days ) ], 200 );
	}

	public function billing_credits(): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		return new WP_REST_Response( [ 'balance' => $user_id ? PDX_Billing::credit_balance( $user_id ) : 0 ], 200 );
	}

	/* ── Intelligence / Correlation ──────────────────────── */

	public function intel_correlate( WP_REST_Request $req ): WP_REST_Response {
		$rl = $this->rate_limit_check( 'intel' );
		if ( $rl ) return $rl;

		$denied = $this->check_module_access( 'threat' );
		if ( $denied ) {
			return $denied;
		}

		$quota = $this->quota_check( 'scans_per_day' );
		if ( $quota ) {
			return $quota;
		}

		// Support both GET query params and POST JSON body
		$body  = $req->get_json_params() ?: [];
		$raw   = sanitize_text_field( $req->get_param( 'value' ) ?? $body['value'] ?? '' );
		$type  = sanitize_key( $req->get_param( 'type' )  ?? $body['type']  ?? '' );
		$norm  = $this->resolve_target_param( $raw, 'value' );
		if ( $norm instanceof WP_REST_Response ) {
			return $norm;
		}
		$value   = $norm['host'];
		$data    = PDX_Correlation::correlate( $value, $type ?: ( $norm['resolved']['type'] ?? '' ) );
		$data['target_raw'] = $norm['raw'];
		$api_key = $this->settings->get( 'api_keys.openai', '' );
		if ( $api_key ) $data['ai_summary'] = PDX_Correlation::ai_summary( $value, $data, $api_key );
		PDX_Audit::log( 'intel', 'correlation_run', [ 'value' => $value, 'type' => $type ] );
		$this->track_usage( 'scans_per_day', 'intel' );
		return new WP_REST_Response( $data, 200 );
	}

	public function intel_timeline( WP_REST_Request $req ): WP_REST_Response {
		$denied = $this->check_module_access( 'threat' );
		if ( $denied ) {
			return $denied;
		}

		$raw  = sanitize_text_field( $req->get_param( 'target' ) ?? '' );
		$days = min( 365, (int) ( $req->get_param( 'days' ) ?? 90 ) );
		$norm = $this->resolve_target_param( $raw, 'target' );
		if ( $norm instanceof WP_REST_Response ) {
			return $norm;
		}
		return new WP_REST_Response(
			[
				'timeline'   => PDX_Correlation::timeline( $norm['host'], $days ),
				'target'     => $norm['host'],
				'target_raw' => $norm['raw'],
			],
			200
		);
	}

	public function intel_clusters(): WP_REST_Response {
		$denied = $this->check_module_access( 'threat' );
		if ( $denied ) {
			return $denied;
		}
		return new WP_REST_Response( [ 'clusters' => PDX_Correlation::cluster_threats() ], 200 );
	}

	public function intel_search( WP_REST_Request $req ): WP_REST_Response {
		$denied = $this->check_module_access( 'threat' );
		if ( $denied ) {
			return $denied;
		}
		$q = sanitize_text_field( $req->get_param( 'q' ) ?? '' );
		if ( strlen( $q ) < 2 ) return new WP_REST_Response( [ 'results' => [] ], 200 );
		return new WP_REST_Response( [ 'results' => PDX_Correlation::search( $q ) ], 200 );
	}

	public function intel_stats(): WP_REST_Response {
		return new WP_REST_Response( [ 'stats' => PDX_Correlation::stats() ], 200 );
	}

	/* ── Workers ─────────────────────────────────────────── */

	public function worker_register( WP_REST_Request $req ): WP_REST_Response {
		$label    = sanitize_text_field( $req->get_param( 'label' ) ?? 'Worker' );
		$endpoint = esc_url_raw( $req->get_param( 'endpoint' ) ?? '' );
		$caps     = (array) ( $req->get_param( 'capabilities' ) ?? [] );
		if ( ! $endpoint ) return new WP_REST_Response( [ 'error' => 'endpoint required.' ], 400 );
		return new WP_REST_Response( PDX_Worker::register( $label, $endpoint, $caps ), 201 );
	}

	public function worker_heartbeat( WP_REST_Request $req ): WP_REST_Response {
		$worker_id = sanitize_text_field( $req->get_param( 'worker_id' ) ?? '' );
		$token     = sanitize_text_field( $req->get_param( 'token' ) ?? '' );
		if ( ! PDX_Worker::authenticate( $worker_id, $token ) ) return new WP_REST_Response( [ 'error' => 'Unauthorized.' ], 401 );
		PDX_Worker::heartbeat( $worker_id );
		$job = PDX_Queue::next_queued( 'automation' );
		return new WP_REST_Response( [ 'ok' => true, 'next_job' => $job ], 200 );
	}

	public function worker_callback( WP_REST_Request $req ): WP_REST_Response {
		$worker_id = sanitize_text_field( $req->get_param( 'worker_id' ) ?? '' );
		$token     = sanitize_text_field( $req->get_param( 'token' ) ?? '' );
		if ( ! PDX_Worker::authenticate( $worker_id, $token ) ) {
			return new WP_REST_Response( [ 'error' => 'Unauthorized worker.' ], 401 );
		}

		$job_id  = sanitize_text_field( $req->get_param( 'job_id' ) ?? '' );
		$success = (bool) $req->get_param( 'success' );
		$result  = (array) ( $req->get_param( 'result' ) ?? [] );
		if ( ! $job_id ) return new WP_REST_Response( [ 'error' => 'job_id required.' ], 400 );

		$result['worker_id'] = $worker_id;
		PDX_Worker::receive_callback( $job_id, $result, $success );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function worker_list(): WP_REST_Response {
		PDX_Worker::check_heartbeats();
		return new WP_REST_Response( [ 'workers' => PDX_Worker::all() ], 200 );
	}

	public function worker_profiles(): WP_REST_Response {
		return new WP_REST_Response( [ 'profiles' => PDX_Worker::browser_profiles() ], 200 );
	}

	public function worker_delete( WP_REST_Request $req ): WP_REST_Response {
		$ok = PDX_Worker::deregister( sanitize_text_field( $req->get_param( 'worker_id' ) ?? '' ) );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	/* ── AI Memory ───────────────────────────────────────── */

	public function memory_store( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_actor();
		if ( $auth ) {
			return $auth;
		}
		$content    = sanitize_textarea_field( $req->get_param( 'content' ) ?? '' );
		$agent      = sanitize_key( $req->get_param( 'agent' ) ?? 'global' );
		$mem_type   = sanitize_key( $req->get_param( 'type' ) ?? 'fact' );
		$importance = min( 100, max( 0, (int) ( $req->get_param( 'importance' ) ?? 50 ) ) );
		if ( ! $content ) return new WP_REST_Response( [ 'error' => 'content required.' ], 400 );
		$mem_id = PDX_Memory::store( $content, $agent, $mem_type, $importance );
		return new WP_REST_Response( [ 'mem_id' => $mem_id ], 201 );
	}

	public function memory_search( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_actor();
		if ( $auth ) {
			return $auth;
		}
		$q     = sanitize_text_field( $req->get_param( 'q' ) ?? '' );
		$agent = sanitize_key( $req->get_param( 'agent' ) ?? '' );
		if ( strlen( $q ) < 2 ) return new WP_REST_Response( [ 'results' => [] ], 200 );
		return new WP_REST_Response( [ 'results' => PDX_Memory::search( $q, $agent ) ], 200 );
	}

	public function memory_context( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_actor();
		if ( $auth ) {
			return $auth;
		}
		$agent = sanitize_key( $req->get_param( 'agent' ) ?? 'global' );
		return new WP_REST_Response( [ 'context' => PDX_Memory::build_context( $agent ) ], 200 );
	}

	public function memory_recent( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_actor();
		if ( $auth ) {
			return $auth;
		}
		$agent = sanitize_key( $req->get_param( 'agent' ) ?? 'global' );
		$limit = min( 50, (int) ( $req->get_param( 'limit' ) ?? 20 ) );
		return new WP_REST_Response( [ 'memories' => PDX_Memory::get_recent( $agent, $limit ) ], 200 );
	}

	/* ── Teams ───────────────────────────────────────────── */

	public function team_list(): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return new WP_REST_Response( [ 'teams' => [] ], 200 );
		return new WP_REST_Response( [ 'teams' => PDX_Team::get_user_teams( $user_id ) ], 200 );
	}

	public function team_create( WP_REST_Request $req ): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return new WP_REST_Response( [ 'error' => 'Authentication required.' ], 401 );
		$name = sanitize_text_field( $req->get_param( 'name' ) ?? '' );
		if ( ! $name ) return new WP_REST_Response( [ 'error' => 'name required.' ], 400 );
		$team_id = PDX_Team::create_team( $name, $user_id );
		return new WP_REST_Response( [ 'team_id' => $team_id ], 201 );
	}

	public function team_members( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_logged_in();
		if ( $auth ) {
			return $auth;
		}
		$team_id = sanitize_text_field( $req->get_param( 'team_id' ) ?? '' );
		$actor   = get_current_user_id();
		$denied  = $this->deny_unless( PDX_Team::user_can( $team_id, $actor, 'view_all' ) );
		if ( $denied ) {
			return $denied;
		}
		return new WP_REST_Response( [ 'members' => PDX_Team::get_members( $team_id ) ], 200 );
	}

	public function team_add_member( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_logged_in();
		if ( $auth ) {
			return $auth;
		}
		$team_id = sanitize_text_field( $req->get_param( 'team_id' ) ?? '' );
		$user_id = (int) $req->get_param( 'user_id' );
		$role    = sanitize_key( $req->get_param( 'role' ) ?? 'viewer' );
		$actor   = get_current_user_id();
		if ( ! PDX_Team::user_can( $team_id, $actor, 'invite_members' ) ) return new WP_REST_Response( [ 'error' => 'Insufficient permissions.' ], 403 );
		$ok = PDX_Team::add_member( $team_id, $user_id, $role, $actor );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
	}

	public function team_cases( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_logged_in();
		if ( $auth ) {
			return $auth;
		}
		$team_id = sanitize_text_field( $req->get_param( 'team_id' ) ?? '' );
		$actor   = get_current_user_id();
		$denied  = $this->deny_unless( PDX_Team::user_can( $team_id, $actor, 'view_all' ) );
		if ( $denied ) {
			return $denied;
		}
		$status  = sanitize_key( $req->get_param( 'status' ) ?? '' );
		return new WP_REST_Response( [ 'cases' => PDX_Team::get_cases( $team_id, $status ) ], 200 );
	}

	public function team_create_case( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_logged_in();
		if ( $auth ) {
			return $auth;
		}
		$team_id = sanitize_text_field( $req->get_param( 'team_id' ) ?? '' );
		$actor   = get_current_user_id();
		$denied  = $this->deny_unless( PDX_Team::user_can( $team_id, $actor, 'create_case' ) );
		if ( $denied ) {
			return $denied;
		}
		$title   = sanitize_text_field( $req->get_param( 'title' ) ?? '' );
		$desc    = sanitize_textarea_field( $req->get_param( 'description' ) ?? '' );
		$prio    = sanitize_key( $req->get_param( 'priority' ) ?? 'medium' );
		$tags    = (array) ( $req->get_param( 'tags' ) ?? [] );
		if ( ! $title ) return new WP_REST_Response( [ 'error' => 'title required.' ], 400 );
		$case_id = PDX_Team::create_case( $team_id, $title, $desc, $prio, $tags );
		return new WP_REST_Response( [ 'case_id' => $case_id ], 201 );
	}

	public function case_notes( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_logged_in();
		if ( $auth ) {
			return $auth;
		}
		$case_id = sanitize_text_field( $req->get_param( 'case_id' ) ?? '' );
		$actor   = get_current_user_id();
		$denied  = $this->deny_unless( PDX_Team::user_can_access_case( $case_id, $actor ) );
		if ( $denied ) {
			return $denied;
		}
		return new WP_REST_Response( [ 'notes' => PDX_Team::get_notes( $case_id ) ], 200 );
	}

	public function case_add_note( WP_REST_Request $req ): WP_REST_Response {
		$auth = $this->require_logged_in();
		if ( $auth ) {
			return $auth;
		}
		$case_id = sanitize_text_field( $req->get_param( 'case_id' ) ?? '' );
		$actor   = get_current_user_id();
		$denied  = $this->deny_unless( PDX_Team::user_can_access_case( $case_id, $actor ) );
		if ( $denied ) {
			return $denied;
		}
		$content = sanitize_textarea_field( $req->get_param( 'content' ) ?? '' );
		$type    = sanitize_key( $req->get_param( 'type' ) ?? 'comment' );
		if ( ! $content ) return new WP_REST_Response( [ 'error' => 'content required.' ], 400 );
		$ok = PDX_Team::add_note( $case_id, $content, $type );
		return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 201 : 400 );
	}

	/* ── Command palette ─────────────────────────────────── */

	public function command_search( WP_REST_Request $req ): WP_REST_Response {
		$q       = sanitize_text_field( $req->get_param( 'q' ) ?? '' );
		$results = [];

		// Module commands
		foreach ( $this->modules->all() as $id => $mod ) {
			if ( ! $q || stripos( $mod['label'], $q ) !== false || stripos( $mod['description'], $q ) !== false ) {
				$results[] = [ 'type' => 'module', 'id' => $id, 'label' => $mod['label'], 'description' => $mod['description'], 'icon' => $mod['icon'] ];
			}
		}

		// Workspace search
		if ( strlen( $q ) >= 2 ) {
			$ws = PDX_Workspace::search( $q, 5 );
			foreach ( $ws as $w ) {
				$results[] = [ 'type' => 'workspace', 'id' => $w['ws_id'], 'label' => $w['title'], 'description' => $w['module'] . ' · ' . $w['ws_type'], 'icon' => 'workspace-folder' ];
			}
			// IOC search
			$iocs = PDX_Correlation::search( $q, 5 );
			foreach ( $iocs as $ioc ) {
				$results[] = [ 'type' => 'ioc', 'id' => $ioc['ioc_type'] . ':' . $ioc['ioc_value'], 'label' => $ioc['ioc_value'], 'description' => $ioc['ioc_type'] . ' · ' . $ioc['source'], 'icon' => 'ioc-threat' ];
			}
		}

		// Static commands
		$static = [
			[ 'type' => 'action', 'id' => 'new_scan',          'label' => 'New TrustCheck Scan', 'description' => 'Run a domain intelligence scan', 'icon' => 'scan-new' ],
			[ 'type' => 'action', 'id' => 'new_investigation', 'label' => 'New Investigation',   'description' => 'Open investigation board',       'icon' => 'investigation-new' ],
			[ 'type' => 'action', 'id' => 'open_workspace',    'label' => 'Open Workspaces',     'description' => 'Browse saved projects',          'icon' => 'workspace-folder' ],
			[ 'type' => 'action', 'id' => 'view_audit',        'label' => 'View Audit Log',      'description' => 'Platform activity log',          'icon' => 'audit-log' ],
		];
		foreach ( $static as $cmd ) {
			if ( ! $q || stripos( $cmd['label'], $q ) !== false ) $results[] = $cmd;
		}

		return new WP_REST_Response( [ 'results' => array_slice( $results, 0, 20 ) ], 200 );
	}

	/* ── Developer tokens ────────────────────────────────── */

	public function dev_token_list(): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return new WP_REST_Response( [ 'tokens' => [] ], 200 );
		$tokens = get_user_meta( $user_id, 'pdx_dev_tokens', true );
		$safe   = array_map( fn( $t ) => array_diff_key( $t, [ 'hash' => '' ] ), is_array( $tokens ) ? $tokens : [] );
		return new WP_REST_Response( [ 'tokens' => array_values( $safe ) ], 200 );
	}

	public function dev_token_create( WP_REST_Request $req ): WP_REST_Response {
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;
		if ( ! $user_id ) return new WP_REST_Response( [ 'error' => 'Authentication required.' ], 401 );
		$label   = sanitize_text_field( $req->get_param( 'label' ) ?? 'API Token' );
		$token   = 'pdx_' . bin2hex( random_bytes( 24 ) );
		$token_id = 'tok-' . substr( bin2hex( random_bytes( 6 ) ), 0, 10 );
		$tokens  = get_user_meta( $user_id, 'pdx_dev_tokens', true );
		if ( ! is_array( $tokens ) ) $tokens = [];
		$tokens[ $token_id ] = [ 'id' => $token_id, 'label' => $label, 'hash' => hash( 'sha256', $token ), 'created_at' => time(), 'last_used' => null ];
		update_user_meta( $user_id, 'pdx_dev_tokens', $tokens );
		PDX_Audit::log( 'dev', 'token_created', [ 'label' => $label ] );
		return new WP_REST_Response( [ 'token_id' => $token_id, 'token' => $token, 'label' => $label, 'note' => 'Store this token securely — it will not be shown again.' ], 201 );
	}

	public function dev_token_delete( WP_REST_Request $req ): WP_REST_Response {
		$user_id  = is_user_logged_in() ? get_current_user_id() : 0;
		$token_id = sanitize_text_field( $req->get_param( 'token_id' ) ?? '' );
		if ( ! $user_id ) return new WP_REST_Response( [ 'error' => 'Authentication required.' ], 401 );
		$tokens = get_user_meta( $user_id, 'pdx_dev_tokens', true );
		if ( ! is_array( $tokens ) || ! isset( $tokens[ $token_id ] ) ) return new WP_REST_Response( [ 'error' => 'Token not found.' ], 404 );
		unset( $tokens[ $token_id ] );
		update_user_meta( $user_id, 'pdx_dev_tokens', $tokens );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/* ── Platform stats ──────────────────────────────────── */

	public function platform_stats(): WP_REST_Response {
		return new WP_REST_Response( [
			'audit'       => [ 'total' => PDX_Audit::count(), 'by_module' => PDX_Audit::stats_by_module(), 'hourly' => PDX_Audit::stats_by_hour( 24 ) ],
			'queue'       => PDX_Queue::queue_stats(),
			'workers'     => PDX_Worker::all(),
			'ioc_stats'   => PDX_Correlation::stats(),
			'billing'     => [ 'mrr' => PDX_Billing::mrr(), 'plans' => PDX_Billing::plan_distribution() ],
			'cache'       => PDX_Cache::stats(),
			'rate_limits' => PDX_RateLimit::stats(),
		], 200 );
	}

	public function platform_integration_audit(): WP_REST_Response {
		try {
			$audit  = new PDX_Integration_Audit( $this->intel, $this->settings );
			$result = $audit->run_full();
			// Always HTTP 200 when the audit runner completes — per-provider status lives in JSON.
			return new WP_REST_Response( $result, 200 );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX Integration Audit] fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}
			return new WP_REST_Response(
				[
					'audit_completed'     => false,
					'has_provider_errors' => true,
					'fatal_error'         => $e->getMessage(),
					'message'             => 'Integration audit terminated before all probes could run: ' . $e->getMessage(),
					'summary'             => [
						'total'   => 0,
						'ok'      => 0,
						'partial' => 0,
						'error'   => 1,
						'skipped' => 0,
					],
					'providers'           => [],
				],
				500
			);
		}
	}

	/* ── Threat Intel: Live feed status ──────────────────── */

	public function threat_feeds( WP_REST_Request $req ): WP_REST_Response {
		$denied = $this->check_module_access( 'threat' );
		if ( $denied ) {
			return $denied;
		}

		$domain = sanitize_text_field( $req->get_param( 'domain' ) ?? '' );

		try {
			$data = PDX_Threat_Feeds::aggregate( $domain );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] threat_feeds: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}

			return new WP_REST_Response(
				[
					'ok'        => false,
					'error'     => 'Feed sync failed.',
					'message'   => 'Unable to synchronize threat feeds. Please try again.',
					'detail'    => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $e->getMessage() : '',
					'target'    => $domain,
					'feeds'     => [],
					'synced_at' => gmdate( 'c' ),
					'status'    => [
						'state'   => 'error',
						'message' => 'Synchronization failed due to a server error.',
					],
				],
				200
			);
		}

		$data['ok'] = 'error' !== ( $data['status']['state'] ?? '' );
		$status     = 200;
		if ( 'error' === ( $data['status']['state'] ?? '' ) ) {
			$status = 503;
		}
		return new WP_REST_Response( $data, $status );
	}

	/* ── Threat Intel: CVE Lookup ────────────────────────── */

	public function threat_cve( WP_REST_Request $req ): WP_REST_Response {
		$denied = $this->check_module_access( 'threat' );
		if ( $denied ) {
			return $denied;
		}

		$quota = $this->quota_check( 'scans_per_day' );
		if ( $quota ) {
			return $quota;
		}

		// Rate-limit check — wrapped so a missing DB table doesn't 500.
		try {
			$rl = $this->rate_limit_check( 'threat_cve' );
			if ( $rl ) return $rl;
		} catch ( Throwable $e ) {
			// Rate-limit table may not exist yet; log and continue.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] threat_cve rate_limit_check failed: ' . $e->getMessage() );
			}
		}

		$query = sanitize_text_field( trim( $req->get_param( 'q' ) ?? '' ) );
		if ( ! $query ) {
			return new WP_REST_Response( [ 'error' => 'Query parameter "q" is required.' ], 400 );
		}

		// PDX_Cache::get() returns null on miss (not false) — check for null.
		$cache_key = 'cve_' . md5( strtolower( $query ) );
		try {
			$cached = PDX_Cache::get( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				$cached['cached'] = true;
				return new WP_REST_Response( $cached, 200 );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] threat_cve cache read failed: ' . $e->getMessage() );
			}
		}

		// Execute the lookup — catch any fatal/exception so we always return JSON.
		try {
			$result = $this->intel->fetch_cve( $query );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] threat_cve fetch_cve exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}
			return new WP_REST_Response( [
				'cves'   => [],
				'total'  => 0,
				'source' => 'error',
				'error'  => 'CVE lookup failed. Check server outbound HTTPS connectivity.',
			], 503 );
		}

		// Ensure result is always a well-formed array before caching/returning.
		if ( ! is_array( $result ) ) {
			$result = [ 'cves' => [], 'total' => 0, 'source' => 'error', 'error' => 'Unexpected response from CVE source.' ];
		}
		$result += [ 'cves' => [], 'total' => 0, 'source' => 'none' ];

		try {
			PDX_Cache::set( $cache_key, $result, HOUR_IN_SECONDS );
		} catch ( Throwable $e ) { /* non-fatal */ }

		try {
			PDX_Audit::log( 'threat', 'cve_lookup', [ 'query' => $query, 'total' => (int) ( $result['total'] ?? 0 ) ] );
		} catch ( Throwable $e ) { /* non-fatal — audit table may not exist */ }

		$this->track_usage( 'scans_per_day', 'threat' );

		return new WP_REST_Response( $result, $this->upstream_error_status( $result ) );
	}

	/* ── Threat Intel: Attack Surface ────────────────────── */

	public function threat_surface( WP_REST_Request $req ): WP_REST_Response {
		$denied = $this->check_module_access( 'threat' );
		if ( $denied ) {
			return $denied;
		}

		$quota = $this->quota_check( 'scans_per_day' );
		if ( $quota ) {
			return $quota;
		}

		try {
			$rl = $this->rate_limit_check( 'threat_surface' );
			if ( $rl ) return $rl;
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] threat_surface rate_limit_check failed: ' . $e->getMessage() );
			}
		}

		$raw    = sanitize_text_field( trim( $req->get_param( 'domain' ) ?? '' ) );
		$norm   = $this->resolve_target_param( $raw, 'domain' );
		if ( $norm instanceof WP_REST_Response ) {
			return $norm;
		}
		$target = $norm['host'];

		$cache_key = 'surface_v2_' . md5( strtolower( $target ) );
		try {
			$cached = PDX_Cache::get( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				$cached['cached'] = true;
				return new WP_REST_Response( $cached, 200 );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] threat_surface cache read failed: ' . $e->getMessage() );
			}
		}

		try {
			$result = $this->intel->fetch_attack_surface( $norm['raw'] );
			if ( is_array( $result ) ) {
				$result['target_raw'] = $norm['raw'];
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] threat_surface fetch_attack_surface exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}
			return new WP_REST_Response( [
				'target'  => $target,
				'ports'   => [],
				'vulns'   => [],
				'dns'     => [],
				'score'   => 0,
				'summary' => 'Attack surface scan failed.',
				'source'  => 'error',
				'error'   => 'Attack surface scan could not be completed.',
			], 503 );
		}

		if ( ! is_array( $result ) ) {
			$result = [ 'target' => $target, 'ports' => [], 'vulns' => [], 'dns' => [], 'score' => 0, 'summary' => '', 'source' => [] ];
		}

		try {
			PDX_Cache::set( $cache_key, $result, 30 * MINUTE_IN_SECONDS );
		} catch ( Throwable $e ) { /* non-fatal */ }

		try {
			PDX_Audit::log( 'threat', 'surface_scan', [ 'target' => $target, 'score' => (int) ( $result['score'] ?? 0 ) ] );
		} catch ( Throwable $e ) { /* non-fatal */ }

		$this->track_usage( 'scans_per_day', 'threat' );

		return new WP_REST_Response( $result, $this->upstream_error_status( $result ) );
	}

	/* ── Authentication & account ─────────────────────────── */

	private function auth_rate_limit_response( string $action ): ?WP_REST_Response {
		$result = PDX_Auth::auth_rate_limit( $action );
		if ( ! $result['allowed'] ) {
			return new WP_REST_Response( [
				'success'     => false,
				'error'       => 'rate_limited',
				'message'     => 'Too many attempts. Please try again later.',
				'retry_after' => $result['retry_after'],
			], 429 );
		}
		return null;
	}

	public function auth_register( WP_REST_Request $req ): WP_REST_Response {
		$rl = $this->auth_rate_limit_response( 'register' );
		if ( $rl ) {
			return $rl;
		}

		$password         = (string) $req->get_param( 'password' );
		$password_confirm = (string) $req->get_param( 'password_confirm' );
		if ( '' !== $password_confirm && ! hash_equals( $password, $password_confirm ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => 'password_mismatch',
				'message' => 'Password and Confirm Password must match.',
			], 400 );
		}

		$result = PDX_Auth::register(
			sanitize_email( (string) $req->get_param( 'email' ) ),
			$password,
			sanitize_text_field( (string) $req->get_param( 'name' ) )
		);
		return new WP_REST_Response( $result, $result['success'] ? 201 : 400 );
	}

	public function auth_login( WP_REST_Request $req ): WP_REST_Response {
		$rl = $this->auth_rate_limit_response( 'login' );
		if ( $rl ) {
			return $rl;
		}
		$result = PDX_Auth::login(
			sanitize_email( (string) $req->get_param( 'email' ) ),
			(string) $req->get_param( 'password' ),
			(bool) $req->get_param( 'remember' )
		);
		return new WP_REST_Response( $result, $result['success'] ? 200 : 401 );
	}

	public function auth_logout(): WP_REST_Response {
		return new WP_REST_Response( PDX_Auth::logout(), 200 );
	}

	public function auth_me(): WP_REST_Response {
		return new WP_REST_Response( PDX_Auth::user_payload(), 200 );
	}

	public function auth_forgot_password( WP_REST_Request $req ): WP_REST_Response {
		$rl = $this->auth_rate_limit_response( 'forgot' );
		if ( $rl ) {
			return $rl;
		}
		$result = PDX_Auth::forgot_password( sanitize_email( (string) $req->get_param( 'email' ) ) );
		return new WP_REST_Response( $result, 200 );
	}

	public function auth_reset_password( WP_REST_Request $req ): WP_REST_Response {
		$result = PDX_Auth::reset_password(
			sanitize_text_field( (string) $req->get_param( 'token' ) ),
			(int) $req->get_param( 'uid' ),
			(string) $req->get_param( 'password' )
		);
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	public function auth_resend_verification(): WP_REST_Response {
		$rl = $this->auth_rate_limit_response( 'resend' );
		if ( $rl ) {
			return $rl;
		}
		$deny = $this->require_logged_in();
		if ( $deny ) {
			return $deny;
		}
		$result = PDX_Auth::resend_verification();
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	public function auth_verify( WP_REST_Request $req ): WP_REST_Response {
		$result = PDX_Auth::verify_email(
			(int) $req->get_param( 'uid' ),
			sanitize_text_field( (string) $req->get_param( 'token' ) )
		);
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	public function account_dashboard(): WP_REST_Response {
		$deny = $this->require_logged_in();
		if ( $deny ) {
			return $deny;
		}
		return new WP_REST_Response( PDX_Account::dashboard( get_current_user_id() ), 200 );
	}

	public function account_update_profile( WP_REST_Request $req ): WP_REST_Response {
		$deny = $this->require_logged_in();
		if ( $deny ) {
			return $deny;
		}
		$result = PDX_Account::update_profile( get_current_user_id(), [
			'display_name'    => $req->get_param( 'display_name' ),
			'email'           => $req->get_param( 'email' ),
			'current_password'=> $req->get_param( 'current_password' ),
			'new_password'    => $req->get_param( 'new_password' ),
		] );
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	public function account_update_api_key( WP_REST_Request $req ): WP_REST_Response {
		$deny = $this->require_logged_in();
		if ( $deny ) {
			return $deny;
		}
		$result = PDX_Account::update_api_key(
			get_current_user_id(),
			sanitize_key( (string) $req->get_param( 'provider' ) ),
			sanitize_text_field( (string) $req->get_param( 'key' ) )
		);
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	public function account_validate_api_key( WP_REST_Request $req ): WP_REST_Response {
		$deny = $this->require_logged_in();
		if ( $deny ) {
			return $deny;
		}
		$result = PDX_Account::validate_api_key(
			get_current_user_id(),
			sanitize_key( (string) $req->get_param( 'provider' ) )
		);
		return new WP_REST_Response( $result, 200 );
	}
}
