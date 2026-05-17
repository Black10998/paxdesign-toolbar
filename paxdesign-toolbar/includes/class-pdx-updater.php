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
		add_filter( 'upgrader_source_selection', [ $this, 'fix_github_source' ], 10, 4 );
		add_filter( 'upgrader_pre_install', [ $this, 'backup_before_install' ], 10, 2 );
		add_filter( 'upgrader_post_install', [ $this, 'verify_install' ], 10, 3 );

		add_action( 'plugins_loaded', [ $this, 'bootstrap_recovery' ], 1 );
		add_action( 'admin_init', [ $this, 'maybe_cleanup_stale_maintenance' ] );
		add_action( 'shutdown', [ $this, 'maybe_cleanup_stale_maintenance' ], 999 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrade_complete' ], 10, 2 );

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

		$url  = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$res  = wp_remote_get(
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

	public function fix_github_source( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$target = $this->plugin_dir();
		if ( $source === $target ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}

		$moved = $wp_filesystem->move( $source, $target, true );
		if ( $moved ) {
			return $target;
		}

		return $source;
	}

	public function backup_before_install( $return, $hook_extra ) {
		if ( ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $return;
		}

		$this->set_state(
			[
				'upgrading' => true,
				'started'   => time(),
				'from'      => PDX_VERSION,
			]
		);

		$backup_dir = WP_CONTENT_DIR . '/upgrade/pdx-toolbar-backup';
		$this->delete_directory( $backup_dir );

		if ( ! $this->copy_directory( $this->plugin_dir(), $backup_dir ) ) {
			return new WP_Error(
				'pdx_backup_failed',
				__( 'Could not create a backup before updating PaxDesign Toolbar.', 'paxdesign-toolbar' )
			);
		}

		$state = $this->get_state();
		$state['backup'] = $backup_dir;
		$this->set_state( $state );

		return $return;
	}

	public function verify_install( $response, $hook_extra, $result ) {
		if ( is_wp_error( $response ) ) {
			$this->maybe_rollback( $hook_extra );
			return $response;
		}

		if ( ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $response;
		}

		$main_file = $this->plugin_dir() . '/paxdesign-toolbar.php';
		if ( ! is_readable( $main_file ) ) {
			$this->maybe_rollback( $hook_extra );
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
			$this->maybe_rollback( $hook_extra );
			return new WP_Error(
				'pdx_invalid_version',
				__( 'Updated package does not contain a valid plugin version.', 'paxdesign-toolbar' )
			);
		}

		return $response;
	}

	public function on_upgrade_complete( $upgrader, $hook_extra ): void {
		$this->release_maintenance_mode();

		if ( ! is_array( $hook_extra ) ) {
			return;
		}

		if ( ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}

		$failed = false;
		if ( is_object( $upgrader ) && isset( $upgrader->result ) && is_wp_error( $upgrader->result ) ) {
			$failed = true;
		}

		if ( $failed ) {
			$this->maybe_rollback( $hook_extra );
			$this->clear_all_caches();
			return;
		}

		$this->clear_all_caches();
		$this->delete_directory( WP_CONTENT_DIR . '/upgrade/pdx-toolbar-backup' );
		$this->set_state( [] );
	}

	public function bootstrap_recovery(): void {
		$this->maybe_cleanup_stale_maintenance();
	}

	public function maybe_cleanup_stale_maintenance(): void {
		$file = $this->maintenance_file();
		if ( ! $file ) {
			return;
		}

		if ( ! $this->is_maintenance_stale( $file ) ) {
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
	private function maybe_rollback( ?array $hook_extra = null ): void {
		if ( ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}

		$state = $this->get_state();
		$backup = $state['backup'] ?? '';
		if ( ! $backup || ! is_dir( $backup ) ) {
			return;
		}

		$target = $this->plugin_dir();
		$this->delete_directory( $target );
		$this->copy_directory( (string) $backup, $target );
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
		if ( ! function_exists( 'copy_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			WP_Filesystem();
		}
		wp_mkdir_p( $to );
		return (bool) copy_dir( $from, $to );
	}

	private function delete_directory( string $dir ): void {
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}

	public function handle_check_updates(): void {
		if ( ! current_user_can( PDX_CAP ) ) {
			wp_die( esc_html__( 'Unauthorized', 'paxdesign-toolbar' ), 403 );
		}
		check_admin_referer( 'pdx_check_updates' );

		$this->clear_all_caches();
		$status = $this->get_status( true );

		$redirect_args = [
			'page'          => PDX_SLUG,
			'pdx_checked'   => '1',
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
					'page'                 => PDX_SLUG,
					'pdx_maintenance_fixed' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

PDX_Updater::instance();
