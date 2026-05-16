<?php
/**
 * PDX_CachePurge — purges every caching layer when the plugin updates.
 *
 * Layers handled:
 *   1. PDX transients (all pdx_* keys)
 *   2. WordPress object cache (wp_cache_flush)
 *   3. WP core rewrite rules
 *   4. Popular caching plugins (W3TC, WP Super Cache, WP Rocket,
 *      LiteSpeed Cache, Autoptimize, Breeze, Hummingbird, Cache Enabler,
 *      SG Optimizer, Kinsta MU, WP Engine MU, Cloudways Breeze)
 *   5. Cloudflare API (zone purge — optional, configured in admin)
 *   6. Asset minification caches (Autoptimize, WP Rocket minify)
 *
 * Triggered automatically on:
 *   - Plugin version change (detected on plugins_loaded)
 *   - Settings save (pdx_settings_saved action)
 *   - Manual admin button (admin_post_pdx_purge_cache)
 *   - REST endpoint POST /pdx/v1/cache/purge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_CachePurge {

	/** Option key that stores the last purged version. */
	const VERSION_OPT = 'pdx_purged_version';

	/** Option key for Cloudflare credentials. */
	const CF_OPT = 'pdx_cloudflare';

	/* ── Bootstrap ──────────────────────────────────────── */

	public static function init(): void {
		// Auto-purge when plugin version changes.
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_purge_on_update' ], 5 );

		// Purge on every settings save.
		add_action( 'pdx_settings_saved', [ __CLASS__, 'purge_all' ] );

		// Manual purge from admin.
		add_action( 'admin_post_pdx_purge_cache', [ __CLASS__, 'handle_admin_purge' ] );
	}

	/* ── Auto-purge on version change ───────────────────── */

	public static function maybe_purge_on_update(): void {
		$last = get_option( self::VERSION_OPT, '' );
		if ( $last === PDX_VERSION ) return;

		// Version changed — purge all layers and record new version.
		// This fires once per version bump, on the first WordPress load
		// after the plugin files are updated.
		self::purge_all();
		update_option( self::VERSION_OPT, PDX_VERSION, false );

		// Also delete any cached asset URLs WordPress may have stored.
		delete_transient( 'pdx_asset_urls' );
		wp_cache_delete( 'pdx_asset_urls' );
	}

	/* ── Master purge ───────────────────────────────────── */

	/**
	 * Purge every caching layer. Returns an array of results keyed by layer.
	 */
	public static function purge_all(): array {
		$results = [];

		$results['pdx_transients']  = self::purge_pdx_transients();
		$results['object_cache']    = self::purge_object_cache();
		$results['rewrite_rules']   = self::flush_rewrite_rules();
		$results['caching_plugins'] = self::purge_caching_plugins();
		$results['minify_cache']    = self::purge_minify_cache();
		$results['cloudflare']      = self::purge_cloudflare();

		do_action( 'pdx_cache_purged', $results );

		return $results;
	}

	/* ── Layer 1: PDX transients ────────────────────────── */

	public static function purge_pdx_transients(): bool {
		global $wpdb;

		// Delete all transients whose name starts with pdx_
		$like = $wpdb->esc_like( '_transient_pdx_' ) . '%';
		$keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		) );

		$deleted = 0;
		foreach ( $keys as $k ) {
			$name = preg_replace( '/^_transient_/', '', $k );
			if ( delete_transient( $name ) ) $deleted++;
		}

		// Also delete timeout entries
		$like2 = $wpdb->esc_like( '_transient_timeout_pdx_' ) . '%';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like2
		) );

		// Clear PDX in-process cache
		PDX_Cache::flush_prefix( '' );

		return true;
	}

	/* ── Layer 2: WP object cache ───────────────────────── */

	public static function purge_object_cache(): bool {
		wp_cache_flush();
		return true;
	}

	/* ── Layer 3: Rewrite rules ─────────────────────────── */

	public static function flush_rewrite_rules(): bool {
		flush_rewrite_rules( false );
		return true;
	}

	/* ── Layer 4: Caching plugins ───────────────────────── */

	public static function purge_caching_plugins(): array {
		$purged = [];

		// ── W3 Total Cache ──────────────────────────────
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'w3tc';
		}

		// ── WP Super Cache ──────────────────────────────
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$purged[] = 'wp_super_cache';
		}

		// ── WP Rocket ───────────────────────────────────
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'wp_rocket';
		}
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
		}

		// ── LiteSpeed Cache ─────────────────────────────
		if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
			LiteSpeed_Cache_API::purge_all();
			$purged[] = 'litespeed';
		}
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			do_action( 'litespeed_purge_all' );
			if ( ! in_array( 'litespeed', $purged ) ) $purged[] = 'litespeed';
		}

		// ── Autoptimize ─────────────────────────────────
		if ( class_exists( 'autoptimizeCache' ) ) {
			autoptimizeCache::clearall();
			$purged[] = 'autoptimize';
		}

		// ── Hummingbird ─────────────────────────────────
		if ( class_exists( '\Hummingbird\Core\Utils' ) ) {
			do_action( 'wphb_clear_page_cache' );
			$purged[] = 'hummingbird';
		}

		// ── Cache Enabler ───────────────────────────────
		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_total_cache' ) ) {
			Cache_Enabler::clear_total_cache();
			$purged[] = 'cache_enabler';
		}

		// ── SG Optimizer (SiteGround) ───────────────────
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$purged[] = 'sg_optimizer';
		}

		// ── Breeze (Cloudways) ──────────────────────────
		if ( class_exists( 'Breeze_Admin' ) ) {
			do_action( 'breeze_clear_all_cache' );
			$purged[] = 'breeze';
		}

		// ── Kinsta MU plugin ────────────────────────────
		if ( class_exists( 'Kinsta\Cache' ) ) {
			kinsta_cache_purge_all_cache();
			$purged[] = 'kinsta';
		}

		// ── WP Engine MU plugin ─────────────────────────
		if ( class_exists( 'WpeCommon' ) ) {
			if ( method_exists( 'WpeCommon', 'purge_memcached' ) )  WpeCommon::purge_memcached();
			if ( method_exists( 'WpeCommon', 'clear_maxcdn_cache' ) ) WpeCommon::clear_maxcdn_cache();
			if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) WpeCommon::purge_varnish_cache();
			$purged[] = 'wpe';
		}

		// ── Nginx Helper (Nginx FastCGI cache) ──────────
		if ( class_exists( 'Nginx_Helper' ) ) {
			do_action( 'rt_nginx_helper_purge_all' );
			$purged[] = 'nginx_helper';
		}

		// ── Comet Cache / ZenCache ──────────────────────
		if ( class_exists( 'comet_cache' ) ) {
			comet_cache::clear();
			$purged[] = 'comet_cache';
		}

		// ── Swift Performance ───────────────────────────
		if ( class_exists( 'Swift_Performance_Cache' ) ) {
			Swift_Performance_Cache::clear_all_cache();
			$purged[] = 'swift_performance';
		}

		// ── Varnish HTTP Purge ──────────────────────────
		if ( class_exists( 'VarnishPurger' ) ) {
			do_action( 'after_rocket_clean_domain' ); // triggers Varnish purge
			$purged[] = 'varnish';
		}

		return $purged;
	}

	/* ── Layer 5: Minification caches ───────────────────── */

	public static function purge_minify_cache(): bool {
		// Autoptimize
		if ( class_exists( 'autoptimizeCache' ) ) {
			autoptimizeCache::clearall();
		}

		// WP Rocket minify
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
		}

		// Delete any cached/minified versions of our own assets
		// (some hosts store minified copies in wp-content/cache/)
		$cache_dirs = [
			WP_CONTENT_DIR . '/cache/autoptimize/',
			WP_CONTENT_DIR . '/cache/min/',
			WP_CONTENT_DIR . '/cache/wp-rocket/',
		];
		foreach ( $cache_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				// Only delete files that reference our plugin slug
				self::delete_files_matching( $dir, 'paxdesign' );
			}
		}

		return true;
	}

	/**
	 * Recursively delete files in $dir whose name contains $needle.
	 */
	private static function delete_files_matching( string $dir, string $needle ): void {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $file->isFile() && str_contains( $file->getFilename(), $needle ) ) {
				@unlink( $file->getPathname() );
			}
		}
	}

	/* ── Layer 6: Cloudflare ────────────────────────────── */

	public static function purge_cloudflare(): array {
		$cf = get_option( self::CF_OPT, [] );

		$zone_id   = trim( $cf['zone_id']   ?? '' );
		$api_token = trim( $cf['api_token'] ?? '' );

		if ( ! $zone_id || ! $api_token ) {
			return [ 'skipped' => true, 'reason' => 'not_configured' ];
		}

		$response = wp_remote_post(
			"https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache",
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				],
				'body' => wp_json_encode( [ 'purge_everything' => true ] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return [
			'success'  => (bool) ( $body['success'] ?? false ),
			'errors'   => $body['errors']   ?? [],
			'messages' => $body['messages'] ?? [],
		];
	}

	/* ── Admin handler ──────────────────────────────────── */

	public static function handle_admin_purge(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'pdx_purge_cache', 'pdx_nonce' );

		$results = self::purge_all();

		// Store results for display on redirect
		set_transient( 'pdx_purge_results', $results, 60 );

		wp_safe_redirect( add_query_arg(
			[ 'page' => PDX_SLUG . '-cache', 'purged' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ── Cloudflare settings save ───────────────────────── */

	public static function save_cloudflare( array $data ): void {
		update_option( self::CF_OPT, [
			'zone_id'   => sanitize_text_field( $data['zone_id']   ?? '' ),
			'api_token' => sanitize_text_field( $data['api_token'] ?? '' ),
		], false );
	}

	public static function get_cloudflare(): array {
		return get_option( self::CF_OPT, [ 'zone_id' => '', 'api_token' => '' ] );
	}
}
