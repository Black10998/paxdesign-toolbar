<?php
/**
 * Plugin Name:  PaxDesign Utility Dock
 * Plugin URI:   https://paxdesign.io
 * Description:  Enterprise-grade SaaS utility dock with AI services, cybersecurity intelligence, modular tools, and a full admin control panel.
 * Version:      2.1.0
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

// Plugin constants
define( 'PDX_VERSION',   '2.1.0' );
define( 'PDX_DIR',       plugin_dir_path( __FILE__ ) );
define( 'PDX_URL',       plugin_dir_url( __FILE__ ) );
define( 'PDX_SLUG',      'paxdesign-toolbar' );
define( 'PDX_OPT',       'pdx_settings' );
define( 'PDX_CAP',       'manage_options' );

// Autoload includes
require_once PDX_DIR . 'includes/class-pdx-loader.php';
require_once PDX_DIR . 'includes/class-pdx-settings.php';
require_once PDX_DIR . 'includes/class-pdx-frontend.php';
require_once PDX_DIR . 'includes/admin/class-pdx-admin.php';
require_once PDX_DIR . 'includes/admin/class-pdx-setup.php';
require_once PDX_DIR . 'includes/commerce/class-pdx-commerce.php';
require_once PDX_DIR . 'includes/commerce/class-pdx-access.php';
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

	private function __construct() {
		$this->loader   = new PDX_Loader();
		$this->settings = new PDX_Settings();
		$this->modules  = new PDX_Module_Registry();
		$this->commerce = new PDX_Commerce( $this->settings );
		$this->frontend = new PDX_Frontend( $this->settings, $this->modules );
		$this->admin    = new PDX_Admin( $this->settings, $this->modules );
		$this->setup    = new PDX_Setup();
		$this->rest     = new PDX_REST_API( $this->settings, $this->modules, $this->commerce );
		$this->loader->run();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

/**
 * Activation / deactivation hooks.
 */
register_activation_hook( __FILE__, static function () {
	PDX_Settings::install_defaults();
	PDX_Access::install();
	PDX_Setup::on_activate();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () {
	flush_rewrite_rules();
} );

/**
 * Boot after all plugins are loaded.
 */
add_action( 'plugins_loaded', static function () {
	PaxDesign_Toolbar::instance();
} );
