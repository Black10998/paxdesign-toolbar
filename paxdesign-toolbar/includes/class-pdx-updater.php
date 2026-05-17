<?php
/**
 * GitHub release updater — production-grade update flow for wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Updater {

	private const GITHUB_REPO         = 'Black10998/paxdesign-toolbar';
	private const PLUGIN_FOLDER       = 'paxdesign-toolbar';
	private const PLUGIN_MAIN_FILE    = 'paxdesign-toolbar.php';
	private const PLUGIN_TEXT_DOMAIN  = 'paxdesign-toolbar';
	private const CACHE_KEY           = 'pdx_github_release';
	private const LAST_CHECK_OPTION   = 'pdx_updater_last_checked';
	private const STATE_OPTION        = 'pdx_updater_state';
	private const CACHE_TTL           = 43200;
	private const HTTP_TIMEOUT        = 45;
	private const MAINTENANCE_MAX_AGE = 600;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 20, 3 );
		add_filter( 'http_request_args', [ $this, 'http_request_args' ], 10, 2 );
		add_filter( 'upgrader_package_options', [ $this, 'filter_package_options' ], 10, 1 );
		add_filter( 'upgrader_pre_install', [ $this, 'backup_before_install' ], 5, 2 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_github_source' ], 10, 4 );
		add_filter( 'upgrader_post_install', [ $this, 'verify_install' ], 10, 3 );
		add_filter( 'upgrader_install_package_result', [ $this, 'on_install_package_result' ], 10, 2 );

		add_action( 'plugins_loaded', [ $this, 'bootstrap_recovery' ], 1 );
		add_action( 'admin_init', [ $this, 'maybe_cleanup_stale_maintenance' ] );
		add_action( 'admin_init', [ $this, 'maybe_cleanup_duplicate_plugins_admin' ] );
		add_action( 'shutdown', [ $this, 'maybe_cleanup_stale_maintenance' ], 999 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrade_complete' ], 10, 2 );
		add_action( 'deleted_plugin', [ $this, 'on_deleted_plugin' ], 10, 2 );

		add_action( 'admin_post_pdx_check_updates', [ $this, 'handle_check_updates' ] );
		add_action( 'admin_post_pdx_clear_maintenance', [ $this, 'handle_clear_maintenance' ] );
	}

	public function plugin_basename(): string {
		return plugin_basename( PDX_DIR . 'paxdesign-toolbar.php' );
	}

	public function plugin_dir(): string {
		return WP_PLUGIN_DIR . '/' . self::PLUGIN_FOLDER;
	}

	/**
	 * @param array<string, mixed>|null $hook_extra
	 */
	private function is_our_plugin( ?array $hook_extra = null, ?string $plugin_basename = null ): bool {
		$basename = $plugin_basename ?? $this->plugin_basename();
		if ( $hook_extra ) {
			if ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $basename ) {
				return true;
			}
			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				foreach ( $hook_extra['plugins'] as $plugin ) {
					if ( $plugin === $basename ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	public function clear_all_caches(): void {
		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_cache_delete( 'plugins', 'plugins' );
	}

	/**
	 * @return array{installed:string,latest:?string,update_available:bool,last_checked:int,error:?string,package:?string,release_url:?string,maintenance_active:bool,maintenance_stale:bool,backup_available:bool,checked_at_formatted:?string}
	 */
	public function get_status( bool $force_refresh = false ): array {
		$release = $this->fetch_release( $force_refresh );
		$latest  = is_array( $release ) && ! empty( $release['version'] ) ? $release['version'] : null;
		$error   = is_array( $release ) && ! empty( $release['error'] ) ? (string) $release['error'] : null;

		$last_checked = (int) get_option( self::LAST_CHECK_OPTION, 0 );
		$maintenance  = $this->maintenance_file();
		$state        = $this->get_state();
		$backup       = ! empty( $state['backup'] ) && is_dir( (string) $state['backup'] );

		return [
			'installed'            => PDX_VERSION,
			'latest'               => $latest,
			'update_available'     => $latest && version_compare( PDX_VERSION, $latest, '<' ),
			'last_checked'         => $last_checked,
			'checked_at_formatted' => $last_checked ? wp_date( 'M j, Y g:i a', $last_checked ) : null,
			'error'                => $error,
			'package'              => is_array( $release ) ? ( $release['package'] ?? null ) : null,
			'release_url'          => is_array( $release ) ? ( $release['url'] ?? null ) : null,
			'maintenance_active'   => (bool) $maintenance,
			'maintenance_stale'    => $this->is_maintenance_stale( $maintenance ),
			'backup_available'     => $backup,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function fetch_release( bool $force = false ): ?array {
		if ( $force ) {
			delete_transient( self::CACHE_KEY );
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! $force ) {
			return $cached;
		}

		$url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$res = wp_remote_get(
			$url,
			[
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'PaxDesign-Toolbar-Updater/' . PDX_VERSION,
				],
			]
		);

		update_option( self::LAST_CHECK_OPTION, time() );

		if ( is_wp_error( $res ) ) {
			return [
				'error'   => $res->get_error_message(),
				'version' => '',
				'package' => '',
				'url'     => 'https://github.com/' . self::GITHUB_REPO,
				'name'    => 'PaxDesign Utility Dock',
				'notes'   => '',
			];
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== (int) $code ) {
			return [
				'error'   => sprintf( 'GitHub API returned HTTP %d.', (int) $code ),
				'version' => '',
				'package' => '',
				'url'     => 'https://github.com/' . self::GITHUB_REPO,
				'name'    => 'PaxDesign Utility Dock',
				'notes'   => '',
			];
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return [
				'error'   => 'Invalid release metadata from GitHub.',
				'version' => '',
				'package' => '',
				'url'     => 'https://github.com/' . self::GITHUB_REPO,
				'name'    => 'PaxDesign Utility Dock',
				'notes'   => '',
			];
		}

		$version = ltrim( (string) $body['tag_name'], 'vV' );
		$package = '';

		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				$name = $asset['name'] ?? '';
				if ( preg_match( '/^paxdesign-toolbar-.+\.zip$/i', $name ) ) {
					$package = $asset['browser_download_url'] ?? '';
					break;
				}
			}
		}

		if ( ! $package ) {
			return [
				'error'   => 'No installable release ZIP asset found on GitHub. Upload paxdesign-toolbar-x.y.z.zip to the release.',
				'version' => $version,
				'package' => '',
				'url'     => $body['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO,
				'name'    => 'PaxDesign Utility Dock',
				'notes'   => $body['body'] ?? '',
			];
		}

		$data = [
			'version' => $version,
			'package' => $package,
			'url'     => $body['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO,
			'name'    => 'PaxDesign Utility Dock',
			'notes'   => $body['body'] ?? '',
			'error'   => null,
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->fetch_release( false );
		if ( ! is_array( $release ) || ! empty( $release['error'] ) ) {
			return $transient;
		}
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( PDX_VERSION, $release['version'], '>=' ) ) {
			return $transient;
		}

		$plugin = $this->plugin_basename();
		$transient->response[ $plugin ] = (object) [
			'slug'        => PDX_SLUG,
			'plugin'      => $plugin,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
			'id'          => $plugin,
		];

		return $transient;
	}

	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== PDX_SLUG ) {
			return $result;
		}

		$release = $this->fetch_release( false );
		if ( ! is_array( $release ) || empty( $release['version'] ) ) {
			return $result;
		}

		return (object) [
			'name'          => $release['name'],
			'slug'          => PDX_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://paxdesign.io">PaxDesign</a>',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => [
				'description' => 'Enterprise utility dock for WordPress.',
				'changelog'   => wp_kses_post( $release['notes'] ),
			],
		];
	}

	public function http_request_args( array $args, string $url ): array {
		if ( str_contains( $url, 'api.github.com/repos/' . self::GITHUB_REPO )
			|| str_contains( $url, 'github.com/' . self::GITHUB_REPO ) ) {
			$args['timeout'] = max( (int) ( $args['timeout'] ?? 5 ), self::HTTP_TIMEOUT );
		}
		return $args;
	}

	/**
	 * WordPress 6.3+ move_dir() into upgrade-temp-backup often fails on shared hosting.
	 * We use our own copy-based backup and skip core temp_backup moves for this plugin.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public function filter_package_options( array $options ): array {
		if ( empty( $options['hook_extra'] ) || ! is_array( $options['hook_extra'] ) ) {
			return $options;
		}

		if ( ! $this->is_our_plugin( $options['hook_extra'] ) ) {
			return $options;
		}

		if ( ! empty( $options['hook_extra']['temp_backup'] ) ) {
			$options['hook_extra']['pdx_temp_backup_skipped'] = $options['hook_extra']['temp_backup'];
			unset( $options['hook_extra']['temp_backup'] );
		}

		return $options;
	}

	/**
	 * Normalize extracted ZIP folder only — never touch the live plugin directory here.
	 *
	 * @param string|\WP_Error $source
	 * @return string|\WP_Error
	 */
	public function fix_github_source( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $source;
		}

		if ( ! $this->init_filesystem() ) {
			return $source;
		}

		global $wp_filesystem;

		$source = $this->locate_plugin_root_in_source( (string) $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$parent     = trailingslashit( dirname( $source ) );
		$normalized = $parent . self::PLUGIN_FOLDER;
		$source     = untrailingslashit( $source );

		if ( $source === $normalized ) {
			return $source;
		}

		// Only remove another extracted copy in the upgrade working directory — never wp-plugins.
		if ( $wp_filesystem->exists( $normalized ) && ! $this->is_under_plugins_dir( $normalized ) ) {
			$wp_filesystem->delete( $normalized, true );
		}

		if ( $wp_filesystem->move( $source, $normalized, true ) ) {
			return $normalized;
		}

		// Fallback: copy then remove source (move_dir fails on some hosts).
		if ( ! function_exists( 'copy_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( function_exists( 'copy_dir' ) && true === copy_dir( $source, $normalized ) ) {
			$wp_filesystem->delete( $source, true );
			return $normalized;
		}

		return new WP_Error(
			'pdx_source_rename_failed',
			__( 'Could not prepare the update package folder as paxdesign-toolbar.', 'paxdesign-toolbar' )
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	private function locate_plugin_root_in_source( string $source ) {
		global $wp_filesystem;

		$main = trailingslashit( $source ) . 'paxdesign-toolbar.php';
		if ( $wp_filesystem->exists( $main ) ) {
			return untrailingslashit( $source );
		}

		$dirlist = $wp_filesystem->dirlist( $source );
		if ( ! is_array( $dirlist ) ) {
			return new WP_Error(
				'pdx_invalid_package',
				__( 'Update package could not be read.', 'paxdesign-toolbar' )
			);
		}

		foreach ( array_keys( $dirlist ) as $name ) {
			if ( 'paxdesign-toolbar.php' === $name ) {
				return untrailingslashit( $source );
			}

			$candidate = trailingslashit( $source ) . $name;
			if ( empty( $dirlist[ $name ]['type'] ) || 'd' !== $dirlist[ $name ]['type'] ) {
				continue;
			}

			$nested_main = trailingslashit( $candidate ) . 'paxdesign-toolbar.php';
			if ( $wp_filesystem->exists( $nested_main ) ) {
				return untrailingslashit( $candidate );
			}
		}

		return new WP_Error(
			'pdx_invalid_package',
			__( 'Update package must contain a paxdesign-toolbar folder with paxdesign-toolbar.php.', 'paxdesign-toolbar' )
		);
	}

	public function backup_before_install( $return, $hook_extra ) {
		if ( is_wp_error( $return ) || ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $return;
		}

		$this->init_filesystem();
		$this->ensure_upgrade_directories();

		$this->set_state(
			[
				'upgrading' => true,
				'started'   => time(),
				'from'      => PDX_VERSION,
			]
		);

		$this->cleanup_failed_backups();

		$backup_path = $this->create_copy_backup();
		$state       = $this->get_state();

		if ( $backup_path ) {
			$state['backup']        = $backup_path;
			$state['backup_skipped'] = false;
			unset( $state['backup_error'] );
		} else {
			$state['backup_skipped'] = true;
			$state['backup_error']   = __(
				'Automatic backup could not be created (permissions). The update will continue without a rollback copy.',
				'paxdesign-toolbar'
			);
		}

		$this->set_state( $state );

		return $return;
	}

	public function verify_install( $response, $hook_extra, $result ) {
		if ( ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			$this->handle_failed_upgrade( $hook_extra );
			return $response;
		}

		$main_file = $this->plugin_dir() . '/paxdesign-toolbar.php';
		if ( ! is_readable( $main_file ) ) {
			$this->handle_failed_upgrade( $hook_extra );
			return new WP_Error(
				'pdx_invalid_package',
				__( 'Update package is missing paxdesign-toolbar.php.', 'paxdesign-toolbar' )
			);
		}

		$plugin_data = get_file_data(
			$main_file,
			[ 'Version' => 'Version' ],
			'plugin'
		);

		if ( empty( $plugin_data['Version'] ) ) {
			$this->handle_failed_upgrade( $hook_extra );
			return new WP_Error(
				'pdx_invalid_version',
				__( 'Updated package does not contain a valid plugin version.', 'paxdesign-toolbar' )
			);
		}

		if ( is_array( $result ) ) {
			$this->consolidate_install_to_canonical_folder( $result );
		}

		$this->cleanup_duplicate_plugin_folders( true );

		return $response;
	}

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>|\WP_Error
	 */
	public function on_install_package_result( $result, $hook_extra ) {
		if ( ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->handle_failed_upgrade( $hook_extra );
		}

		$this->release_maintenance_mode();

		return $result;
	}

	public function on_upgrade_complete( $upgrader, $hook_extra ): void {
		$this->release_maintenance_mode();

		if ( ! is_array( $hook_extra ) || ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}

		$failed = false;
		if ( is_object( $upgrader ) && isset( $upgrader->result ) && is_wp_error( $upgrader->result ) ) {
			$failed = true;
		}

		if ( $failed ) {
			$this->handle_failed_upgrade( $hook_extra );
			return;
		}

		$this->clear_all_caches();
		$this->cleanup_failed_backups();
		$this->cleanup_duplicate_plugin_folders( true );
		$this->set_state( [] );
	}

	/**
	 * @param string $plugin_file Plugin basename path.
	 * @param bool   $deleted     Whether the plugin was deleted successfully.
	 */
	public function on_deleted_plugin( $plugin_file, $deleted ): void {
		if ( ! is_string( $plugin_file ) || ! str_contains( $plugin_file, 'paxdesign-toolbar' ) ) {
			return;
		}

		$this->cleanup_duplicate_plugin_folders( true );
	}

	public function maybe_cleanup_duplicate_plugins_admin(): void {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && 'plugins' === $screen->id ) {
			$this->cleanup_duplicate_plugin_folders( true );
		}
	}

	public function bootstrap_recovery(): void {
		$this->maybe_cleanup_stale_maintenance();
		$this->cleanup_duplicate_plugin_folders();

		$state = $this->get_state();
		if ( empty( $state['upgrading'] ) ) {
			return;
		}

		if ( ! empty( $state['started'] ) && ( time() - (int) $state['started'] ) > 900 ) {
			$this->handle_failed_upgrade( null );
		}
	}

	public function maybe_cleanup_stale_maintenance(): void {
		$file = $this->maintenance_file();
		if ( ! $file || ! $this->is_maintenance_stale( $file ) ) {
			return;
		}

		$this->release_maintenance_mode();
	}

	public function release_maintenance_mode(): void {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$upgrader = new WP_Upgrader();
			$upgrader->release_maintenance_mode();
		}

		$file = ABSPATH . '.maintenance';
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * @param array<string, mixed>|null $hook_extra
	 */
	private function handle_failed_upgrade( ?array $hook_extra = null ): void {
		$this->release_maintenance_mode();
		$this->maybe_rollback( $hook_extra );
		$this->clear_all_caches();
		$this->cleanup_failed_backups();

		$state = $this->get_state();
		unset( $state['upgrading'] );
		$this->set_state( $state );
	}

	/**
	 * @param array<string, mixed>|null $hook_extra
	 */
	private function maybe_rollback( ?array $hook_extra = null ): void {
		if ( ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}

		$state  = $this->get_state();
		$backup = isset( $state['backup'] ) ? (string) $state['backup'] : '';

		if ( ! $backup || ! is_dir( $backup ) ) {
			return;
		}

		$target = $this->plugin_dir();
		$this->delete_directory( $target );
		$this->copy_directory( $backup, $target );
	}

	/**
	 * @return list<string>
	 */
	private function backup_dir_candidates(): array {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

		return array_values(
			array_unique(
				[
					WP_CONTENT_DIR . '/upgrade/pdx-toolbar-backup',
					WP_CONTENT_DIR . '/uploads/pdx-upgrades/backup',
					$base . '/pdx-upgrades/backup',
					trailingslashit( get_temp_dir() ) . 'pdx-toolbar-backup',
				]
			)
		);
	}

	private function create_copy_backup(): ?string {
		$source = $this->plugin_dir();
		if ( ! is_dir( $source ) ) {
			return null;
		}

		foreach ( $this->backup_dir_candidates() as $dir ) {
			$this->delete_directory( $dir );

			if ( ! wp_mkdir_p( $dir ) ) {
				continue;
			}

			$this->chmod_dir( $dir );

			if ( $this->copy_directory( $source, $dir ) ) {
				return $dir;
			}

			$this->delete_directory( $dir );
		}

		return null;
	}

	private function cleanup_failed_backups(): void {
		foreach ( $this->backup_dir_candidates() as $dir ) {
			if ( is_dir( $dir ) ) {
				$this->delete_directory( $dir );
			}
		}
	}

	/**
	 * Ensure only wp-content/plugins/paxdesign-toolbar exists; remove stale duplicates.
	 *
	 * @param bool $force Run even if already ran this request.
	 */
	public function cleanup_duplicate_plugin_folders( bool $force = false ): void {
		static $ran = false;
		if ( $ran && ! $force ) {
			return;
		}
		$ran = true;

		$plugins_dir = WP_PLUGIN_DIR;
		if ( ! is_dir( $plugins_dir ) ) {
			return;
		}

		$this->consolidate_versioned_install_into_canonical();
		$this->maybe_repair_active_plugins_list();

		$canonical = $this->canonical_plugin_dir_path();

		// Versioned sibling folders: paxdesign-toolbar-7.1.2, etc.
		$patterns = [
			$plugins_dir . '/paxdesign-toolbar-*',
			$plugins_dir . '/' . self::PLUGIN_FOLDER . '/paxdesign-toolbar-*',
		];

		foreach ( $patterns as $pattern ) {
			$matches = glob( $pattern );
			if ( ! is_array( $matches ) ) {
				continue;
			}
			foreach ( $matches as $path ) {
				if ( ! is_dir( $path ) ) {
					continue;
				}
				$this->delete_duplicate_plugin_directory( $path, $canonical );
			}
		}

		// Any top-level folder that registers a second PaxDesign plugin file.
		$entries = @scandir( $plugins_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $entries ) ) {
			$this->flush_plugin_cache();
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$dir = $plugins_dir . '/' . $entry;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			if ( $this->is_canonical_plugin_dir( $dir ) ) {
				continue;
			}

			$main_file = $dir . '/' . self::PLUGIN_MAIN_FILE;
			if ( is_readable( $main_file ) && $this->is_our_plugin_header_file( $main_file ) ) {
				$this->delete_duplicate_plugin_directory( $dir, $canonical );
			}
		}

		$this->cleanup_temp_backup_duplicates();
		$this->flush_plugin_cache();
	}

	/**
	 * If a versioned folder is newer than canonical, merge into canonical first.
	 */
	private function consolidate_versioned_install_into_canonical(): void {
		$plugins_dir = WP_PLUGIN_DIR;
		$canonical   = $this->plugin_dir();
		$canon_main  = $canonical . '/' . self::PLUGIN_MAIN_FILE;

		if ( ! is_readable( $canon_main ) ) {
			$best_dir = null;
			$best_ver = '0.0.0';

			foreach ( glob( $plugins_dir . '/paxdesign-toolbar-*' ) ?: [] as $path ) {
				if ( ! is_dir( $path ) ) {
					continue;
				}
				$main = $path . '/' . self::PLUGIN_MAIN_FILE;
				if ( ! is_readable( $main ) || ! $this->is_our_plugin_header_file( $main ) ) {
					continue;
				}
				$ver = $this->read_plugin_version( $main );
				if ( version_compare( $ver, $best_ver, '>' ) ) {
					$best_ver = $ver;
					$best_dir = $path;
				}
			}

			if ( $best_dir ) {
				wp_mkdir_p( $canonical );
				$this->copy_directory( $best_dir, $canonical );
			}
		}

		foreach ( glob( $plugins_dir . '/paxdesign-toolbar-*' ) ?: [] as $path ) {
			if ( ! is_dir( $path ) || $this->is_canonical_plugin_dir( $path ) ) {
				continue;
			}
			$main = $path . '/' . self::PLUGIN_MAIN_FILE;
			if ( ! is_readable( $main ) || ! $this->is_our_plugin_header_file( $main ) ) {
				$this->delete_directory( $path );
				continue;
			}

			$canon_ver = is_readable( $canon_main ) ? $this->read_plugin_version( $canon_main ) : '0.0.0';
			$alt_ver   = $this->read_plugin_version( $main );

			if ( version_compare( $alt_ver, $canon_ver, '>' ) ) {
				$this->copy_directory( $path, $canonical );
			}

			$this->delete_duplicate_plugin_directory( $path, $this->canonical_plugin_dir_path() );
		}
	}

	/**
	 * @param array<string, mixed> $result Install result from WP_Upgrader.
	 */
	private function consolidate_install_to_canonical_folder( array $result ): void {
		$candidates = [];

		if ( ! empty( $result['destination'] ) ) {
			$candidates[] = (string) $result['destination'];
		}
		if ( ! empty( $result['local_destination'] ) ) {
			$candidates[] = (string) $result['local_destination'];
		}

		$canonical = $this->plugin_dir();
		wp_mkdir_p( $canonical );

		foreach ( array_unique( $candidates ) as $dest ) {
			$dest = untrailingslashit( wp_normalize_path( $dest ) );
			if ( $this->is_canonical_plugin_dir( $dest ) ) {
				continue;
			}

			$main = $dest . '/' . self::PLUGIN_MAIN_FILE;
			if ( ! is_readable( $main ) || ! $this->is_our_plugin_header_file( $main ) ) {
				continue;
			}

			$this->copy_directory( $dest, $canonical );
			if ( ! $this->is_canonical_plugin_dir( $dest ) ) {
				$this->delete_directory( $dest );
			}
		}

		$this->maybe_repair_active_plugins_list();
	}

	private function cleanup_temp_backup_duplicates(): void {
		$base = WP_CONTENT_DIR . '/upgrade-temp-backup/plugins';
		if ( ! is_dir( $base ) ) {
			return;
		}

		foreach ( glob( $base . '/paxdesign-toolbar*' ) ?: [] as $path ) {
			if ( ! is_dir( $path ) ) {
				continue;
			}
			if ( basename( $path ) === self::PLUGIN_FOLDER ) {
				continue;
			}
			$this->delete_directory( $path );
		}
	}

	private function delete_duplicate_plugin_directory( string $path, string $canonical ): void {
		$path = wp_normalize_path( $path );

		if ( $this->is_canonical_plugin_dir( $path ) ) {
			return;
		}

		if ( $path === $canonical ) {
			return;
		}

		$main_file = $path . '/' . self::PLUGIN_MAIN_FILE;
		if ( is_readable( $main_file ) ) {
			$basename = plugin_basename( $main_file );
			if ( $basename === $this->plugin_basename() ) {
				return;
			}
			if ( $this->is_plugin_active_basename( $basename ) && ! $this->is_versioned_plugin_basename( $basename ) ) {
				return;
			}
		}

		$this->delete_directory( $path );
	}

	private function is_versioned_plugin_basename( string $basename ): bool {
		return (bool) preg_match( '#^paxdesign-toolbar-[^/]+/paxdesign-toolbar\.php$#', $basename );
	}

	private function is_plugin_active_basename( string $basename ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $basename );
	}

	private function maybe_repair_active_plugins_list(): void {
		$correct = $this->plugin_basename();
		$active  = (array) get_option( 'active_plugins', [] );
		$changed = false;
		$has_ok  = in_array( $correct, $active, true );

		foreach ( $active as $index => $basename ) {
			if ( $basename === $correct ) {
				continue;
			}
			if ( ! $this->is_versioned_plugin_basename( $basename ) ) {
				continue;
			}
			unset( $active[ $index ] );
			$changed = true;
		}

		if ( $changed ) {
			if ( ! $has_ok && is_readable( PDX_DIR . self::PLUGIN_MAIN_FILE ) ) {
				$active[] = $correct;
			}
			update_option( 'active_plugins', array_values( array_unique( $active ) ) );
			$this->flush_plugin_cache();
		}
	}

	private function is_our_plugin_header_file( string $main_file ): bool {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $main_file, false, false );
		$domain = isset( $data['TextDomain'] ) ? (string) $data['TextDomain'] : '';
		$name   = isset( $data['Name'] ) ? (string) $data['Name'] : '';

		return self::PLUGIN_TEXT_DOMAIN === $domain
			|| str_contains( $name, 'PaxDesign Utility Dock' );
	}

	private function read_plugin_version( string $main_file ): string {
		$data = get_file_data(
			$main_file,
			[ 'Version' => 'Version' ],
			'plugin'
		);

		return isset( $data['Version'] ) ? (string) $data['Version'] : '0.0.0';
	}

	private function canonical_plugin_dir_path(): string {
		return wp_normalize_path( realpath( $this->plugin_dir() ) ?: $this->plugin_dir() );
	}

	private function is_canonical_plugin_dir( string $dir ): bool {
		$canonical = $this->canonical_plugin_dir_path();
		$dir_path  = wp_normalize_path( realpath( $dir ) ?: $dir );

		return $dir_path === $canonical;
	}

	private function is_under_plugins_dir( string $path ): bool {
		$plugins = wp_normalize_path( realpath( WP_PLUGIN_DIR ) ?: WP_PLUGIN_DIR );
		$path    = wp_normalize_path( $path );

		return str_starts_with( $path, $plugins . '/' );
	}

	private function flush_plugin_cache(): void {
		wp_cache_delete( 'plugins', 'plugins' );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
	}

	private function ensure_upgrade_directories(): void {
		$dirs = [
			WP_CONTENT_DIR . '/upgrade',
			WP_CONTENT_DIR . '/upgrade-temp-backup',
			WP_CONTENT_DIR . '/upgrade-temp-backup/plugins',
			WP_CONTENT_DIR . '/uploads/pdx-upgrades',
		];

		foreach ( $dirs as $dir ) {
			wp_mkdir_p( $dir );
			$this->chmod_dir( $dir );
		}
	}

	private function init_filesystem(): bool {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		ob_start();
		$credentials = request_filesystem_credentials( '' );
		ob_end_clean();

		if ( false === $credentials ) {
			$credentials = [];
		}

		return (bool) WP_Filesystem( $credentials );
	}

	private function chmod_dir( string $dir ): void {
		if ( defined( 'FS_CHMOD_DIR' ) ) {
			@chmod( $dir, FS_CHMOD_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private function maintenance_file(): ?string {
		$file = ABSPATH . '.maintenance';
		return file_exists( $file ) ? $file : null;
	}

	private function is_maintenance_stale( ?string $file = null ): bool {
		$file = $file ?? $this->maintenance_file();
		if ( ! $file ) {
			return false;
		}

		$state = $this->get_state();
		if ( ! empty( $state['upgrading'] ) && ! empty( $state['started'] ) ) {
			if ( ( time() - (int) $state['started'] ) < 120 ) {
				return false;
			}
		}

		$mtime = (int) filemtime( $file );
		if ( $mtime > 0 && ( time() - $mtime ) < self::MAINTENANCE_MAX_AGE ) {
			return false;
		}

		$parsed = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_string( $parsed ) && preg_match( '/\$upgrading\s*=\s*(\d+)/', $parsed, $m ) ) {
			$started = (int) $m[1];
			if ( $started > 0 && ( time() - $started ) < self::MAINTENANCE_MAX_AGE ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_state(): array {
		$state = get_option( self::STATE_OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function set_state( array $state ): void {
		if ( empty( $state ) ) {
			delete_option( self::STATE_OPTION );
			return;
		}
		update_option( self::STATE_OPTION, $state, false );
	}

	private function copy_directory( string $from, string $to ): bool {
		if ( ! is_dir( $from ) ) {
			return false;
		}

		if ( ! function_exists( 'copy_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$this->init_filesystem();
		wp_mkdir_p( $to );
		$this->chmod_dir( $to );

		if ( function_exists( 'copy_dir' ) ) {
			$result = copy_dir( $from, $to );
			if ( true === $result || is_array( $result ) ) {
				if ( is_readable( $to . '/paxdesign-toolbar.php' ) ) {
					return true;
				}
			}
		}

		return $this->native_copy_directory( $from, $to );
	}

	private function native_copy_directory( string $from, string $to ): bool {
		$from = wp_normalize_path( $from );
		$to   = wp_normalize_path( $to );

		if ( ! wp_mkdir_p( $to ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $from, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$sub_path = substr( wp_normalize_path( $item->getPathname() ), strlen( $from ) + 1 );
			$target   = $to . '/' . $sub_path;

			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $target ) ) {
					return false;
				}
				$this->chmod_dir( $target );
			} elseif ( ! copy( $item->getPathname(), $target ) ) {
				return false;
			}
		}

		return is_readable( $to . '/paxdesign-toolbar.php' );
	}

	private function delete_directory( string $dir ): void {
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}

		$this->init_filesystem();
		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->delete( $dir, true ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	public function handle_check_updates(): void {
		if ( ! current_user_can( PDX_CAP ) ) {
			wp_die( esc_html__( 'Unauthorized', 'paxdesign-toolbar' ), 403 );
		}
		check_admin_referer( 'pdx_check_updates' );

		$this->clear_all_caches();
		$status = $this->get_status( true );

		$redirect_args = [
			'page'        => PDX_SLUG,
			'pdx_checked' => '1',
		];

		if ( ! empty( $status['error'] ) ) {
			$redirect_args['pdx_update_error'] = rawurlencode( $status['error'] );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_clear_maintenance(): void {
		if ( ! current_user_can( PDX_CAP ) ) {
			wp_die( esc_html__( 'Unauthorized', 'paxdesign-toolbar' ), 403 );
		}
		check_admin_referer( 'pdx_clear_maintenance' );

		$this->release_maintenance_mode();
		$this->set_state( [] );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                  => PDX_SLUG,
					'pdx_maintenance_fixed' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

PDX_Updater::instance();
