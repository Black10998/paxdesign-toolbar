<?php
/**
 * Plugin Name:  PaxDesign Utility Dock
 * Plugin URI:   https://paxdesign.io
 * Description:  Enterprise AI/Cyber SaaS dock — threat intelligence, AI agents, browser automation, multi-agent pipelines, connectors, persistent workspaces, audit logs, and a full admin control panel.
 * Version:      3.0.0
 * Author:       PaxDesign
 * Author URI:   https://paxdesign.io
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  paxdesign-toolbar
 * Domain Path:  /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDX_VERSION',   '3.0.0' );
define( 'PDX_DIR',       plugin_dir_path( __FILE__ ) );
define( 'PDX_URL',       plugin_dir_url( __FILE__ ) );
define( 'PDX_SLUG',      'paxdesign-toolbar' );
define( 'PDX_OPT',       'pdx_settings' );
define( 'PDX_CAP',       'manage_options' );

// Core
require_once PDX_DIR . 'includes/class-pdx-loader.php';
require_once PDX_DIR . 'includes/class-pdx-settings.php';
require_once PDX_DIR . 'includes/class-pdx-frontend.php';

// Admin
require_once PDX_DIR . 'includes/admin/class-pdx-admin.php';
require_once PDX_DIR . 'includes/admin/class-pdx-setup.php';

// Commerce
require_once PDX_DIR . 'includes/commerce/class-pdx-commerce.php';
require_once PDX_DIR . 'includes/commerce/class-pdx-access.php';

// Enterprise systems
require_once PDX_DIR . 'includes/class-pdx-audit.php';
require_once PDX_DIR . 'includes/class-pdx-queue.php';
require_once PDX_DIR . 'includes/class-pdx-workspace.php';
require_once PDX_DIR . 'includes/class-pdx-webhook.php';
require_once PDX_DIR . 'includes/class-pdx-intelligence.php';

// API + Modules
require_once PDX_DIR . 'includes/api/class-pdx-rest-api.php';
require_once PDX_DIR . 'includes/modules/class-pdx-module-registry.php';

/**
 * Main plugin class — singleton bootstrap.
 */
final class PaxDesign_Toolbar {

	private static ?self $instance = null;

	public PDX_Loader          $loader;
	public PDX_Settings        $settings;
	public PDX_Frontend        $frontend;
	public PDX_Admin           $admin;
	public PDX_Setup           $setup;
	public PDX_Commerce        $commerce;
	public PDX_REST_API        $rest;
	public PDX_Module_Registry $modules;
	public PDX_Intelligence    $intel;

	private function __construct() {
		$this->loader   = new PDX_Loader();
		$this->settings = new PDX_Settings();
		$this->modules  = new PDX_Module_Registry();
		$this->commerce = new PDX_Commerce( $this->settings );
		$this->intel    = new PDX_Intelligence( $this->settings );
		$this->frontend = new PDX_Frontend( $this->settings, $this->modules );
		$this->admin    = new PDX_Admin( $this->settings, $this->modules );
		$this->setup    = new PDX_Setup();
		$this->rest     = new PDX_REST_API( $this->settings, $this->modules, $this->commerce, $this->intel );
		$this->loader->run();

		// Scheduled maintenance
		add_action( 'pdx_daily_maintenance', [ $this, 'run_maintenance' ] );
		if ( ! wp_next_scheduled( 'pdx_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'pdx_daily_maintenance' );
		}
	}

	public function run_maintenance(): void {
		PDX_Audit::prune();
		PDX_Queue::prune_expired();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

register_activation_hook( __FILE__, static function () {
	PDX_Settings::install_defaults();
	PDX_Access::install();
	PDX_Audit::install();
	PDX_Queue::install();
	PDX_Workspace::install();
	PDX_Webhook::install();
	PDX_Setup::on_activate();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( 'pdx_daily_maintenance' );
	flush_rewrite_rules();
} );

add_action( 'plugins_loaded', static function () {
	PaxDesign_Toolbar::instance();
} );
