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
			'accent_color'         => '#3fb950',
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
			'mobile_dock_position' => 'bottom-center', // bottom-center | bottom-left | bottom-right
			'mobile_panel_height'  => 90,              // % of viewport height (50–96)
			'mobile_swipe_close'   => true,            // swipe-down to close panel
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

		if ( $hide_out && ! is_user_logged_in() ) return false;
		if ( $hide_in  &&   is_user_logged_in() ) return false;

		$roles = $this->get( 'show_to_roles', [ 'all' ] );
		if ( ! in_array( 'all', $roles, true ) && is_user_logged_in() ) {
			$user = wp_get_current_user();
			$intersect = array_intersect( $roles, (array) $user->roles );
			if ( empty( $intersect ) ) return false;
		}

		return true;
	}
}
