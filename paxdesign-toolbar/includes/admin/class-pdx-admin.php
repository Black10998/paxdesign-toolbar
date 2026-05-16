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

		add_submenu_page( PDX_SLUG, __( 'General',    'paxdesign-toolbar' ), __( 'General',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG,                    [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Modules',    'paxdesign-toolbar' ), __( 'Modules',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-modules',       [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Pricing',    'paxdesign-toolbar' ), __( 'Pricing',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-pricing',       [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'PayPal',     'paxdesign-toolbar' ), __( 'PayPal',     'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-payments',      [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Orders',     'paxdesign-toolbar' ), __( 'Orders',     'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-orders',        [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'API Keys',   'paxdesign-toolbar' ), __( 'API Keys',   'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-api',           [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'UI & Style', 'paxdesign-toolbar' ), __( 'UI & Style', 'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-ui',            [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Webhooks',   'paxdesign-toolbar' ), __( 'Webhooks',   'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-webhooks',      [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Audit Log',  'paxdesign-toolbar' ), __( 'Audit Log',  'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-audit',         [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Privacy',    'paxdesign-toolbar' ), __( 'Privacy',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-privacy',       [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Roles',      'paxdesign-toolbar' ), __( 'Roles',      'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-roles',         [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Analytics',  'paxdesign-toolbar' ), __( 'Analytics',  'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-analytics',     [ $this, 'render_page' ] );
		// v4 pages
		add_submenu_page( PDX_SLUG, __( 'Billing',    'paxdesign-toolbar' ), __( 'Billing',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-billing',       [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Teams',      'paxdesign-toolbar' ), __( 'Teams',      'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-teams',         [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Workers',    'paxdesign-toolbar' ), __( 'Workers',    'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-workers',       [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Dev Tokens', 'paxdesign-toolbar' ), __( 'Dev Tokens', 'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-dev-tokens',    [ $this, 'render_page' ] );
		add_submenu_page( PDX_SLUG, __( 'Platform',   'paxdesign-toolbar' ), __( 'Platform',   'paxdesign-toolbar' ), PDX_CAP, PDX_SLUG . '-platform',      [ $this, 'render_page' ] );

		// Webhook form handlers
		add_action( 'admin_post_pdx_webhook_create', [ $this, 'handle_webhook_create' ] );
		add_action( 'admin_post_pdx_webhook_delete', [ $this, 'handle_webhook_delete' ] );
	}

	public function enqueue( string $hook ): void {
		if ( strpos( $hook, PDX_SLUG ) === false ) return;

		wp_enqueue_style(
			'pdx-admin',
			PDX_URL . 'assets/css/admin.css',
			[],
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
			'nonce'    => wp_create_nonce( 'pdx_admin_nonce' ),
			'restUrl'  => rest_url( 'pdx/v1' ),
			'settings' => $this->settings->all(),
			'modules'  => $this->modules->all(),
			'version'  => PDX_VERSION,
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
		];
		return $map[ $page ] ?? 'general';
	}

	public function handle_save(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'pdx_save_settings', 'pdx_nonce' );

		$tab  = sanitize_key( $_POST['pdx_tab'] ?? 'general' );
		$data = $this->sanitize_tab( $tab, $_POST );

		$this->settings->save( $data );

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
					],
				];

			case 'ui':
				return [
					'dock_position'  => in_array( $post['dock_position'] ?? '', [ 'left', 'right' ] ) ? $post['dock_position'] : 'left',
					'dock_theme'     => in_array( $post['dock_theme']    ?? '', [ 'dark', 'light', 'auto' ] ) ? $post['dock_theme'] : 'dark',
					'dock_size'      => in_array( $post['dock_size']     ?? '', [ 'compact', 'default', 'large' ] ) ? $post['dock_size'] : 'default',
					'accent_color'   => sanitize_hex_color( $post['accent_color'] ?? '#3fb950' ) ?: '#3fb950',
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

			default:
				return [];
		}
	}

	public function admin_notices(): void {
		if ( ! isset( $_GET['updated'] ) ) return;
		if ( strpos( sanitize_key( $_GET['page'] ?? '' ), PDX_SLUG ) === false ) return;
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'paxdesign-toolbar' ) . '</p></div>';
	}

	public function plugin_links( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . PDX_SLUG ) ) . '">' . __( 'Settings', 'paxdesign-toolbar' ) . '</a>';
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
		$icons = [
			'shield'   => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
			'plus'     => 'M12 5v14M5 12h14',
			'user'     => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2',
			'grid'     => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3z',
			'search'   => 'M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z',
			'link'     => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
			'layers'   => 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
			'pipeline' => 'M5 12m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M19 5m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M19 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M7 12h4l4-5M7 12l4 1 4 4',
		];
		$d = $icons[ $name ] ?? 'M12 12m-8 0a8 8 0 1 0 16 0a8 8 0 1 0-16 0';
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="' . esc_attr( $d ) . '"/></svg>';
	}

	private function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
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
}
