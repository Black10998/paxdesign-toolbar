<?php
/**
 * Plugin Name:  PaxDesign Utility Dock
 * Plugin URI:   https://paxdesign.io
 * Description:  Enterprise AI/Cyber SaaS dock — SSE real-time, command palette, IOC correlation graph, investigation board, team collaboration, billing enforcement, AI memory, and 84-endpoint REST API.
 * Version:      8.5.0
 * Update URI:   https://github.com/Black10998/paxdesign-toolbar
 * Author:       PaxDesign
 * Author URI:   https://paxdesign.io
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  paxdesign-toolbar
 * Domain Path:  /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDX_VERSION',   '8.5.0' );
define( 'PDX_DIR',       plugin_dir_path( __FILE__ ) );
define( 'PDX_URL',       plugin_dir_url( __FILE__ ) );
define( 'PDX_SLUG',      'paxdesign-toolbar' );
define( 'PDX_OPT',       'pdx_settings' );
define( 'PDX_CAP',       'manage_options' );

// Recovery layer (no PDX class dependencies) — maintenance + rollback before full bootstrap.
require_once PDX_DIR . 'includes/class-pdx-recovery.php';
PDX_Recovery::register();

if ( ! PDX_Recovery::install_is_healthy() ) {
	return;
}

// Core
require_once PDX_DIR . 'includes/class-pdx-loader.php';
require_once PDX_DIR . 'includes/class-pdx-settings.php';
require_once PDX_DIR . 'includes/class-pdx-icons.php';
require_once PDX_DIR . 'includes/class-pdx-frontend.php';

// Admin
require_once PDX_DIR . 'includes/admin/class-pdx-admin.php';
require_once PDX_DIR . 'includes/admin/class-pdx-setup.php';

// Commerce
require_once PDX_DIR . 'includes/commerce/class-pdx-commerce.php';
require_once PDX_DIR . 'includes/commerce/class-pdx-access.php';

// v3 enterprise systems
require_once PDX_DIR . 'includes/class-pdx-audit.php';
require_once PDX_DIR . 'includes/class-pdx-queue.php';
require_once PDX_DIR . 'includes/class-pdx-workspace.php';
require_once PDX_DIR . 'includes/class-pdx-webhook.php';
require_once PDX_DIR . 'includes/class-pdx-target.php';
require_once PDX_DIR . 'includes/class-pdx-http.php';
require_once PDX_DIR . 'includes/class-pdx-intelligence.php';
require_once PDX_DIR . 'includes/class-pdx-url-analyzer.php';
require_once PDX_DIR . 'includes/class-pdx-threat-feeds.php';
require_once PDX_DIR . 'includes/class-pdx-scan-orchestrator.php';
require_once PDX_DIR . 'includes/class-pdx-ai-service.php';
require_once PDX_DIR . 'includes/class-pdx-conversation.php';
require_once PDX_DIR . 'includes/class-pdx-flow-store.php';
require_once PDX_DIR . 'includes/class-pdx-workflow-engine.php';
require_once PDX_DIR . 'includes/class-pdx-browser-automation.php';

// v4 platform infrastructure (load order matters)
require_once PDX_DIR . 'includes/class-pdx-event-bus.php';
require_once PDX_DIR . 'includes/class-pdx-cache.php';
require_once PDX_DIR . 'includes/class-pdx-cache-purge.php';
require_once PDX_DIR . 'includes/class-pdx-rate-limit.php';
require_once PDX_DIR . 'includes/class-pdx-container.php';
require_once PDX_DIR . 'includes/class-pdx-billing.php';
require_once PDX_DIR . 'includes/class-pdx-correlation.php';
require_once PDX_DIR . 'includes/class-pdx-worker.php';
require_once PDX_DIR . 'includes/class-pdx-memory.php';
require_once PDX_DIR . 'includes/class-pdx-team.php';

// API + Modules
require_once PDX_DIR . 'includes/api/class-pdx-sse.php';
require_once PDX_DIR . 'includes/api/class-pdx-rest-api.php';
require_once PDX_DIR . 'includes/modules/class-pdx-module-registry.php';
require_once PDX_DIR . 'includes/class-pdx-updater.php';

/**
 * Main plugin class — singleton bootstrap.
 */
final class PaxDesign_Toolbar {

	private static ?self $instance = null;

	// v3
	public PDX_Loader          $loader;
	public PDX_Settings        $settings;
	public PDX_Frontend        $frontend;
	public PDX_Admin           $admin;
	public PDX_Setup           $setup;
	public PDX_Commerce        $commerce;
	public PDX_REST_API        $rest;
	public PDX_Module_Registry $modules;
	public PDX_Intelligence    $intel;
	// v4
	public PDX_EventBus        $event_bus;
	public PDX_Container       $container;
	public PDX_SSE             $sse;

	private function __construct() {
		// v4 infrastructure — boot first
		$this->event_bus = PDX_EventBus::instance();
		$this->container = PDX_Container::instance();
		PDX_Cache::init();
		PDX_RateLimit::init();
		PDX_CachePurge::init();

		// Core
		$this->loader   = new PDX_Loader();
		$this->settings = new PDX_Settings();
		$this->modules  = new PDX_Module_Registry();
		$this->commerce = new PDX_Commerce( $this->settings );
		$this->intel    = new PDX_Intelligence( $this->settings );
		$this->frontend = new PDX_Frontend( $this->settings, $this->modules );
		$this->admin    = new PDX_Admin( $this->settings, $this->modules );
		$this->setup    = new PDX_Setup();

		// v4 services — register instantiable objects in container
		// (all-static classes like PDX_Billing, PDX_Correlation, etc. are used directly)
		$this->container->bind_instance( 'settings', $this->settings );
		$this->container->bind_instance( 'modules',  $this->modules );
		$this->container->bind_instance( 'commerce', $this->commerce );
		$this->container->bind_instance( 'intel',    $this->intel );

		// REST API + SSE
		$this->rest = new PDX_REST_API( $this->settings, $this->modules, $this->commerce, $this->intel );
		$this->sse  = new PDX_SSE();

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
		PDX_Worker::check_heartbeats();
		PDX_Cache::flush_expired();
		PDX_RateLimit::prune();
		PDX_Memory::prune_old( 90 );
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
	PDX_Billing::install();
	PDX_Correlation::install();
	PDX_Worker::install();
	PDX_Memory::install();
	PDX_Team::install();
	PDX_RateLimit::install();
	PDX_Setup::on_activate();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( 'pdx_daily_maintenance' );
	flush_rewrite_rules();
} );

add_action( 'plugins_loaded', static function () {
	if ( ! PDX_Recovery::install_is_healthy() ) {
		return;
	}
	try {
		PaxDesign_Toolbar::instance();
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[PDX] Bootstrap failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}
		if ( class_exists( 'PDX_Recovery', false ) ) {
			PDX_Recovery::restore_from_backup();
			PDX_Recovery::release_maintenance_file();
		}
	}
}, 20 );

/* ── Global helpers ──────────────────────────────────────── */

/**
 * Returns the PDX_Settings instance.
 */
function pdx_settings(): PDX_Settings {
	return PaxDesign_Toolbar::instance()->settings;
}

/**
 * Returns the service container instance.
 */
function pdx_container(): PDX_Container {
	return PDX_Container::instance();
}

/* ── Admin-post handlers (v4) ────────────────────────────── */

add_action( 'admin_post_pdx_deregister_worker', static function () {
	if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
	check_admin_referer( 'pdx_deregister_worker' );
	$worker_id = sanitize_text_field( $_GET['worker_id'] ?? '' );
	if ( $worker_id ) PDX_Worker::deregister( $worker_id );
	wp_safe_redirect( admin_url( 'admin.php?page=' . PDX_SLUG . '-workers&deregistered=1' ) );
	exit;
} );

add_action( 'admin_post_pdx_save_settings', static function () {
	if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
	check_admin_referer( 'pdx_save_settings', 'pdx_nonce' );
	$tab = sanitize_key( $_POST['pdx_tab'] ?? 'general' );
	// Billing tab — Stripe keys
	if ( $tab === 'billing' && isset( $_POST['stripe'] ) ) {
		$stripe = [
			'secret_key'     => sanitize_text_field( $_POST['stripe']['secret_key']     ?? '' ),
			'pub_key'        => sanitize_text_field( $_POST['stripe']['pub_key']         ?? '' ),
			'webhook_secret' => sanitize_text_field( $_POST['stripe']['webhook_secret']  ?? '' ),
			'mode'           => in_array( $_POST['stripe']['mode'] ?? '', [ 'test', 'live' ], true ) ? $_POST['stripe']['mode'] : 'test',
		];
		pdx_settings()->save( [ 'stripe' => $stripe ] );
	}
	wp_safe_redirect( add_query_arg( [ 'page' => PDX_SLUG . '-billing', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
	exit;
} );
