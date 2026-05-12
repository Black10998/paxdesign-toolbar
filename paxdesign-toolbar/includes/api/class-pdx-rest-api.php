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
		private PDX_Commerce        $commerce
	) {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$ns = 'pdx/v1';

		register_rest_route( $ns, '/trust', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'trust_check' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'domain' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/i', $v ),
				],
			],
		] );

		register_rest_route( $ns, '/ai/chat', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ai_chat' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/osint/scan', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'osint_scan' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/brief/submit', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'brief_submit' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/pay/create', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'pay_create' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'module_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( $ns, '/pay/capture', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'pay_capture' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'order_id'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'module_id' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( $ns, '/pay/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'pay_status' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/event', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'log_event' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'module' => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'action' => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'meta'   => [ 'required' => false, 'default' => [] ],
			],
		] );

		register_rest_route( $ns, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => static fn() => current_user_can( PDX_CAP ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => static fn() => current_user_can( PDX_CAP ),
			],
		] );
	}

	/* ── Trust check ─────────────────────────────────────── */

	public function trust_check( WP_REST_Request $req ): WP_REST_Response {
		$domain = strtolower( $req->get_param( 'domain' ) );
		return new WP_REST_Response( [
			'domain' => $domain,
			'rdap'   => $this->fetch_rdap( $domain ),
			'ssl'    => $this->fetch_ssl( $domain ),
		], 200 );
	}

	private function fetch_rdap( string $domain ): ?array {
		$resp = wp_remote_get( 'https://rdap.org/domain/' . rawurlencode( $domain ), [ 'timeout' => 8 ] );
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : null;
	}

	private function fetch_ssl( string $domain ): ?array {
		$url  = 'https://api.ssllabs.com/api/v3/analyze?host=' . rawurlencode( $domain ) . '&fromCache=on&maxAge=24&all=done';
		$resp = wp_remote_get( $url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : null;
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
		$result = [ 'target' => $target, 'paid' => $paid, 'sections' => [] ];

		$rdap = $this->fetch_rdap( $target );
		if ( $rdap ) {
			$reg_date = null; $registrar = null;
			foreach ( $rdap['events'] ?? [] as $e ) {
				if ( $e['eventAction'] === 'registration' ) { $reg_date = $e['eventDate']; break; }
			}
			foreach ( $rdap['entities'] ?? [] as $ent ) {
				$vc = $ent['vcardArray'][1] ?? [];
				foreach ( $vc as $v ) { if ( $v[0] === 'fn' ) { $registrar = $v[3]; break 2; } }
			}
			$result['sections']['registration'] = [
				'label' => 'Domain Registration', 'free' => true,
				'data'  => [
					'Registrar'    => $registrar ?? 'Unknown',
					'Registered'   => $reg_date ? substr( $reg_date, 0, 10 ) : 'Unknown',
					'Status'       => implode( ', ', array_slice( $rdap['status'] ?? [], 0, 3 ) ) ?: 'Unknown',
					'Name Servers' => implode( ', ', array_map( fn($n) => $n['ldhName'] ?? '', array_slice( $rdap['nameservers'] ?? [], 0, 4 ) ) ),
				],
			];
		}

		$ssl = $this->fetch_ssl( $target );
		if ( $ssl && ! empty( $ssl['endpoints'] ) ) {
			$ep = $ssl['endpoints'][0];
			$result['sections']['ssl'] = [
				'label' => 'SSL / TLS', 'free' => true,
				'data'  => [
					'Grade'    => $ep['grade']       ?? 'N/A',
					'IP'       => $ep['ipAddress']   ?? 'N/A',
					'Status'   => $ep['statusMessage'] ?? 'Unknown',
				],
			];
		}

		if ( ! $paid && ( $mod['tier'] ?? 'paid' ) !== 'free' ) {
			$result['paywall'] = [
				'module_id' => $module_id,
				'price'     => $mod['price'],
				'currency'  => $mod['currency'],
				'message'   => 'Unlock full report: IP geolocation, breach data, VirusTotal scan.',
			];
			return new WP_REST_Response( $result, 200 );
		}

		$ip_resp = wp_remote_get( 'http://ip-api.com/json/' . rawurlencode( $target ) . '?fields=status,country,regionName,city,isp,org,as,query', [ 'timeout' => 6 ] );
		if ( ! is_wp_error( $ip_resp ) ) {
			$ip = json_decode( wp_remote_retrieve_body( $ip_resp ), true );
			if ( ( $ip['status'] ?? '' ) === 'success' ) {
				$result['sections']['geolocation'] = [
					'label' => 'IP Geolocation', 'free' => false,
					'data'  => [
						'IP' => $ip['query'] ?? 'N/A', 'Country' => $ip['country'] ?? 'N/A',
						'Region' => $ip['regionName'] ?? 'N/A', 'City' => $ip['city'] ?? 'N/A',
						'ISP' => $ip['isp'] ?? 'N/A', 'Org' => $ip['org'] ?? 'N/A',
					],
				];
			}
		}

		$vt_key = $this->settings->get( 'api_keys.virustotal', '' );
		if ( $vt_key ) {
			$vt_resp = wp_remote_get( 'https://www.virustotal.com/api/v3/domains/' . rawurlencode( $target ), [
				'timeout' => 10, 'headers' => [ 'x-apikey' => $vt_key ],
			] );
			if ( ! is_wp_error( $vt_resp ) && wp_remote_retrieve_response_code( $vt_resp ) === 200 ) {
				$vt    = json_decode( wp_remote_retrieve_body( $vt_resp ), true );
				$stats = $vt['data']['attributes']['last_analysis_stats'] ?? [];
				$result['sections']['virustotal'] = [
					'label' => 'VirusTotal', 'free' => false,
					'data'  => [
						'Malicious'  => $stats['malicious']  ?? 0,
						'Suspicious' => $stats['suspicious'] ?? 0,
						'Clean'      => $stats['undetected'] ?? 0,
						'Reputation' => $vt['data']['attributes']['reputation'] ?? 'N/A',
					],
				];
			}
		}

		return new WP_REST_Response( $result, 200 );
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
}
