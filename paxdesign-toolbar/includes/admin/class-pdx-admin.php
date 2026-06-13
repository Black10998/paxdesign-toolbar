<?php
/**
 * Admin panel — registers menu pages, enqueues admin assets, handles form saves.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Admin {

	private string $page_hook = '';

	public function __construct(
		private PDX_Settings        $settings,
		private PDX_Module_Registry $modules
	) {
		add_action( 'admin_menu',              [ $this, 'register_menu' ] );
		add_filter( 'admin_body_class',        [ $this, 'admin_body_class' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue' ] );
		add_action( 'admin_post_pdx_save',     [ $this, 'handle_save' ] );
		add_action( 'admin_post_pdx_clear_log',[ $this, 'handle_clear_log' ] );
		add_action( 'admin_notices',           [ $this, 'admin_notices' ] );
		add_filter( 'plugin_action_links_' . PDX_SLUG . '/' . PDX_SLUG . '.php', [ $this, 'plugin_links' ] );
	}

	public function register_menu(): void {
		$this->page_hook = add_menu_page(
			__( 'PaxDesign Dock', 'paxdesign-toolbar' ),
			__( 'PaxDesign', 'paxdesign-toolbar' ),
			PDX_CAP,
			PDX_SLUG,
			[ $this, 'render_page' ],
			$this->menu_icon(),
			58
		);

		/* Hidden from WP flyout — in-app sidebar is the only nav (avoids stacked menus). */
		$hidden = null;
		add_submenu_page( $hidden, __( 'Modules',    'paxdesign-toolbar' ), __( 'Modules',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-modules',       [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Pricing',    'paxdesign-toolbar' ), __( 'Pricing',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-pricing',       [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'PayPal',     'paxdesign-toolbar' ), __( 'PayPal',     'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-payments',      [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Orders',     'paxdesign-toolbar' ), __( 'Orders',     'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-orders',        [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Customers',  'paxdesign-toolbar' ), __( 'Customers',  'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-customers',     [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'API Keys',   'paxdesign-toolbar' ), __( 'API Keys',   'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-api',           [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'UI & Style', 'paxdesign-toolbar' ), __( 'UI & Style', 'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-ui',            [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Webhooks',   'paxdesign-toolbar' ), __( 'Webhooks',   'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-webhooks',      [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Audit Log',  'paxdesign-toolbar' ), __( 'Audit Log',  'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-audit',         [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Privacy',    'paxdesign-toolbar' ), __( 'Privacy',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-privacy',       [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Roles',      'paxdesign-toolbar' ), __( 'Roles',      'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-roles',         [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Analytics',  'paxdesign-toolbar' ), __( 'Analytics',  'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-analytics',     [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Billing',    'paxdesign-toolbar' ), __( 'Billing',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-billing',       [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Teams',      'paxdesign-toolbar' ), __( 'Teams',      'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-teams',         [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Workers',    'paxdesign-toolbar' ), __( 'Workers',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-workers',       [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Dev Tokens', 'paxdesign-toolbar' ), __( 'Dev Tokens', 'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-dev-tokens',    [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Platform',   'paxdesign-toolbar' ), __( 'Platform',   'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-platform',      [ $this, 'render_page' ] );
		add_submenu_page( $hidden, __( 'Cache',      'paxdesign-toolbar' ), __( 'Cache',      'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-cache',         [ $this, 'render_page' ] );

		// Cache + Cloudflare handlers
		add_action( 'admin_post_pdx_save_cloudflare', [ $this, 'handle_save_cloudflare' ] );

		// Webhook form handlers
		add_action( 'admin_post_pdx_webhook_create', [ $this, 'handle_webhook_create' ] );
		add_action( 'admin_post_pdx_customer_action', [ $this, 'handle_customer_action' ] );
	}

	public function admin_body_class( string $classes ): string {
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( $page === PDX_SLUG || str_starts_with( $page, PDX_SLUG . '-' ) ) {
			$classes .= ' pdx-admin-app';
		}
		return $classes;
	}

	public function enqueue( string $hook ): void {
		if ( strpos( $hook, PDX_SLUG ) === false ) return;

		wp_enqueue_style(
			'pdx-tokens',
			PDX_URL . 'assets/css/pdx-tokens.css',
			[],
			PDX_VERSION
		);

		wp_enqueue_style(
			'pdx-admin',
			PDX_URL . 'assets/css/admin.css',
			[ 'pdx-tokens' ],
			PDX_VERSION
		);

		wp_enqueue_style(
			'pdx-unified-ui',
			PDX_URL . 'assets/css/pdx-unified-ui.css',
			[ 'pdx-admin', 'pdx-tokens' ],
			PDX_VERSION
		);

		wp_enqueue_script(
			'pdx-admin',
			PDX_URL . 'assets/js/admin.js',
			[],
			PDX_VERSION,
			true
		);

		wp_localize_script( 'pdx-admin', 'PDX_ADMIN', [
			'nonce'         => wp_create_nonce( 'pdx_admin_nonce' ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'restUrl'       => rest_url( 'pdx/v1' ),
			'canManage'     => current_user_can( PDX_CAP ),
			'requiredCap'   => PDX_CAP,
			'settings'      => $this->settings->all(),
			'modules'       => $this->modules->all(),
			'version'       => PDX_VERSION,
			'i18n'          => [
				'auditRun'       => __( 'Run Live Audit', 'paxdesign-toolbar' ),
				'auditRunning'   => __( 'Running live audit…', 'paxdesign-toolbar' ),
				'statsRun'       => __( 'View Raw JSON', 'paxdesign-toolbar' ),
				'statsRunning'   => __( 'Loading…', 'paxdesign-toolbar' ),
				'auditForbidden' => sprintf(
					/* translators: %s: WordPress capability slug */
					__( 'Access denied. The integration audit requires the "%s" capability (WordPress administrator).', 'paxdesign-toolbar' ),
					PDX_CAP
				),
				'auditNonce'     => __( 'REST session expired. Reload this page and try again.', 'paxdesign-toolbar' ),
				'auditFailed'    => __( 'Integration audit request failed.', 'paxdesign-toolbar' ),
				'auditPartial'   => __( 'Audit completed with provider errors — review the table below.', 'paxdesign-toolbar' ),
				'auditParseError'=> __( 'Server returned an invalid response. Check PHP error logs.', 'paxdesign-toolbar' ),
			],
		] );
	}

	public function render_page(): void {
		$tab = $this->current_tab();
		include PDX_DIR . 'templates/admin/page-' . $tab . '.php';
	}

	private function current_tab(): string {
		$page = sanitize_key( $_GET['page'] ?? PDX_SLUG );
		$map  = [
			PDX_SLUG                 => 'general',
			PDX_SLUG . '-modules'    => 'modules',
			PDX_SLUG . '-pricing'    => 'pricing',
			PDX_SLUG . '-payments'   => 'payments',
			PDX_SLUG . '-orders'     => 'orders',
			PDX_SLUG . '-customers'  => 'customers',
			PDX_SLUG . '-api'        => 'api',
			PDX_SLUG . '-ui'         => 'ui',
			PDX_SLUG . '-webhooks'   => 'webhooks',
			PDX_SLUG . '-audit'      => 'audit',
			PDX_SLUG . '-privacy'    => 'privacy',
			PDX_SLUG . '-roles'      => 'roles',
			PDX_SLUG . '-analytics'  => 'analytics',
			// v4
			PDX_SLUG . '-billing'    => 'billing',
			PDX_SLUG . '-teams'      => 'teams',
			PDX_SLUG . '-workers'    => 'workers',
			PDX_SLUG . '-dev-tokens' => 'dev-tokens',
			PDX_SLUG . '-platform'   => 'platform',
			PDX_SLUG . '-cache'      => 'cache',
		];
		return $map[ $page ] ?? 'general';
	}

	public function handle_save(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'pdx_save_settings', 'pdx_nonce' );

		$tab  = sanitize_key( $_POST['pdx_tab'] ?? 'general' );
		$data = $this->sanitize_tab( $tab, $_POST );

		$this->settings->save( $data );

		// Increment the config version token so the JS live-sync poll detects
		// the change and refreshes module state on the frontend immediately.
		update_option( 'pdx_config_version', (int) get_option( 'pdx_config_version', 0 ) + 1, false );

		// Bust any object-cache entries that may have baked in the old PDX_CONFIG.
		wp_cache_delete( 'pdx_js_config' );
		delete_transient( 'pdx_js_config' );

		// Fire action so PDX_CachePurge (and any other listeners) can flush.
		do_action( 'pdx_settings_saved', $tab, $data );

		wp_safe_redirect( add_query_arg( [
			'page'    => PDX_SLUG . ( $tab !== 'general' ? '-' . $tab : '' ),
			'updated' => '1',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function sanitize_tab( string $tab, array $post ): array {
		switch ( $tab ) {
			case 'pricing':
				$modules = $this->modules->all();
				$tiers   = [];
				$prices  = [];
				foreach ( $modules as $id => $_ ) {
					$tiers[ $id ]  = in_array( $post['module_tiers'][ $id ] ?? '', [ 'free', 'preview', 'paid', 'subscription' ], true )
						? $post['module_tiers'][ $id ]
						: 'free';
					$prices[ $id ] = max( 0, (float) ( $post['module_prices'][ $id ] ?? 0 ) );
				}
				return [ 'module_tiers' => $tiers, 'module_prices' => $prices ];

			case 'payments':
				return [
					'paypal' => [
						'mode'              => in_array( $post['paypal']['mode'] ?? '', [ 'sandbox', 'live' ], true ) ? $post['paypal']['mode'] : 'sandbox',
						'sandbox_client_id' => sanitize_text_field( $post['paypal']['sandbox_client_id'] ?? '' ),
						'sandbox_secret'    => sanitize_text_field( $post['paypal']['sandbox_secret']    ?? '' ),
						'live_client_id'    => sanitize_text_field( $post['paypal']['live_client_id']    ?? '' ),
						'live_secret'       => sanitize_text_field( $post['paypal']['live_secret']       ?? '' ),
						'currency'          => in_array( $post['paypal']['currency'] ?? 'USD', PDX_Commerce::supported_currencies(), true )
							? $post['paypal']['currency']
							: 'USD',
					],
				];

			case 'orders':
				return []; // read-only page

			case 'general':
				return [
					'enabled'     => isset( $post['enabled'] ),
					'contact_url' => esc_url_raw( $post['contact_url'] ?? '' ),
					'cta_primary_label'   => sanitize_text_field( $post['cta_primary_label']   ?? 'Start a project' ),
					'cta_secondary_label' => sanitize_text_field( $post['cta_secondary_label'] ?? 'Learn more' ),
				];

			case 'modules':
				$modules = $this->modules->all();
				$result  = [ 'modules' => [] ];
				foreach ( $modules as $id => $_ ) {
					$result['modules'][ $id ] = isset( $post['modules'][ $id ] );
				}
				return $result;

			case 'api':
				return [
					'api_keys' => [
						'openai'     => sanitize_text_field( $post['api_keys']['openai']     ?? '' ),
						'virustotal' => sanitize_text_field( $post['api_keys']['virustotal'] ?? '' ),
						'shodan'     => sanitize_text_field( $post['api_keys']['shodan']     ?? '' ),
						'hunter'     => sanitize_text_field( $post['api_keys']['hunter']     ?? '' ),
						'nvd'        => sanitize_text_field( $post['api_keys']['nvd']        ?? '' ),
						'abuseipdb'  => sanitize_text_field( $post['api_keys']['abuseipdb']  ?? '' ),
						'abusech'    => sanitize_text_field( $post['api_keys']['abusech']    ?? '' ),
						'ipapi'      => sanitize_text_field( $post['api_keys']['ipapi']      ?? '' ),
					],
				];

			case 'ui':
				return [
					'dock_position'  => in_array( $post['dock_position'] ?? '', [ 'left', 'right' ] ) ? $post['dock_position'] : 'left',
					'dock_theme'     => in_array( $post['dock_theme']    ?? '', [ 'dark', 'light', 'auto' ] ) ? $post['dock_theme'] : 'dark',
					'dock_size'      => in_array( $post['dock_size']     ?? '', [ 'compact', 'default', 'large' ] ) ? $post['dock_size'] : 'default',
					'accent_color'   => sanitize_hex_color( $post['accent_color'] ?? '#ffffff' ) ?: '#ffffff',
					'custom_css'     => wp_strip_all_tags( $post['custom_css'] ?? '' ),
					// Mobile
					'mobile_enabled'       => isset( $post['mobile_enabled'] ),
					'mobile_breakpoint'    => min( 1280, max( 320, absint( $post['mobile_breakpoint'] ?? 680 ) ) ),
					'mobile_dock_position' => in_array( $post['mobile_dock_position'] ?? '', [ 'under-header', 'bottom-center', 'bottom-left', 'bottom-right' ] ) ? $post['mobile_dock_position'] : 'under-header',
					'mobile_dock_height'   => min( 72, max( 36, absint( $post['mobile_dock_height'] ?? 48 ) ) ),
					'mobile_panel_height'  => min( 96, max( 50, absint( $post['mobile_panel_height'] ?? 90 ) ) ),
					'mobile_icon_size'     => min( 28, max( 0, absint( $post['mobile_icon_size'] ?? 0 ) ) ),
					'mobile_btn_size'      => min( 60, max( 0, absint( $post['mobile_btn_size'] ?? 0 ) ) ),
					'mobile_spacing'       => in_array( $post['mobile_spacing'] ?? '', [ 'default', 'compact', 'relaxed' ] ) ? $post['mobile_spacing'] : 'default',
					'mobile_scale'         => in_array( $post['mobile_scale'] ?? '', [ 'auto', 'fixed', 'fluid' ] ) ? $post['mobile_scale'] : 'auto',
					'mobile_compact'       => isset( $post['mobile_compact'] ),
					'mobile_safe_area'     => isset( $post['mobile_safe_area'] ),
					'mobile_swipe_close'   => isset( $post['mobile_swipe_close'] ),
					'mobile_hide_dock'     => isset( $post['mobile_hide_dock'] ),
				];

			case 'privacy':
				return [
					'analytics_enabled'   => isset( $post['analytics_enabled'] ),
					'log_interactions'    => isset( $post['log_interactions'] ),
					'gdpr_mode'           => isset( $post['gdpr_mode'] ),
					'data_retention_days' => absint( $post['data_retention_days'] ?? 30 ),
				];

			case 'roles':
				$roles = get_editable_roles();
				$selected = [];
				if ( isset( $post['show_to_roles'] ) && is_array( $post['show_to_roles'] ) ) {
					foreach ( $post['show_to_roles'] as $r ) {
						$r = sanitize_key( $r );
						if ( $r === 'all' || isset( $roles[ $r ] ) ) {
							$selected[] = $r;
						}
					}
				}
				return [
					'show_to_roles'       => $selected ?: [ 'all' ],
					'hide_for_logged_out' => isset( $post['hide_for_logged_out'] ),
					'hide_for_logged_in'  => isset( $post['hide_for_logged_in'] ),
				];

			case 'billing':
				return [
					'stripe' => [
						'secret_key'     => sanitize_text_field( $post['stripe']['secret_key']     ?? '' ),
						'pub_key'        => sanitize_text_field( $post['stripe']['pub_key']        ?? '' ),
						'webhook_secret' => sanitize_text_field( $post['stripe']['webhook_secret'] ?? '' ),
						'mode'           => in_array( $post['stripe']['mode'] ?? '', [ 'test', 'live' ], true )
							? $post['stripe']['mode']
							: 'test',
					],
				];

			default:
				return [];
		}
	}

	public function admin_notices(): void {
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( strpos( $page, PDX_SLUG ) === false ) {
			return;
		}

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'paxdesign-toolbar' ) . '</p></div>';
		}

		if ( isset( $_GET['pdx_checked'] ) ) {
			$status = PDX_Updater::instance()->get_status( false );
			if ( ! empty( $status['update_available'] ) ) {
				$msg = sprintf(
					/* translators: %s: latest version number */
					__( 'Update check complete. Version %s is available — use Plugins → Update to install.', 'paxdesign-toolbar' ),
					$status['latest']
				);
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Update check complete. You are on the latest release.', 'paxdesign-toolbar' ) . '</p></div>';
			}
		}

		if ( isset( $_GET['pdx_maintenance_fixed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Maintenance mode cleared.', 'paxdesign-toolbar' ) . '</p></div>';
		}

		if ( ! empty( $_GET['pdx_update_error'] ) ) {
			$err = sanitize_text_field( wp_unslash( $_GET['pdx_update_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}
	}

	public function plugin_links( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . PDX_SLUG ) ) . '">' . __( 'Settings', 'paxdesign-toolbar' ) . '</a>';
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . PDX_SLUG . '#pdx-updates' ) ) . '">' . __( 'Check for updates', 'paxdesign-toolbar' ) . '</a>';
		return $links;
	}

	public function handle_clear_log(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'pdx_clear_log', 'pdx_nonce' );
		delete_option( 'pdx_event_log' );
		wp_safe_redirect( add_query_arg( [ 'page' => PDX_SLUG . '-analytics', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** SVG helper used in module template */
	public function get_svg_icon_html( string $name ): string {
		return PDX_Icons::icon_html( $name );
	}

	private function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function handle_save_cloudflare(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'pdx_save_cloudflare', 'pdx_nonce' );

		PDX_CachePurge::save_cloudflare( [
			'zone_id'   => $_POST['cf_zone_id']   ?? '',
			'api_token' => $_POST['cf_api_token'] ?? '',
		] );

		wp_safe_redirect( add_query_arg(
			[ 'page' => PDX_SLUG . '-cache', 'updated' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_webhook_create(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'pdx_webhook_create' );

		$events = array_map( 'sanitize_key', (array) ( $_POST['wh_events'] ?? [] ) );
		PDX_Webhook::create( [
			'name'   => sanitize_text_field( $_POST['wh_name']   ?? '' ),
			'url'    => esc_url_raw( $_POST['wh_url']            ?? '' ),
			'secret' => sanitize_text_field( $_POST['wh_secret'] ?? '' ),
			'events' => $events,
			'active' => true,
		] );
		PDX_Audit::log( 'webhooks', 'webhook_created', [ 'name' => $_POST['wh_name'] ?? '' ] );

		wp_safe_redirect( add_query_arg( [ 'page' => PDX_SLUG . '-webhooks', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_webhook_delete(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		$id = sanitize_text_field( $_POST['wh_id'] ?? '' );
		check_admin_referer( 'pdx_webhook_delete_' . $id );
		PDX_Webhook::delete( $id );
		PDX_Audit::log( 'webhooks', 'webhook_deleted', [ 'id' => $id ] );

		wp_safe_redirect( add_query_arg( [ 'page' => PDX_SLUG . '-webhooks', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_customer_action(): void {
		if ( ! current_user_can( PDX_CAP ) ) {
			wp_die( 'Unauthorized', 403 );
		}
		check_admin_referer( 'pdx_customer_action', 'pdx_nonce' );

		$user_id = (int) ( $_POST['user_id'] ?? 0 );
		$action  = sanitize_key( $_POST['customer_action'] ?? '' );
		$redirect = add_query_arg(
			[ 'page' => PDX_SLUG . '-customers', 'customer_id' => $user_id, 'updated' => '1' ],
			admin_url( 'admin.php' )
		);

		if ( ! $user_id || ! PDX_Customers::is_customer( $user_id ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => PDX_SLUG . '-customers', 'error' => 'invalid' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		switch ( $action ) {
			case 'suspend':
				PDX_Customers::suspend( $user_id );
				break;
			case 'activate':
				PDX_Customers::activate( $user_id );
				break;
			case 'resend_verification':
				PDX_Customers::resend_verification( $user_id );
				break;
			case 'grant_module':
				PDX_Customers::grant_module( $user_id, sanitize_key( (string) ( $_POST['module_id'] ?? '' ) ), max( 0, (int) ( $_POST['grant_days'] ?? 0 ) ) );
				break;
			case 'revoke_module':
				PDX_Customers::revoke_module( $user_id, sanitize_key( (string) ( $_POST['module_id'] ?? '' ) ) );
				break;
			case 'extend_subscription':
				PDX_Customers::extend_subscription( $user_id, max( 1, (int) ( $_POST['extend_days'] ?? 30 ) ) );
				break;
			case 'save_notes':
				PDX_Customers::save_notes( $user_id, (string) ( $_POST['admin_notes'] ?? '' ) );
				break;
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
