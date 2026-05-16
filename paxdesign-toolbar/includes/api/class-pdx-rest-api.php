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

	public function register_routes(): void {
		$ns  = 'pdx/v1';
		$pub = '__return_true';
		$adm = static fn() => current_user_can( PDX_CAP );

		// Scans
		register_rest_route( $ns, '/trust',          [ 'methods' => 'GET',   'callback' => [ $this, 'trust_check'       ], 'permission_callback' => $pub, 'args' => [ 'domain' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/i', $v ) ] ] ] );
		register_rest_route( $ns, '/osint/scan',     [ 'methods' => 'POST',  'callback' => [ $this, 'osint_scan'        ], 'permission_callback' => $pub ] );

		// AI
		register_rest_route( $ns, '/ai/chat',        [ 'methods' => 'POST',  'callback' => [ $this, 'ai_chat'           ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/ai/memory',      [ [ 'methods' => 'GET',  'callback' => [ $this, 'ai_memory_get'   ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'ai_memory_store' ], 'permission_callback' => $pub ] ] );

		// Automation
		register_rest_route( $ns, '/automation/submit', [ 'methods' => 'POST', 'callback' => [ $this, 'automation_submit' ], 'permission_callback' => $pub ] );

		// Connectors
		register_rest_route( $ns, '/connectors/test', [ 'methods' => 'POST', 'callback' => [ $this, 'connectors_test'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/connectors/list', [ 'methods' => 'GET',  'callback' => [ $this, 'connectors_list'  ], 'permission_callback' => $pub ] );

		// AI Builder
		register_rest_route( $ns, '/builder/run',       [ 'methods' => 'POST', 'callback' => [ $this, 'builder_run'       ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/builder/templates', [ 'methods' => 'GET',  'callback' => [ $this, 'builder_templates' ], 'permission_callback' => $pub ] );

		// Agent Pipeline
		register_rest_route( $ns, '/pipeline/run',       [ 'methods' => 'POST', 'callback' => [ $this, 'pipeline_run'       ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/pipeline/templates', [ 'methods' => 'GET',  'callback' => [ $this, 'pipeline_templates' ], 'permission_callback' => $pub ] );

		// Queue
		register_rest_route( $ns, '/queue/jobs', [ 'methods' => 'GET', 'callback' => [ $this, 'queue_jobs'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/queue/job',  [ 'methods' => 'GET', 'callback' => [ $this, 'job_status'  ], 'permission_callback' => $pub, 'args' => [ 'job_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ] ] ] );

		// Workspaces
		register_rest_route( $ns, '/workspace',                          [ [ 'methods' => 'GET',   'callback' => [ $this, 'workspace_list'   ], 'permission_callback' => $pub ], [ 'methods' => 'POST', 'callback' => [ $this, 'workspace_create' ], 'permission_callback' => $pub ] ] );
		register_rest_route( $ns, '/workspace/search',                   [ 'methods' => 'GET',   'callback' => [ $this, 'workspace_search'  ], 'permission_callback' => $pub ] );
		register_rest_route( $ns, '/workspace/(?P<ws_id>[a-z0-9\-]+)',  [ [ 'methods' => 'GET',   'callback' => [ $this, 'workspace_get'    ], 'permission_callback' => $pub ], [ 'methods' => 'PATCH', 'callback' => [ $this, 'workspace_update' ], 'permission_callback' => $pub ] ] );

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
	}

	/* ── Trust check ─────────────────────────────────────── */

	public function trust_check( WP_REST_Request $req ): WP_REST_Response {
		$domain = strtolower( $req->get_param( 'domain' ) );
		$paid   = PDX_Access::has_access( 'trust' );

		$report = $this->intel->full_scan( $domain, $paid );

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

		return new WP_REST_Response( $report, 200 );
	}

	/* ── AI chat ─────────────────────────────────────────── */

	public function ai_chat( WP_REST_Request $req ): WP_REST_Response {
		$module_id = sanitize_key( $req->get_param( 'module_id' ) ?? 'personas' );
		$message   = sanitize_textarea_field( $req->get_param( 'message' ) ?? '' );
		$persona   = sanitize_text_field( $req->get_param( 'persona' ) ?? 'assistant' );

		if ( ! $message ) return new WP_REST_Response( [ 'error' => 'Message required.' ], 400 );

		$mod  = $this->modules->get_with_pricing( $module_id, $this->settings );
		$tier = $mod['tier'] ?? 'paid';

		if ( $tier !== 'free' && ! PDX_Access::has_access( $module_id ) ) {
			$preview_limit = (int) ( $mod['preview_lines'] ?? 0 );
			$session_key   = 'pdx_preview_' . $module_id . '_' . ( $_COOKIE['pdx_guest'] ?? md5( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			$used          = (int) get_transient( $session_key );

			if ( $preview_limit > 0 && $used < $preview_limit ) {
				set_transient( $session_key, $used + 1, HOUR_IN_SECONDS );
			} else {
				return new WP_REST_Response( [
					'error'        => 'payment_required',
					'preview_used' => $used,
					'preview_max'  => $preview_limit,
					'module_id'    => $module_id,
					'price'        => $mod['price'],
					'currency'     => $mod['currency'],
				], 402 );
			}
		}

		$api_key = $this->settings->get( 'api_keys.openai', '' );
		if ( ! $api_key ) return new WP_REST_Response( [ 'error' => 'OpenAI API key not configured.' ], 503 );

		$system_prompts = [
			'assistant'  => 'You are a helpful, professional AI assistant for PaxDesign clients.',
			'analyst'    => 'You are a senior business analyst. Provide structured, data-driven insights.',
			'developer'  => 'You are an expert software engineer. Give precise, production-ready technical answers.',
			'strategist' => 'You are a strategic consultant. Think in frameworks, outcomes, and ROI.',
		];

		$resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( [
				'model'       => 'gpt-4o-mini',
				'messages'    => [
					[ 'role' => 'system', 'content' => $system_prompts[ $persona ] ?? $system_prompts['assistant'] ],
					[ 'role' => 'user',   'content' => $message ],
				],
				'max_tokens'  => 800,
				'temperature' => 0.7,
			] ),
		] );

		if ( is_wp_error( $resp ) ) return new WP_REST_Response( [ 'error' => 'AI service unavailable.' ], 503 );

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$text = $body['choices'][0]['message']['content'] ?? null;
		if ( ! $text ) return new WP_REST_Response( [ 'error' => 'No response from AI.' ], 503 );

		return new WP_REST_Response( [ 'reply' => $text, 'model' => $body['model'] ?? 'gpt-4o-mini' ], 200 );
	}

	/* ── OSINT scan ──────────────────────────────────────── */

	public function osint_scan( WP_REST_Request $req ): WP_REST_Response {
		$target    = sanitize_text_field( $req->get_param( 'target' ) ?? '' );
		$module_id = 'osint';
		if ( ! $target ) return new WP_REST_Response( [ 'error' => 'Target required.' ], 400 );

		$mod  = $this->modules->get_with_pricing( $module_id, $this->settings );
		$paid = PDX_Access::has_access( $module_id );

		if ( ! $paid && ( $mod['tier'] ?? 'paid' ) !== 'free' ) {
			// Preview: free sources only
			$report = $this->intel->full_scan( $target, false );
			$report['paywall'] = [
				'module_id' => $module_id,
				'price'     => $mod['price'],
				'currency'  => $mod['currency'],
				'message'   => 'Unlock full report: IP geolocation, VirusTotal, Shodan, email intelligence, behavioral scoring.',
				'locked_sources' => [ 'geolocation', 'virustotal', 'shodan', 'hunter', 'behavioral' ],
			];
			PDX_Audit::log( $module_id, 'preview_scan', [ 'target' => $target ] );
			return new WP_REST_Response( $report, 200 );
		}

		// Full paid scan
		$report = $this->intel->full_scan( $target, true );
		$report['anomalies'] = PDX_Intelligence::detect_anomalies( $target, $report['risk'] );
		$report['behavioral'] = PDX_Intelligence::behavioral_score( $report );

		// Save workspace
		$ws_id = PDX_Workspace::create( $module_id, 'scan', "OSINT: {$target}", $report );
		$report['workspace_id'] = $ws_id;

		PDX_Audit::log( $module_id, 'full_scan_completed', [ 'target' => $target, 'score' => $report['risk']['score'] ] );
		PDX_Webhook::dispatch( 'scan.completed', [ 'module' => $module_id, 'target' => $target, 'risk' => $report['risk'] ] );

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

		$capture = $this->commerce->capture_order( $order_id );
		if ( is_wp_error( $capture ) ) return new WP_REST_Response( [ 'error' => $capture->get_error_message() ], 502 );
		if ( ( $capture['status'] ?? '' ) !== 'COMPLETED' ) return new WP_REST_Response( [ 'error' => 'Payment not completed.', 'status' => $capture['status'] ?? '' ], 402 );

		$payer_email = $capture['payer']['email_address'] ?? '';
		PDX_Access::activate( $order_id, $payer_email ?: null );

		if ( ! is_user_logged_in() ) {
			$token = PDX_Access::guest_token();
			if ( ! $token ) {
				$token = wp_generate_password( 32, false );
				setcookie( 'pdx_guest', $token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
			PDX_Access::grant_guest_access( $token, $module_id );
		}

		return new WP_REST_Response( [ 'ok' => true, 'status' => 'active', 'module_id' => $module_id, 'message' => 'Payment confirmed. Access unlocked.' ], 200 );
	}

	/* ── Access status ───────────────────────────────────── */

	public function pay_status(): WP_REST_Response {
		$modules = $this->modules->all_with_pricing( $this->settings );
		$result  = [];
		foreach ( $modules as $id => $mod ) {
			if ( $mod['tier'] === 'free' ) {
				$result[ $id ] = [ 'status' => 'active', 'tier' => 'free', 'label' => 'Free' ];
			} else {
				$has = PDX_Access::has_access( $id );
				$result[ $id ] = [
					'status'   => $has ? 'active' : 'locked',
					'tier'     => $mod['tier'],
					'label'    => $has ? 'Unlocked' : ( $mod['tier'] === 'preview' ? 'Preview Available' : 'Locked' ),
					'price'    => $mod['price'],
					'currency' => $mod['currency'],
				];
			}
		}
		return new WP_REST_Response( $result, 200 );
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
		$this->settings->save( $body );
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
		$url  = esc_url_raw( $req->get_param( 'url' ) ?? '' );
		$task = sanitize_textarea_field( $req->get_param( 'task' ) ?? '' );
		if ( ! $url || ! $task ) return new WP_REST_Response( [ 'error' => 'URL and task are required.' ], 400 );
		$api_key = $this->settings->get( 'api_keys.openai', '' );
		if ( ! $api_key ) return new WP_REST_Response( [ 'error' => 'OpenAI API key not configured.' ], 503 );
		$job_id  = PDX_Queue::enqueue( $module_id, 'browser_automation', [ 'url' => $url, 'task' => $task ], 3, 7200 );
		$analysis = $this->run_ai_task_analysis( $url, $task, $api_key );
		PDX_Queue::complete( $job_id, $analysis );
		PDX_Audit::log( $module_id, 'task_submitted', [ 'url' => $url, 'job_id' => $job_id ] );
		PDX_Webhook::dispatch( 'job.completed', [ 'module' => $module_id, 'job_id' => $job_id ] );
		$ws_id = PDX_Workspace::create( $module_id, 'automation', 'Automation: ' . parse_url( $url, PHP_URL_HOST ), $analysis );
		return new WP_REST_Response( [ 'job_id' => $job_id, 'workspace_id' => $ws_id, 'status' => 'done', 'result' => $analysis ], 200 );
	}

	private function run_ai_task_analysis( string $url, string $task, string $api_key ): array {
		$prompt = "You are a browser automation analyst. Analyze this task and return a JSON object with keys: steps (array), data_points (array), obstacles (array), approach (string), estimated_seconds (int).\n\nURL: {$url}\nTask: {$task}";
		$resp   = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'model' => 'gpt-4o-mini', 'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ], 'max_tokens' => 800, 'temperature' => 0.3 ] ),
		] );
		if ( is_wp_error( $resp ) ) return [ 'error' => 'AI unavailable', 'url' => $url, 'task' => $task ];
		$body    = json_decode( wp_remote_retrieve_body( $resp ), true );
		$content = $body['choices'][0]['message']['content'] ?? '';
		$parsed  = json_decode( $content, true );
		return $parsed ? array_merge( $parsed, [ 'url' => $url, 'task' => $task, 'ai_analyzed' => true ] )
		               : [ 'url' => $url, 'task' => $task, 'analysis' => $content, 'ai_analyzed' => true ];
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
		$start  = microtime( true );
		$result = $this->test_connector( $type, $endpoint, $auth );
		$result['latency_ms'] = round( ( microtime( true ) - $start ) * 1000 );
		PDX_Audit::log( $module_id, 'connector_tested', [ 'type' => $type, 'endpoint' => $endpoint, 'ok' => $result['ok'] ] );
		PDX_Webhook::dispatch( 'connector.test', [ 'type' => $type, 'ok' => $result['ok'] ] );
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
			[ 'id' => 'rest_api',   'label' => 'REST API',       'icon' => 'link',     'description' => 'Connect to any REST endpoint with Bearer auth.' ],
			[ 'id' => 'webhook',    'label' => 'Webhook',         'icon' => 'zap',      'description' => 'Send events to external webhook URLs.' ],
			[ 'id' => 'openai',     'label' => 'OpenAI',          'icon' => 'cpu',      'description' => 'GPT-4 and embedding models.' ],
			[ 'id' => 'slack',      'label' => 'Slack',           'icon' => 'message',  'description' => 'Post messages to Slack channels.' ],
			[ 'id' => 'airtable',   'label' => 'Airtable',        'icon' => 'grid',     'description' => 'Read/write Airtable bases.' ],
			[ 'id' => 'notion',     'label' => 'Notion',          'icon' => 'file',     'description' => 'Sync data with Notion databases.' ],
			[ 'id' => 'github',     'label' => 'GitHub',          'icon' => 'code',     'description' => 'Trigger workflows and read repo data.' ],
			[ 'id' => 'zapier',     'label' => 'Zapier',          'icon' => 'zap',      'description' => 'Trigger Zapier zaps via webhooks.' ],
		];
	}

	/* ── AI Builder ──────────────────────────────────────── */

	public function builder_templates( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( [ 'templates' => $this->get_builder_templates() ], 200 );
	}

	public function builder_run( WP_REST_Request $req ): WP_REST_Response {
		$module_id = 'builder';
		$mod       = $this->modules->get_with_pricing( $module_id, $this->settings );
		if ( ! PDX_Access::has_access( $module_id ) && ( $mod['tier'] ?? 'paid' ) !== 'free' ) {
			return new WP_REST_Response( [ 'error' => 'payment_required', 'module_id' => $module_id, 'price' => $mod['price'], 'currency' => $mod['currency'] ], 402 );
		}
		$api_key = $this->settings->get( 'api_keys.openai', '' );
		if ( ! $api_key ) return new WP_REST_Response( [ 'error' => 'OpenAI API key not configured.' ], 503 );
		$flow_name = sanitize_text_field( $req->get_param( 'flow_name' ) ?? 'Untitled Flow' );
		$steps     = (array) ( $req->get_param( 'steps' ) ?? [] );
		$input     = sanitize_textarea_field( $req->get_param( 'input' ) ?? '' );
		if ( empty( $steps ) ) return new WP_REST_Response( [ 'error' => 'At least one step required.' ], 400 );
		$job_id = PDX_Queue::enqueue( $module_id, 'flow_run', [ 'flow_name' => $flow_name, 'steps' => $steps, 'input' => $input ], 2, 3600 );
		$result = $this->execute_builder_flow( $steps, $input, $api_key );
		PDX_Queue::complete( $job_id, $result );
		PDX_Audit::log( $module_id, 'flow_executed', [ 'flow_name' => $flow_name, 'steps' => count( $steps ), 'job_id' => $job_id ] );
		PDX_Webhook::dispatch( 'builder.deploy', [ 'flow_name' => $flow_name, 'job_id' => $job_id ] );
		$ws_id = PDX_Workspace::create( $module_id, 'builder', $flow_name, [ 'steps' => $steps, 'result' => $result ] );
		return new WP_REST_Response( [ 'job_id' => $job_id, 'workspace_id' => $ws_id, 'flow_name' => $flow_name, 'result' => $result ], 200 );
	}

	private function execute_builder_flow( array $steps, string $input, string $api_key ): array {
		$context = $input;
		$outputs = [];
		foreach ( $steps as $i => $step ) {
			$type   = sanitize_key( $step['type'] ?? 'llm' );
			$prompt = sanitize_textarea_field( $step['prompt'] ?? '' );
			if ( $type === 'llm' && $prompt ) {
				$full_prompt = $prompt . ( $context ? "\n\nContext:\n" . $context : '' );
				$resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
					'timeout' => 30,
					'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( [ 'model' => 'gpt-4o-mini', 'messages' => [ [ 'role' => 'user', 'content' => $full_prompt ] ], 'max_tokens' => 600, 'temperature' => 0.5 ] ),
				] );
				$body    = is_wp_error( $resp ) ? [] : json_decode( wp_remote_retrieve_body( $resp ), true );
				$output  = $body['choices'][0]['message']['content'] ?? '[Step failed]';
				$context = $output;
				$outputs[] = [ 'step' => $i + 1, 'type' => $type, 'output' => $output ];
			} elseif ( $type === 'transform' ) {
				$context   = strtoupper( $context );
				$outputs[] = [ 'step' => $i + 1, 'type' => $type, 'output' => $context ];
			} else {
				$outputs[] = [ 'step' => $i + 1, 'type' => $type, 'output' => $context ];
			}
		}
		return [ 'steps_executed' => count( $outputs ), 'outputs' => $outputs, 'final_output' => $context ];
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
		$mod       = $this->modules->get_with_pricing( $module_id, $this->settings );
		if ( ! PDX_Access::has_access( $module_id ) && ( $mod['tier'] ?? 'paid' ) !== 'free' ) {
			return new WP_REST_Response( [ 'error' => 'payment_required', 'module_id' => $module_id, 'price' => $mod['price'], 'currency' => $mod['currency'] ], 402 );
		}
		$api_key = $this->settings->get( 'api_keys.openai', '' );
		if ( ! $api_key ) return new WP_REST_Response( [ 'error' => 'OpenAI API key not configured.' ], 503 );
		$pipeline_name = sanitize_text_field( $req->get_param( 'pipeline_name' ) ?? 'Untitled Pipeline' );
		$agents        = (array) ( $req->get_param( 'agents' ) ?? [] );
		$objective     = sanitize_textarea_field( $req->get_param( 'objective' ) ?? '' );
		if ( empty( $agents ) || ! $objective ) return new WP_REST_Response( [ 'error' => 'Agents and objective required.' ], 400 );
		$job_id = PDX_Queue::enqueue( $module_id, 'pipeline_run', [ 'pipeline_name' => $pipeline_name, 'agents' => $agents, 'objective' => $objective ], 1, 3600 );
		$result = $this->execute_pipeline( $agents, $objective, $api_key );
		PDX_Queue::complete( $job_id, $result );
		PDX_Audit::log( $module_id, 'pipeline_executed', [ 'pipeline_name' => $pipeline_name, 'agents' => count( $agents ), 'job_id' => $job_id ] );
		PDX_Webhook::dispatch( 'pipeline.run', [ 'pipeline_name' => $pipeline_name, 'job_id' => $job_id ] );
		$ws_id = PDX_Workspace::create( $module_id, 'pipeline', $pipeline_name, [ 'agents' => $agents, 'result' => $result ] );
		return new WP_REST_Response( [ 'job_id' => $job_id, 'workspace_id' => $ws_id, 'pipeline_name' => $pipeline_name, 'result' => $result ], 200 );
	}

	private function execute_pipeline( array $agents, string $objective, string $api_key ): array {
		$handoff  = $objective;
		$trace    = [];
		$personas = [
			'researcher'  => 'You are a research agent. Gather and synthesize information thoroughly.',
			'analyst'     => 'You are an analysis agent. Identify patterns, risks, and insights.',
			'writer'      => 'You are a writing agent. Produce clear, professional output.',
			'critic'      => 'You are a quality-control agent. Review and improve the previous output.',
			'coordinator' => 'You are a coordinator agent. Summarize and structure the final deliverable.',
		];
		foreach ( $agents as $agent ) {
			$role   = sanitize_key( $agent['role'] ?? 'coordinator' );
			$system = $personas[ $role ] ?? $personas['coordinator'];
			$prompt = "Objective: {$objective}\n\nPrevious agent output:\n{$handoff}\n\nYour task: Process this and produce your contribution.";
			$resp   = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
				'timeout' => 30,
				'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'model' => 'gpt-4o-mini', 'messages' => [ [ 'role' => 'system', 'content' => $system ], [ 'role' => 'user', 'content' => $prompt ] ], 'max_tokens' => 700, 'temperature' => 0.6 ] ),
			] );
			$body   = is_wp_error( $resp ) ? [] : json_decode( wp_remote_retrieve_body( $resp ), true );
			$output = $body['choices'][0]['message']['content'] ?? '[Agent failed]';
			$trace[] = [ 'agent' => $role, 'name' => $agent['name'] ?? $role, 'output' => $output ];
			$handoff = $output;
		}
		return [ 'agents_run' => count( $trace ), 'trace' => $trace, 'final_output' => $handoff, 'objective' => $objective ];
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
		$ws    = PDX_Workspace::get( $ws_id );
		if ( ! $ws ) return new WP_REST_Response( [ 'error' => 'Workspace not found.' ], 404 );
		return new WP_REST_Response( $ws, 200 );
	}

	public function workspace_update( WP_REST_Request $req ): WP_REST_Response {
		$ws_id  = sanitize_text_field( $req->get_param( 'ws_id' ) ?? '' );
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

	/* ── PayPal: create order ────────────────────────────── */

	public function pay_create( WP_REST_Request $req ): WP_REST_Response {
		$module_id = $req->get_param( 'module_id' );
		if ( ! $this->commerce->is_configured() ) return new WP_REST_Response( [ 'error' => 'Payment system not configured.' ], 503 );
		$mod = $this->modules->get_with_pricing( $module_id, $this->settings );
		if ( ! $mod ) return new WP_REST_Response( [ 'error' => 'Unknown module.' ], 400 );
		if ( (float) $mod['price'] <= 0 ) return new WP_REST_Response( [ 'error' => 'This module is free.' ], 400 );
		$order = $this->commerce->create_order( $module_id, (float) $mod['price'], $mod['currency'], 'PaxDesign — ' . $mod['label'], add_query_arg( [ 'pdx_capture' => '1', 'pdx_module' => $module_id ], home_url( '/' ) ), home_url( '/' ) );
		if ( is_wp_error( $order ) ) return new WP_REST_Response( [ 'error' => $order->get_error_message() ], 502 );
		PDX_Access::create_pending( $module_id, $order['id'], (float) $mod['price'], $mod['currency'] );
		PDX_Audit::log( 'commerce', 'order_created', [ 'module_id' => $module_id, 'order_id' => $order['id'], 'amount' => $mod['price'] ] );
		$approve_url = '';
		foreach ( $order['links'] ?? [] as $link ) {
			if ( $link['rel'] === 'approve' ) { $approve_url = $link['href']; break; }
		}
		return new WP_REST_Response( [ 'order_id' => $order['id'], 'approve_url' => $approve_url, 'amount' => $mod['price'], 'currency' => $mod['currency'] ], 200 );
	}

	/* ── PayPal: capture order ───────────────────────────── */

	public function pay_capture( WP_REST_Request $req ): WP_REST_Response {
		$order_id  = $req->get_param( 'order_id' );
		$module_id = $req->get_param( 'module_id' );
		if ( ! $this->commerce->is_configured() ) return new WP_REST_Response( [ 'error' => 'Payment system not configured.' ], 503 );
		$capture = $this->commerce->capture_order( $order_id );
		if ( is_wp_error( $capture ) ) return new WP_REST_Response( [ 'error' => $capture->get_error_message() ], 502 );
		if ( ( $capture['status'] ?? '' ) !== 'COMPLETED' ) return new WP_REST_Response( [ 'error' => 'Payment not completed.', 'status' => $capture['status'] ?? '' ], 402 );
		$payer_email = $capture['payer']['email_address'] ?? '';
		PDX_Access::activate( $order_id, $payer_email ?: null );
		if ( ! is_user_logged_in() ) {
			$token = PDX_Access::guest_token();
			if ( ! $token ) {
				$token = wp_generate_password( 32, false );
				setcookie( 'pdx_guest', $token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
			PDX_Access::grant_guest_access( $token, $module_id );
		}
		PDX_Audit::log( 'commerce', 'payment_captured', [ 'module_id' => $module_id, 'order_id' => $order_id, 'email' => $payer_email ], 'info' );
		PDX_Webhook::dispatch( 'payment.captured', [ 'module_id' => $module_id, 'order_id' => $order_id ] );
		return new WP_REST_Response( [ 'ok' => true, 'status' => 'active', 'module_id' => $module_id, 'message' => 'Payment confirmed. Access unlocked.' ], 200 );
	}

	/* ── Access status ───────────────────────────────────── */

	public function pay_status(): WP_REST_Response {
		$modules = $this->modules->all_with_pricing( $this->settings );
		$result  = [];
		foreach ( $modules as $id => $mod ) {
			if ( $mod['tier'] === 'free' ) {
				$result[ $id ] = [ 'status' => 'active', 'tier' => 'free', 'label' => 'Free' ];
			} else {
				$has = PDX_Access::has_access( $id );
				$result[ $id ] = [ 'status' => $has ? 'active' : 'locked', 'tier' => $mod['tier'], 'label' => $has ? 'Unlocked' : ( $mod['tier'] === 'preview' ? 'Preview Available' : 'Locked' ), 'price' => $mod['price'], 'currency' => $mod['currency'] ];
			}
		}
		return new WP_REST_Response( $result, 200 );
	}

	/* ── Project brief ───────────────────────────────────── */

	public function brief_submit( WP_REST_Request $req ): WP_REST_Response {
		$name    = sanitize_text_field( $req->get_param( 'name' ) ?? '' );
		$email   = sanitize_email( $req->get_param( 'email' ) ?? '' );
		$type    = sanitize_text_field( $req->get_param( 'type' ) ?? '' );
		$budget  = sanitize_text_field( $req->get_param( 'budget' ) ?? '' );
		$details = sanitize_textarea_field( $req->get_param( 'details' ) ?? '' );
		if ( ! $name || ! $email || ! $details ) return new WP_REST_Response( [ 'error' => 'Name, email, and details are required.' ], 400 );
		if ( ! is_email( $email ) ) return new WP_REST_Response( [ 'error' => 'Invalid email address.' ], 400 );
		wp_mail( get_option( 'admin_email' ), '[PaxDesign] New Project Brief from ' . $name, "Name: {$name}\nEmail: {$email}\nType: {$type}\nBudget: {$budget}\n\nDetails:\n{$details}", [ 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email ] );
		$log   = get_option( 'pdx_briefs', [] );
		$log[] = [ 'ts' => time(), 'name' => $name, 'email' => $email, 'type' => $type, 'budget' => $budget, 'details' => substr( $details, 0, 500 ) ];
		update_option( 'pdx_briefs', array_slice( $log, -200 ) );
		PDX_Audit::log( 'create', 'brief_submitted', [ 'name' => $name, 'email' => $email, 'type' => $type ] );
		return new WP_REST_Response( [ 'ok' => true, 'message' => "Brief received. We'll be in touch within 24 hours." ], 200 );
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
		$this->settings->save( $body );
		return new WP_REST_Response( [ 'ok' => true, 'settings' => $this->settings->all() ], 200 );
	}
}
