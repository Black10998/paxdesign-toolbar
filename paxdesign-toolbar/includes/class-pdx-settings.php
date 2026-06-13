<?php
/**
 * Settings manager — single option key, typed accessors, defaults.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Settings {

	/** Default configuration */
	public static function defaults(): array {
		return [
			// General
			'enabled'              => true,
			'version'              => PDX_VERSION,

			// Visibility / roles
			'show_to_roles'        => [ 'all' ],   // 'all' | array of WP role slugs
			'hide_for_logged_out'  => false,
			'hide_for_logged_in'   => false,

			// UI
			'dock_position'        => 'left',      // left | right
			'dock_theme'           => 'dark',      // dark | light | auto
			'dock_size'            => 'default',   // compact | default | large
			'accent_color'         => '#ffffff',
			'custom_css'           => '',

			// Modules enabled
			'modules'              => [
				'trust'       => true,
				'osint'       => true,
				'threat'      => true,
				'personas'    => true,
				'builder'     => true,
				'pipeline'    => true,
				'automation'  => true,
				'connectors'  => true,
				'create'      => true,
				'workspace'   => true,
			],

			// API keys
			'api_keys'             => [
				'openai'      => '',
				'virustotal'  => '',
				'shodan'      => '',
				'hunter'      => '',
				'nvd'         => '',
				'abuseipdb'   => '',
				'abusech'     => '',
				'ipapi'       => '',
			],

			// Enterprise
			'webhooks_enabled'     => true,
			'audit_enabled'        => true,
			'workspace_enabled'    => true,
			'queue_enabled'        => true,
			'ai_memory_enabled'    => true,

			// Privacy
			'analytics_enabled'    => false,
			'log_interactions'     => false,
			'gdpr_mode'            => false,
			'data_retention_days'  => 30,

			// Contact / CTA
			'contact_url'          => '',
			'cta_primary_label'    => 'Start a project',
			'cta_secondary_label'  => 'Learn more',

			// Mobile
			'mobile_enabled'       => true,
			'mobile_breakpoint'    => 680,
			'mobile_dock_position' => 'under-header',  // under-header | bottom-center | bottom-left | bottom-right
			'mobile_dock_height'   => 48,              // px — dock bar height in under-header mode
			'mobile_panel_height'  => 90,              // % of viewport height (50–96)
			'mobile_icon_size'     => 0,               // px — 0 = CSS default
			'mobile_btn_size'      => 0,               // px — 0 = CSS default
			'mobile_spacing'       => 'default',       // default | compact | relaxed
			'mobile_scale'         => 'auto',          // auto | fixed | fluid
			'mobile_compact'       => false,           // compact mode
			'mobile_safe_area'     => true,            // respect env(safe-area-inset-*)
			'mobile_swipe_close'   => true,            // swipe to close panel
			'mobile_hide_dock'     => true,            // hide dock when panel is open

			// PayPal
			'paypal'               => [
				'mode'              => 'sandbox',
				'sandbox_client_id' => '',
				'sandbox_secret'    => '',
				'live_client_id'    => '',
				'live_secret'       => '',
				'currency'          => 'USD',
			],

			// Per-module tiers and prices (keyed by module_id)
			'module_tiers'         => [],
			'module_prices'        => [],
		];
	}

	/** Install defaults on first activation */
	public static function install_defaults(): void {
		if ( ! get_option( PDX_OPT ) ) {
			add_option( PDX_OPT, self::defaults() );
		}
	}

	/** Get all settings, merged with defaults */
	public function all(): array {
		$saved = get_option( PDX_OPT, [] );
		return array_replace_recursive( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	/** Get a single setting by dot-notation key */
	public function get( string $key, $fallback = null ) {
		if ( str_starts_with( $key, 'api_keys.' ) && is_user_logged_in() && class_exists( 'PDX_Account', false ) ) {
			$provider = substr( $key, 9 );
			$user_key = PDX_Account::get_user_api_key( get_current_user_id(), $provider );
			if ( '' !== $user_key ) {
				return $user_key;
			}
		}
		$all  = $this->all();
		$keys = explode( '.', $key );
		$val  = $all;
		foreach ( $keys as $k ) {
			if ( ! is_array( $val ) || ! array_key_exists( $k, $val ) ) {
				return $fallback;
			}
			$val = $val[ $k ];
		}
		return $val;
	}

	/** Persist settings (sanitised array) */
	public function save( array $data ): bool {
		$current = $this->all();
		$merged  = array_replace_recursive( $current, $data );
		return update_option( PDX_OPT, $merged );
	}

	/** Check if a module is enabled */
	public function module_enabled( string $module ): bool {
		return (bool) $this->get( "modules.{$module}", true );
	}

	/** Resolve contact URL — falls back to page search then home */
	public function contact_url(): string {
		$url = $this->get( 'contact_url' );
		if ( $url ) return esc_url( $url );

		$p = get_page_by_path( 'contact' );
		if ( $p ) return get_permalink( $p->ID );

		$pages = get_pages( [ 'search' => 'contact', 'number' => 1 ] );
		if ( ! empty( $pages ) ) return get_permalink( $pages[0]->ID );

		return home_url( '/#contact' );
	}

	/** Check if dock should render for current visitor */
	public function should_render(): bool {
		if ( ! $this->get( 'enabled' ) ) return false;

		$hide_out = $this->get( 'hide_for_logged_out' );
		$hide_in  = $this->get( 'hide_for_logged_in' );

		$blocked = false;
		if ( $hide_out && ! is_user_logged_in() ) {
			$blocked = true;
		}
		if ( $hide_in  && is_user_logged_in() ) {
			$blocked = true;
		}

		$roles = $this->get( 'show_to_roles', [ 'all' ] );
		if ( ! in_array( 'all', $roles, true ) && is_user_logged_in() ) {
			$user = wp_get_current_user();
			$intersect = array_intersect( $roles, (array) $user->roles );
			if ( empty( $intersect ) ) {
				$blocked = true;
			}
		}

		// v9 auth UX requirement: always render frontend dock so protected modules
		// can trigger login/register gating instead of disappearing entirely.
		// This prevents misconfigured visibility settings from removing navigation.
		if ( $blocked ) {
			if ( ! is_admin() && class_exists( 'PDX_Auth', false ) ) {
				return true;
			}
			return false;
		}

		return true;
	}

	/**
	 * abuse.ch Auth-Key header (required for URLhaus API since June 2025).
	 *
	 * @return array<string, string>
	 */
	public function abusech_auth_headers(): array {
		$key = (string) $this->get( 'api_keys.abusech', '' );
		return '' !== $key ? [ 'Auth-Key' => $key ] : [];
	}
}
