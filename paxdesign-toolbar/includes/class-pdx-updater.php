<?php
/**
 * GitHub release updater — production-grade update flow for wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Updater {

	private const GITHUB_REPO         = 'Black10998/paxdesign-toolbar';
	private const UPDATE_URI          = 'https://github.com/Black10998/paxdesign-toolbar';
	private const PLUGIN_FOLDER       = 'paxdesign-toolbar';
	private const PLUGIN_MAIN_FILE    = 'paxdesign-toolbar.php';
	private const PLUGIN_TEXT_DOMAIN  = 'paxdesign-toolbar';
	private const CACHE_KEY           = 'pdx_github_release';
	private const LAST_CHECK_OPTION   = 'pdx_updater_last_checked';
	private const STATE_OPTION        = 'pdx_updater_state';
	private const CACHE_TTL           = 43200;
	private const HTTP_TIMEOUT        = 45;
	private const MAINTENANCE_MAX_AGE       = 120;
	private const MAINTENANCE_GRACE_ACTIVE = 90;

	private static ?self $instance = null;

	/** @var bool */
	private bool $upgrade_shutdown_registered = false;

	/** @var bool */
	private bool $upgrade_finalized = false;

	/** @var bool */
	private bool $pending_update_transient_persist = false;

	/** @var object|null */
	private ?object $pending_update_transient = null;

	/** @var bool */
	private bool $shutdown_persist_registered = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// WordPress 5.8+ Update URI header — primary injection path during wp_update_plugins().
		add_filter( 'update_plugins_github.com', [ $this, 'filter_update_plugins_uri' ], 10, 4 );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ], 10 );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'finalize_update_transient' ], 9999 );
		add_filter( 'site_transient_update_plugins', [ $this, 'sanitize_stored_update_transient' ], 1 );
		add_filter( 'site_transient_update_plugins', [ $this, 'finalize_update_transient' ], 9999 );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 20, 3 );
		add_filter( 'http_request_args', [ $this, 'http_request_args' ], 10, 2 );
		add_filter( 'upgrader_package_options', [ $this, 'filter_package_options' ], 10, 1 );
		add_filter( 'upgrader_install_package', [ $this, 'force_canonical_install_destination' ], 5, 2 );
		add_filter( 'upgrader_pre_install', [ $this, 'backup_before_install' ], 5, 2 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_github_source' ], 10, 4 );
		add_filter( 'all_plugins', [ $this, 'filter_plugins_list_single_instance' ], 100 );
		add_filter( 'upgrader_post_install', [ $this, 'verify_install' ], 10, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'recover_post_install_success' ], 999, 3 );
		add_filter( 'upgrader_install_package_result', [ $this, 'on_install_package_result' ], 10, 2 );

		add_action( 'plugins_loaded', [ $this, 'repair_broken_install_layout' ], -1 );
		add_action( 'plugins_loaded', [ $this, 'enforce_canonical_install' ], 0 );
		add_action( 'plugins_loaded', [ $this, 'repair_stored_update_transient' ], 1 );
		add_action( 'plugins_loaded', [ $this, 'bootstrap_recovery' ], 2 );
		add_action( 'admin_init', [ $this, 'repair_stored_update_transient' ], 0 );
		add_action( 'admin_init', [ $this, 'maybe_migrate_to_canonical_dir' ], 1 );
		add_action( 'admin_init', [ $this, 'maybe_cleanup_stale_maintenance' ] );
		add_action( 'admin_init', [ $this, 'maybe_cleanup_duplicate_plugins_admin' ] );
		add_action( 'shutdown', [ $this, 'maybe_cleanup_stale_maintenance' ], 999 );
		add_action( 'shutdown', [ $this, 'run_deferred_upgrade_cleanup' ], 9998 );
		add_action( 'upgrader_process_complete', [ $this, 'reconcile_upgrader_result' ], 5, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrade_complete' ], 999, 2 );
		// Flush release transient immediately after any upgrade that touches our plugin,
		// so the next admin page load reflects the newly installed version.
		add_action( 'upgrader_process_complete', [ $this, 'flush_release_transient_on_upgrade' ], 1000, 2 );
		add_action( 'deleted_plugin', [ $this, 'on_deleted_plugin' ], 10, 2 );
		add_action( 'activated_plugin', [ $this, 'on_activated_plugin' ], 10, 2 );

		add_action( 'admin_post_pdx_check_updates', [ $this, 'handle_check_updates' ] );
		add_action( 'admin_post_pdx_clear_maintenance', [ $this, 'handle_clear_maintenance' ] );
	}

	/**
	 * WordPress plugin basename — always the canonical folder when it exists.
	 */
	public function plugin_basename(): string {
		return $this->canonical_plugin_basename();
	}

	/**
	 * Basename for the copy PHP loaded from this request (may differ until canonical enforcement runs).
	 */
	public function loaded_plugin_basename(): string {
		return plugin_basename( $this->plugin_dir() . '/' . self::PLUGIN_MAIN_FILE );
	}

	/**
	 * Basename for the canonical install path (wp-content/plugins/paxdesign-toolbar/).
	 *
	 * Always the fixed path — never a versioned or double-nested folder (prevents null update rows).
	 */
	public function canonical_plugin_basename(): string {
		return self::PLUGIN_FOLDER . '/' . self::PLUGIN_MAIN_FILE;
	}

	/**
	 * Every installed copy of PaxDesign on disk (canonical + versioned duplicates).
	 *
	 * @return list<string>
	 */
	public function all_plugin_basenames(): array {
		$basenames = [ $this->plugin_basename() ];

		$canonical = $this->canonical_plugin_basename();
		if ( ! in_array( $canonical, $basenames, true ) ) {
			$basenames[] = $canonical;
		}

		$patterns = [
			WP_PLUGIN_DIR . '/paxdesign-toolbar-*',
			WP_PLUGIN_DIR . '/paxdesign-toolbar-*/' . self::PLUGIN_FOLDER,
			WP_PLUGIN_DIR . '/' . self::PLUGIN_FOLDER . '/paxdesign-toolbar-*',
		];

		foreach ( $patterns as $pattern ) {
			foreach ( glob( $pattern, GLOB_ONLYDIR ) ?: [] as $dir ) {
				$candidates = [
					$dir . '/' . self::PLUGIN_MAIN_FILE,
					$dir . '/' . self::PLUGIN_FOLDER . '/' . self::PLUGIN_MAIN_FILE,
				];
				foreach ( $candidates as $main ) {
					if ( ! is_readable( $main ) || ! $this->is_our_plugin_header_file( $main ) ) {
						continue;
					}
					$basename = plugin_basename( $main );
					if ( ! in_array( $basename, $basenames, true ) ) {
						$basenames[] = $basename;
					}
				}
			}
		}

		return $basenames;
	}

	public function is_plugin_basename_ours( string $basename ): bool {
		if ( $basename === $this->canonical_plugin_basename() ) {
			return true;
		}

		return str_contains( $basename, self::PLUGIN_FOLDER . '/' . self::PLUGIN_MAIN_FILE );
	}

	/**
	 * Installed version from the live plugin file header (never a stale in-memory constant).
	 */
	public function get_installed_version(): string {
		$canonical_main = $this->canonical_plugin_dir() . '/' . self::PLUGIN_MAIN_FILE;
		if ( is_readable( $canonical_main ) ) {
			$ver = $this->read_plugin_version( $canonical_main );
			if ( '' !== $ver && '0.0.0' !== $ver ) {
				return $ver;
			}
		}

		$loaded_main = $this->plugin_dir() . '/' . self::PLUGIN_MAIN_FILE;
		$ver         = $this->read_plugin_version( $loaded_main );
		if ( '' !== $ver && '0.0.0' !== $ver ) {
			return $ver;
		}

		return defined( 'PDX_VERSION' ) ? (string) PDX_VERSION : '0.0.0';
	}

	/**
	 * Live plugin directory (where this request's paxdesign-toolbar.php is loaded from).
	 * Must match WordPress' update destination — not a hardcoded folder name.
	 */
	public function plugin_dir(): string {
		if ( defined( 'PDX_DIR' ) && is_string( PDX_DIR ) && '' !== PDX_DIR ) {
			return wp_normalize_path( untrailingslashit( PDX_DIR ) );
		}

		return wp_normalize_path( WP_PLUGIN_DIR . '/' . self::PLUGIN_FOLDER );
	}

	/**
	 * Preferred canonical folder name under wp-content/plugins/.
	 */
	public function canonical_plugin_dir(): string {
		return wp_normalize_path( WP_PLUGIN_DIR . '/' . self::PLUGIN_FOLDER );
	}

	public function uses_canonical_plugin_dir(): bool {
		return $this->plugin_dir() === wp_normalize_path( realpath( $this->canonical_plugin_dir() ) ?: $this->canonical_plugin_dir() );
	}

	/**
	 * @param array<string, mixed>|null $hook_extra
	 */
	private function is_our_plugin( ?array $hook_extra = null, ?string $plugin_basename = null ): bool {
		$ours = $this->all_plugin_basenames();

		if ( null !== $plugin_basename ) {
			return in_array( $plugin_basename, $ours, true );
		}

		if ( ! is_array( $hook_extra ) || empty( $hook_extra ) ) {
			return false;
		}

		if ( ! empty( $hook_extra['plugin'] ) && in_array( (string) $hook_extra['plugin'], $ours, true ) ) {
			return true;
		}

		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $plugin ) {
				if ( in_array( (string) $plugin, $ours, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public function clear_release_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	public function clear_all_caches(): void {
		$this->clear_release_cache();
		$this->invalidate_plugin_update_transient();
	}

	/**
	 * Drop only PaxDesign rows from the plugin update transient, then rebuild via wp_update_plugins().
	 * Avoids wiping update metadata for unrelated plugins.
	 */
	public function invalidate_plugin_update_transient(): void {
		$transient = get_site_transient( 'update_plugins' );
		if ( is_object( $transient ) ) {
			$this->clear_pdx_update_transient_entries( $transient );
			set_site_transient( 'update_plugins', $transient );
		}
		$this->flush_plugin_cache();
	}

	/**
	 * After upgrades/activation: refresh GitHub metadata and rebuild core update transients.
	 */
	public function refresh_plugin_update_metadata(): void {
		$this->clear_release_cache();
		$this->invalidate_plugin_update_transient();

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
	}

	/**
	 * @return list<string>
	 */
	private function update_transient_basenames(): array {
		return [ $this->canonical_plugin_basename() ];
	}

	/**
	 * Plugin update rows keyed by any paxdesign-toolbar* basename (including orphans).
	 */
	private function is_pdx_plugin_transient_key( string $key ): bool {
		return str_contains( $key, self::PLUGIN_FOLDER );
	}

	/**
	 * Non-canonical PaxDesign plugin basenames (versioned, double-nested, or orphan paths).
	 */
	private function is_noncanonical_pdx_basename( string $basename ): bool {
		return $this->is_pdx_plugin_transient_key( $basename )
			&& $basename !== $this->canonical_plugin_basename();
	}

	/**
	 * @param array<string, array<string, mixed>> $row
	 * @return array<string, mixed>
	 */
	private function sanitize_plugin_header_row( array $row ): array {
		foreach ( [
			'Name',
			'PluginURI',
			'Description',
			'Version',
			'Author',
			'AuthorURI',
			'TextDomain',
			'DomainPath',
			'Title',
			'AuthorName',
		] as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$row[ $field ] = $this->string_field( $row[ $field ] ?? null, '' );
			}
		}

		return $row;
	}

	/**
	 * Schedule persisting a repaired update_plugins transient (safe during read filters).
	 */
	private function schedule_update_transient_persist( object $transient ): void {
		$this->pending_update_transient_persist = true;
		$this->pending_update_transient         = $transient;

		if ( ! $this->shutdown_persist_registered ) {
			add_action( 'shutdown', [ $this, 'persist_pending_update_transient' ], 1 );
			$this->shutdown_persist_registered = true;
		}
	}

	public function persist_pending_update_transient(): void {
		if ( ! $this->pending_update_transient_persist || ! is_object( $this->pending_update_transient ) ) {
			return;
		}

		$this->pending_update_transient_persist = false;
		set_site_transient( 'update_plugins', $this->pending_update_transient );
		$this->pending_update_transient = null;
	}

	/**
	 * Recursively replace null scalars (arrays/objects) so wp_json_encode/json_encode and WP core never see null.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	private function deep_null_scalars_to_empty( $data ) {
		if ( null === $data ) {
			return '';
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->deep_null_scalars_to_empty( $value );
			}
			return $data;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = $this->deep_null_scalars_to_empty( $value );
			}
			return $data;
		}

		return $data;
	}

	/**
	 * @param mixed $data
	 */
	private function json_encode_safe( $data ): string {
		$data = $this->deep_null_scalars_to_empty( $data );

		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $data );
			if ( is_string( $json ) && '' !== $json && 'null' !== $json ) {
				return $json;
			}
		}

		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && false !== $json ? $json : '{}';
	}

	/**
	 * Coerce mixed values to string for WordPress core (strpos/str_replace reject null on PHP 8.1+).
	 */
	private function encode_for_compare( mixed $data ): string {
		return $this->json_encode_safe( $data );
	}

	private function string_field( mixed $value, string $default = '' ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return $default;
	}

	/**
	 * @param array<mixed>|object|null $data
	 * @return array<string>
	 */
	private function sanitize_string_list( $data ): array {
		if ( ! is_array( $data ) && ! ( is_object( $data ) && $data instanceof \stdClass ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) $data as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			} elseif ( is_scalar( $item ) && '' !== (string) $item ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}

	/**
	 * Plugin icons/banners are associative URL maps — never pass null values to core.
	 *
	 * @param mixed $data
	 * @return array<string, string>
	 */
	private function sanitize_url_map( $data ): array {
		if ( ! is_array( $data ) && ! ( is_object( $data ) && $data instanceof \stdClass ) ) {
			return [];
		}

		$out = [];
		foreach ( (array) $data as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			$url = $this->string_field( $value, '' );
			if ( '' !== $url ) {
				$out[ $key ] = $url;
			}
		}

		return $out;
	}

	/**
	 * Any null scalar on an update row breaks esc_url(), add_query_arg(), and path helpers on PHP 8.1+.
	 */
	private function coerce_null_scalars_on_object( object $obj ): object {
		foreach ( get_object_vars( $obj ) as $key => $value ) {
			if ( null === $value ) {
				$obj->$key = '';
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$obj->$key = (string) $value;
			}
		}

		return $obj;
	}

	/**
	 * Remove orphan PDX rows and normalize string fields so core never receives null.
	 *
	 * @return bool Whether the transient was modified.
	 */
	private function scrub_pdx_entries_in_update_transient( object $transient ): bool {
		$modified  = false;
		$canonical = $this->canonical_plugin_basename();

		foreach ( [ 'response', 'no_update' ] as $bucket ) {
			if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				$transient->$bucket = [];
				$modified           = true;
				continue;
			}

			foreach ( array_keys( $transient->$bucket ) as $plugin_key ) {
				$key = (string) $plugin_key;
				if ( ! $this->is_pdx_plugin_transient_key( $key ) ) {
					continue;
				}

				if ( $key !== $canonical ) {
					unset( $transient->$bucket[ $plugin_key ] );
					$modified = true;
					continue;
				}

				$before    = $transient->$bucket[ $plugin_key ];
				$sanitized = $this->sanitize_update_object( $before );

				if ( ! $this->update_object_is_valid( $sanitized ) ) {
					if ( 'response' === $bucket || 'no_update' === $bucket ) {
						unset( $transient->$bucket[ $plugin_key ] );
						$modified = true;
					}
					continue;
				}

				$before_json    = $this->encode_for_compare( $before );
				$sanitized_json = $this->encode_for_compare( $sanitized );
				if ( $before_json !== $sanitized_json ) {
					$transient->$bucket[ $plugin_key ] = $sanitized;
					$modified                          = true;
				}
			}
		}

		$modified = $this->enforce_pdx_update_bucket_placement( $transient ) || $modified;

		return $modified;
	}

	/**
	 * Ensure PaxDesign never appears in response when already on the latest release.
	 *
	 * @return bool Whether the transient was modified.
	 */
	private function enforce_pdx_update_bucket_placement( object $transient ): bool {
		$modified  = false;
		$installed = $this->get_installed_version();
		$canonical = $this->canonical_plugin_basename();

		if ( '' === $installed ) {
			return false;
		}

		$row = null;
		if ( isset( $transient->response[ $canonical ] ) ) {
			$row = $this->sanitize_update_object( $transient->response[ $canonical ] );
		} elseif ( isset( $transient->no_update[ $canonical ] ) ) {
			$row = $this->sanitize_update_object( $transient->no_update[ $canonical ] );
		}

		if ( ! is_object( $row ) ) {
			return false;
		}

		$new_ver = $this->string_field( $row->new_version ?? null, '' );
		if ( '' === $new_ver ) {
			return false;
		}

		$up_to_date = version_compare( $installed, $new_ver, '>=' );

		if ( $up_to_date ) {
			if ( isset( $transient->response[ $canonical ] ) ) {
				unset( $transient->response[ $canonical ] );
				$modified = true;
			}
			if ( ! isset( $transient->no_update[ $canonical ] ) ) {
				$transient->no_update[ $canonical ] = $row;
				$modified = true;
			}
		} elseif ( ! isset( $transient->response[ $canonical ] ) ) {
			unset( $transient->no_update[ $canonical ] );
			$transient->response[ $canonical ] = $row;
			$modified = true;
		}

		return $modified;
	}

	private function prune_stale_update_transient_keys( object $transient ): void {
		$this->scrub_pdx_entries_in_update_transient( $transient );
	}

	/**
	 * Repair corrupt update_plugins rows left from versioned folders or legacy releases.
	 */
	public function repair_stored_update_transient(): void {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			return;
		}

		$before = $this->encode_for_compare( $transient );
		$this->prepare_update_transient_for_storage( $transient );
		if ( $this->encode_for_compare( $transient ) !== $before ) {
			set_site_transient( 'update_plugins', $transient );
		}
	}

	/**
	 * @return array{installed:string,latest:string,update_available:bool,last_checked:int,error:string,package:string,release_url:string,maintenance_active:bool,maintenance_stale:bool,backup_available:bool,checked_at_formatted:string}
	 */
	public function get_status( bool $force_refresh = false ): array {
		// fetch_release() always returns a fully normalized array — no nulls.
		$release = $this->fetch_release( $force_refresh );

		$latest = '' !== $release['version'] ? $release['version'] : '';
		$error  = '' !== $release['error']   ? $release['error']   : '';

		$last_checked = (int) get_option( self::LAST_CHECK_OPTION, 0 );
		$maintenance  = $this->maintenance_file();
		$state        = $this->get_state();
		$backup       = ! empty( $state['backup'] ) && is_dir( (string) $state['backup'] );

		$installed   = $this->get_installed_version();
		$install_dir = $this->canonical_plugin_dir();

		return [
			'installed'            => $installed,
			'install_dir'          => $install_dir,
			'canonical_dir'        => $this->canonical_plugin_dir(),
			'uses_canonical_dir'   => $this->uses_canonical_plugin_dir(),
			'latest'               => $latest,
			'update_available'     => '' !== $latest && version_compare( $installed, $latest, '<' ),
			'last_checked'         => $last_checked,
			'checked_at_formatted' => $last_checked > 0 ? (string) wp_date( 'M j, Y g:i a', $last_checked ) : '',
			'error'                => $error,
			'package'              => $release['package'],
			'release_url'          => $release['url'],
			'maintenance_active'   => (bool) $maintenance,
			'maintenance_stale'    => $this->is_maintenance_stale( $maintenance ),
			'backup_available'     => $backup,
		];
	}

	/**
	 * Fetch latest release metadata from GitHub, with transient caching.
	 * Always returns a normalized array (all fields are non-null strings).
	 *
	 * @return array<string, mixed>
	 */
	public function fetch_release( bool $force = false ): array {
		if ( $force ) {
			delete_transient( self::CACHE_KEY );
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! $force ) {
			// Re-normalize on every read: handles stale DB entries from older plugin versions
			// that may have stored null fields, which cause PHP 8.1 deprecation warnings.
			$normalized = $this->normalize_release_data( $cached );
			if ( $normalized !== $cached ) {
				set_transient( self::CACHE_KEY, $normalized, self::CACHE_TTL );
			}
			return $normalized;
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
			return $this->normalize_release_data(
				[
					'error'   => $res->get_error_message(),
					'version' => '',
					'package' => '',
					'url'     => 'https://github.com/' . self::GITHUB_REPO,
					'name'    => 'PaxDesign Utility Dock',
					'notes'   => '',
				]
			);
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== (int) $code ) {
			return $this->normalize_release_data(
				[
					'error'   => sprintf( 'GitHub API returned HTTP %d.', (int) $code ),
					'version' => '',
					'package' => '',
					'url'     => 'https://github.com/' . self::GITHUB_REPO,
					'name'    => 'PaxDesign Utility Dock',
					'notes'   => '',
				]
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return $this->normalize_release_data(
				[
					'error'   => 'Invalid release metadata from GitHub.',
					'version' => '',
					'package' => '',
					'url'     => 'https://github.com/' . self::GITHUB_REPO,
					'name'    => 'PaxDesign Utility Dock',
					'notes'   => '',
				]
			);
		}

		$version = ltrim( (string) $body['tag_name'], 'vV' );
		$package = '';

		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			$exact_name = 'paxdesign-toolbar-' . $version . '.zip';
			foreach ( $body['assets'] as $asset ) {
				$name = $asset['name'] ?? '';
				if ( strcasecmp( (string) $name, $exact_name ) === 0 ) {
					$package = $asset['browser_download_url'] ?? '';
					break;
				}
			}
			if ( ! $package ) {
				foreach ( $body['assets'] as $asset ) {
					$name = $asset['name'] ?? '';
					if ( preg_match( '/^paxdesign-toolbar-[0-9].+\.zip$/i', (string) $name ) ) {
						$package = $asset['browser_download_url'] ?? '';
						break;
					}
				}
			}
		}

		if ( ! $package ) {
			return $this->normalize_release_data(
				[
					'error'   => 'No installable release ZIP asset found on GitHub. Upload paxdesign-toolbar-x.y.z.zip to the release.',
					'version' => $version,
					'package' => '',
					'url'     => $body['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO,
					'name'    => 'PaxDesign Utility Dock',
					'notes'   => $body['body'] ?? '',
				]
			);
		}

		$data = $this->normalize_release_data(
			[
				'version' => $version,
				'package' => $package,
				'url'     => $body['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO,
				'name'    => 'PaxDesign Utility Dock',
				'notes'   => $body['body'] ?? '',
				'error'   => '',
			]
		);

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Normalize release data — all fields are guaranteed non-null strings on return.
	 * WordPress core passes these values to strpos(), str_replace(), esc_url(), etc.
	 * on PHP 8.1+ null triggers a deprecation; empty string is always safe.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function normalize_release_data( array $data ): array {
		$fallback_url = 'https://github.com/' . self::GITHUB_REPO;

		$normalized = [
			'version' => $this->string_field( $data['version'] ?? null, '' ),
			'package' => $this->string_field( $data['package'] ?? null, '' ),
			'url'     => $this->string_field( $data['url'] ?? null, $fallback_url ),
			'name'    => $this->string_field( $data['name'] ?? null, 'PaxDesign Utility Dock' ),
			'notes'   => $this->string_field( $data['notes'] ?? null, '' ),
			'error'   => $this->string_field( $data['error'] ?? null, '' ),
		];

		if ( '' === $normalized['url'] ) {
			$normalized['url'] = $fallback_url;
		}
		if ( '' === $normalized['name'] ) {
			$normalized['name'] = 'PaxDesign Utility Dock';
		}

		return $normalized;
	}

	/**
	 * WordPress core calls esc_url() / path helpers on update metadata — null values trigger PHP 8.1+ deprecations.
	 *
	 * @param array<string, mixed> $release
	 */
	private function build_update_offer( array $release, string $plugin ): object {
		$release = $this->normalize_release_data( $release );

		return $this->sanitize_update_object(
			(object) [
				'id'            => self::UPDATE_URI,
				'slug'          => PDX_SLUG,
				'plugin'        => $plugin,
				'new_version'   => $release['version'],
				'url'           => $release['url'],
				'package'       => $release['package'],
				'tested'        => $this->wp_version_for_update_meta(),
				'requires'      => '6.0',
				'requires_php'  => '8.0',
				'compatibility' => (object) [],
				'icons'         => [],
				'banners'       => [],
				'banners_rtl'   => [],
			]
		);
	}

	/**
	 * WordPress 5.8+ Update URI hook — wp_update_plugins() calls this before saving the transient.
	 *
	 * @param array|false              $update       Existing update payload or false.
	 * @param array<string, mixed>     $plugin_data  Plugin header data.
	 * @param string                   $plugin_file  Plugin basename.
	 * @param string[]                 $locales      Locales (unused).
	 * @return array<string, mixed>|false
	 */
	public function filter_update_plugins_uri( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		if ( ! is_string( $plugin_file ) || ! $this->is_plugin_basename_ours( $plugin_file ) ) {
			return $update;
		}

		if ( ! is_array( $plugin_data ) ) {
			return $update;
		}

		$update_uri = $this->string_field( $plugin_data['UpdateURI'] ?? null, '' );
		if ( '' === $update_uri ) {
			return $update;
		}

		$expected_host = wp_parse_url( self::UPDATE_URI, PHP_URL_HOST );
		$plugin_host   = wp_parse_url( $update_uri, PHP_URL_HOST );
		if ( is_string( $expected_host ) && '' !== $expected_host && $plugin_host !== $expected_host ) {
			return $update;
		}

		$payload = $this->build_update_uri_payload();
		if ( false === $payload ) {
			return false;
		}

		$installed = $this->get_installed_version();
		$new_ver   = $this->string_field( $payload['new_version'] ?? null, '' );
		if ( '' !== $new_ver && '' !== $installed && version_compare( $installed, $new_ver, '>=' ) ) {
			return false;
		}

		return $payload;
	}

	/**
	 * @return array<string, mixed>|false
	 */
	private function build_update_uri_payload() {
		$release = $this->fetch_release( false );
		if ( '' !== $release['error'] || '' === $release['version'] || '' === $release['package'] ) {
			return false;
		}

		$payload = $this->normalize_update_uri_payload(
			[
				'id'           => self::UPDATE_URI,
				'slug'         => PDX_SLUG,
				'plugin'       => $this->canonical_plugin_basename(),
				'new_version'  => $release['version'],
				'url'          => $release['url'],
				'package'      => $release['package'],
				'tested'       => $this->wp_version_for_update_meta(),
				'requires'     => '6.0',
				'requires_php' => '8.0',
				'icons'        => [],
				'banners'      => [],
				'banners_rtl'  => [],
			]
		);

		if ( ! $this->update_uri_payload_is_valid( $payload ) ) {
			return false;
		}

		return $payload;
	}

	/**
	 * Update URI hook payloads must match update_plugins transient field names (new_version, not version).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function normalize_update_uri_payload( array $payload ): array {
		$new_version = $this->string_field( $payload['new_version'] ?? $payload['version'] ?? null, '' );

		return [
			'id'            => $this->string_field( $payload['id'] ?? null, self::UPDATE_URI ),
			'slug'          => $this->string_field( $payload['slug'] ?? null, PDX_SLUG ),
			'plugin'        => $this->string_field( $payload['plugin'] ?? null, $this->canonical_plugin_basename() ),
			'new_version'   => $new_version,
			'url'           => $this->string_field( $payload['url'] ?? null, self::UPDATE_URI ),
			'package'       => $this->string_field( $payload['package'] ?? null, '' ),
			'tested'        => $this->string_field( $payload['tested'] ?? null, $this->wp_version_for_update_meta() ),
			'requires'      => $this->string_field( $payload['requires'] ?? null, '6.0' ),
			'requires_php'  => $this->string_field( $payload['requires_php'] ?? null, '8.0' ),
			'icons'         => $this->sanitize_url_map( $payload['icons'] ?? null ),
			'banners'       => $this->sanitize_url_map( $payload['banners'] ?? null ),
			'banners_rtl'   => $this->sanitize_url_map( $payload['banners_rtl'] ?? null ),
			'compatibility' => (object) [],
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function update_uri_payload_is_valid( array $payload ): bool {
		return '' !== ( $payload['plugin'] ?? '' )
			&& '' !== ( $payload['new_version'] ?? '' )
			&& '' !== ( $payload['package'] ?? '' )
			&& '' !== ( $payload['url'] ?? '' )
			&& '' !== ( $payload['slug'] ?? '' );
	}

	/**
	 * Ensure update_plugins buckets exist and every PaxDesign row is valid before storage.
	 */
	private function prepare_update_transient_for_storage( object $transient ): object {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		$this->scrub_pdx_entries_in_update_transient( $transient );

		return $transient;
	}

	/**
	 * Last-line scrub before read/write of update_plugins (after all other filters).
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function finalize_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		return $this->prepare_update_transient_for_storage( $transient );
	}

	/**
	 * Drop PaxDesign rows from both update buckets (e.g. GitHub fetch failed).
	 */
	private function clear_pdx_update_transient_entries( object $transient ): void {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		foreach ( $this->update_transient_basenames() as $plugin ) {
			unset( $transient->response[ $plugin ], $transient->no_update[ $plugin ] );
		}

		$this->scrub_pdx_entries_in_update_transient( $transient );
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public function sanitize_stored_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$before = $this->encode_for_compare( $transient );
		$this->prepare_update_transient_for_storage( $transient );
		if ( $this->encode_for_compare( $transient ) !== $before ) {
			$this->schedule_update_transient_persist( $transient );
		}

		return $transient;
	}

	/**
	 * @param mixed $obj
	 */
	private function sanitize_update_object( $obj ): object {
		if ( is_array( $obj ) ) {
			$obj = (object) $obj;
		}
		if ( ! is_object( $obj ) ) {
			$obj = (object) [];
		}

		// Malformed Update URI payloads use "version" — promote before field coercion.
		$legacy_version = $this->string_field( $obj->version ?? null, '' );
		if ( '' !== $legacy_version && '' === $this->string_field( $obj->new_version ?? null, '' ) ) {
			$obj->new_version = $legacy_version;
		}

		$fallback = self::UPDATE_URI;
		$basename = $this->canonical_plugin_basename();

		$string_fields = [
			'id'             => self::UPDATE_URI,
			'slug'           => PDX_SLUG,
			'plugin'         => $basename,
			'new_version'    => '',
			'url'            => $fallback,
			'package'        => '',
			'tested'         => $this->wp_version_for_update_meta(),
			'requires'       => '6.0',
			'requires_php'   => '8.0',
			'upgrade_notice' => '',
			'version'        => '',
		];

		foreach ( $string_fields as $field => $default ) {
			$raw = $obj->$field ?? null;
			$val = $this->string_field( $raw, $default );
			if ( 'url' === $field && '' === $val ) {
				$val = $fallback;
			}
			if ( ( 'slug' === $field ) && '' === $val ) {
				$val = PDX_SLUG;
			}
			if ( 'plugin' === $field && '' === $val ) {
				$val = $basename;
			}
			if ( 'id' === $field && '' === $val ) {
				$val = self::UPDATE_URI;
			}
			$obj->$field = $val;
		}

		if ( ! isset( $obj->compatibility ) || ! is_object( $obj->compatibility ) ) {
			$obj->compatibility = (object) [];
		}

		$obj->icons       = $this->sanitize_url_map( $obj->icons ?? null );
		$obj->banners     = $this->sanitize_url_map( $obj->banners ?? null );
		$obj->banners_rtl = $this->sanitize_url_map( $obj->banners_rtl ?? null );

		return $this->coerce_null_scalars_on_object( $obj );
	}

	private function update_object_is_valid( object $obj ): bool {
		$obj = $this->sanitize_update_object( $obj );

		return '' !== $obj->new_version
			&& '' !== $obj->package
			&& '' !== $obj->url
			&& '' !== $obj->plugin
			&& '' !== $obj->slug;
	}

	/**
	 * @return string WordPress version string safe for update metadata (never null).
	 */
	private function wp_version_for_update_meta(): string {
		global $wp_version;

		if ( is_string( $wp_version ) && '' !== $wp_version ) {
			return $wp_version;
		}

		$blog_version = get_bloginfo( 'version' );
		return is_string( $blog_version ) ? $blog_version : '';
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		$installed_ver = $this->get_installed_version();

		$this->prune_stale_update_transient_keys( $transient );

		$release = $this->fetch_release( false );
		if ( '' !== $release['error'] || '' === $release['version'] || '' === $release['package'] ) {
			$this->clear_pdx_update_transient_entries( $transient );
			return $this->prepare_update_transient_for_storage( $transient );
		}

		$up_to_date = version_compare( $installed_ver, $release['version'], '>=' );

		foreach ( $this->update_transient_basenames() as $plugin ) {
			$offer = $this->build_update_offer( $release, $plugin );
			if ( ! $this->update_object_is_valid( $offer ) ) {
				unset( $transient->response[ $plugin ], $transient->no_update[ $plugin ] );
				continue;
			}
			if ( $up_to_date ) {
				unset( $transient->response[ $plugin ] );
				$transient->no_update[ $plugin ] = $offer;
			} else {
				unset( $transient->no_update[ $plugin ] );
				$transient->response[ $plugin ] = $offer;
			}
		}

		return $this->prepare_update_transient_for_storage( $transient );
	}

	public function plugins_api( $result, $action, $args ) {
		if ( ! is_string( $action ) || 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = is_object( $args ) && isset( $args->slug )
			? (string) $args->slug
			: '';

		if ( PDX_SLUG !== $slug ) {
			return $result;
		}

		$release = $this->fetch_release( false );
		// fetch_release() always returns a normalized array now.
		if ( '' !== $release['error'] || '' === $release['version'] || '' === $release['package'] ) {
			return $result;
		}

		$notes = '' !== $release['notes'] ? $release['notes'] : 'See GitHub releases for changelog.';

		return $this->sanitize_plugins_api_object(
			(object) [
				'name'              => $release['name'],
				'slug'              => PDX_SLUG,
				'version'           => $release['version'],
				'author'            => '<a href="https://paxdesign.io">PaxDesign</a>',
				'author_profile'    => 'https://paxdesign.io',
				'homepage'          => $release['url'],
				'download_link'     => $release['package'],
				'requires'          => '6.0',
				'requires_php'      => '8.0',
				'tested'            => $this->wp_version_for_update_meta(),
				'last_updated'      => gmdate( 'Y-m-d' ),
				'sections'          => [
					'description' => 'Enterprise utility dock for WordPress.',
					'changelog'   => wp_kses_post( $notes ),
				],
				'icons'             => [],
				'banners'           => [],
				'banners_rtl'       => [],
				'compatibility'     => (object) [],
				'ratings'           => [],
				'num_ratings'       => 0,
				'rating'            => 0,
				'active_installs'   => 0,
				'added'             => '',
				'contributors'      => [],
			]
		);
	}

	/**
	 * plugins_api() responses must not contain null strings (thickbox / esc_url paths).
	 *
	 * @param object $info Plugin information object.
	 */
	private function sanitize_plugins_api_object( object $info ): object {
		$info->name          = $this->string_field( $info->name ?? null, 'PaxDesign Utility Dock' );
		$info->slug          = $this->string_field( $info->slug ?? null, PDX_SLUG );
		$info->version       = $this->string_field( $info->version ?? null, '' );
		$info->author        = $this->string_field( $info->author ?? null, '' );
		$info->author_profile = $this->string_field( $info->author_profile ?? null, 'https://paxdesign.io' );
		$info->homepage      = $this->string_field( $info->homepage ?? null, self::UPDATE_URI );
		$info->download_link = $this->string_field( $info->download_link ?? null, '' );
		$info->requires      = $this->string_field( $info->requires ?? null, '6.0' );
		$info->requires_php  = $this->string_field( $info->requires_php ?? null, '8.0' );
		$info->tested        = $this->wp_version_for_update_meta();
		$info->last_updated  = $this->string_field( $info->last_updated ?? null, gmdate( 'Y-m-d' ) );
		$info->added         = $this->string_field( $info->added ?? null, '' );

		$sections = [];
		if ( isset( $info->sections ) && ( is_array( $info->sections ) || is_object( $info->sections ) ) ) {
			foreach ( (array) $info->sections as $key => $value ) {
				if ( ! is_string( $key ) || '' === $key ) {
					continue;
				}
				$sections[ $key ] = $this->string_field( $value, '' );
			}
		}
		if ( ! isset( $sections['description'] ) ) {
			$sections['description'] = 'Enterprise utility dock for WordPress.';
		}
		$info->sections = $sections;

		$info->icons       = $this->sanitize_url_map( $info->icons ?? null );
		$info->banners     = $this->sanitize_url_map( $info->banners ?? null );
		$info->banners_rtl = $this->sanitize_url_map( $info->banners_rtl ?? null );

		if ( ! isset( $info->compatibility ) || ! is_object( $info->compatibility ) ) {
			$info->compatibility = (object) [];
		}

		return $this->coerce_null_scalars_on_object( $info );
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

		// Explicitly disable WP 6.3+ temp_backup (move_dir to upgrade-temp-backup fails on Hostinger).
		$options['hook_extra']['temp_backup'] = false;
		$options['abort_if_destination_exists'] = false;
		$options['clear_destination']           = true;
		$options['destination']                 = $this->canonical_plugin_dir();
		$options['hook_extra']['plugin']        = $this->canonical_plugin_basename();

		return $options;
	}

	/**
	 * WordPress must install into wp-content/plugins/paxdesign-toolbar only — never paxdesign-toolbar-x.y.z.
	 *
	 * @param array<string, mixed> $options   Install package options.
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 * @return array<string, mixed>
	 */
	public function force_canonical_install_destination( array $options, array $hook_extra = [] ): array {
		$extra = ( ! empty( $options['hook_extra'] ) && is_array( $options['hook_extra'] ) )
			? $options['hook_extra']
			: $hook_extra;

		if ( ! $this->is_our_plugin( $extra ) ) {
			return $options;
		}

		$this->enforce_canonical_install();

		$options['destination']                 = $this->canonical_plugin_dir();
		$options['clear_destination']           = true;
		$options['abort_if_destination_exists'] = false;

		if ( ! isset( $options['hook_extra'] ) || ! is_array( $options['hook_extra'] ) ) {
			$options['hook_extra'] = is_array( $extra ) ? $extra : [];
		}
		$options['hook_extra']['plugin'] = $this->canonical_plugin_basename();

		return $options;
	}

	/**
	 * Hide duplicate versioned plugin rows — WordPress must list only the canonical instance.
	 *
	 * @param array<string, array<string, mixed>> $plugins All plugins.
	 * @return array<string, array<string, mixed>>
	 */
	public function filter_plugins_list_single_instance( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		$canonical = $this->canonical_plugin_basename();

		foreach ( array_keys( $plugins ) as $basename ) {
			if ( $this->is_noncanonical_pdx_basename( $basename ) ) {
				unset( $plugins[ $basename ] );
			}
		}

		if ( isset( $plugins[ $canonical ] ) && is_array( $plugins[ $canonical ] ) ) {
			$plugins[ $canonical ] = $this->sanitize_plugin_header_row( $plugins[ $canonical ] );
		}

		return $plugins;
	}

	/**
	 * Repair double-nested installs (paxdesign-toolbar-8.6.4/paxdesign-toolbar/) and purge orphan metadata.
	 */
	public function repair_broken_install_layout(): void {
		static $ran = false;
		if ( $ran || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return;
		}
		$ran = true;

		$canonical = $this->canonical_plugin_dir();
		$canon_main = $canonical . '/' . self::PLUGIN_MAIN_FILE;
		$changed    = false;

		foreach ( glob( WP_PLUGIN_DIR . '/paxdesign-toolbar-*', GLOB_ONLYDIR ) ?: [] as $versioned_dir ) {
			if ( $this->is_canonical_plugin_dir( $versioned_dir ) ) {
				continue;
			}

			$nested_dir  = $versioned_dir . '/' . self::PLUGIN_FOLDER;
			$nested_main = $nested_dir . '/' . self::PLUGIN_MAIN_FILE;
			$flat_main   = $versioned_dir . '/' . self::PLUGIN_MAIN_FILE;

			if ( is_readable( $nested_main ) && $this->is_our_plugin_header_file( $nested_main ) ) {
				wp_mkdir_p( $canonical );
				$this->copy_directory( $nested_dir, $canonical );
				$changed = true;
			} elseif ( is_readable( $flat_main ) && $this->is_our_plugin_header_file( $flat_main ) ) {
				wp_mkdir_p( $canonical );
				$this->copy_directory( $versioned_dir, $canonical );
				$changed = true;
			}

			if ( is_dir( $versioned_dir ) ) {
				$this->delete_directory( $versioned_dir );
				$changed = true;
			}
		}

		if ( $changed || ! is_readable( $canon_main ) ) {
			$this->consolidate_versioned_install_into_canonical();
			$this->remove_all_versioned_plugin_directories();
			$this->maybe_repair_active_plugins_list();
			$this->clear_all_caches();
			$this->repair_stored_update_transient();
			$this->flush_plugin_cache();
		}
	}

	/**
	 * Single enforcement pass per request — merge into canonical, delete versioned folders, repair active list.
	 */
	public function enforce_canonical_install(): void {
		static $ran = false;
		if ( $ran ) {
			return;
		}
		$ran = true;

		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! is_dir( WP_PLUGIN_DIR ) ) {
			return;
		}

		$this->consolidate_versioned_install_into_canonical();
		$this->remove_all_versioned_plugin_directories();
		$this->maybe_repair_active_plugins_list();
		$this->flush_plugin_cache();
	}

	/**
	 * Delete every paxdesign-toolbar-x.y.z directory under wp-content/plugins.
	 */
	private function remove_all_versioned_plugin_directories(): void {
		$canonical = $this->canonical_plugin_dir_path();
		$patterns  = [
			WP_PLUGIN_DIR . '/paxdesign-toolbar-*',
			WP_PLUGIN_DIR . '/' . self::PLUGIN_FOLDER . '/paxdesign-toolbar-*',
		];

		foreach ( $patterns as $pattern ) {
			foreach ( glob( $pattern, GLOB_ONLYDIR ) ?: [] as $path ) {
				if ( ! is_dir( $path ) ) {
					continue;
				}
				$this->delete_duplicate_plugin_directory( $path, $canonical );
			}
		}

		$plugins_dir = WP_PLUGIN_DIR;
		$entries     = @scandir( $plugins_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || $entry === self::PLUGIN_FOLDER ) {
				continue;
			}
			if ( ! $this->is_versioned_folder_name( $entry ) ) {
				continue;
			}
			$path = $plugins_dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->delete_duplicate_plugin_directory( $path, $canonical );
			}
		}
	}

	private function is_versioned_folder_name( string $name ): bool {
		return (bool) preg_match( '#^paxdesign-toolbar-[0-9].*$#', $name );
	}

	/**
	 * @param array<string, array<string, mixed>> $plugins
	 */
	private function newest_versioned_basename( array $plugins ): string {
		$newest    = '';
		$newest_ver = '0.0.0';

		foreach ( array_keys( $plugins ) as $basename ) {
			if ( ! $this->is_versioned_plugin_basename( $basename ) ) {
				continue;
			}
			$main = WP_PLUGIN_DIR . '/' . $basename;
			if ( ! is_readable( $main ) ) {
				continue;
			}
			$ver = $this->read_plugin_version( $main );
			if ( version_compare( $ver, $newest_ver, '>' ) ) {
				$newest_ver = $ver;
				$newest     = $basename;
			}
		}

		return $newest;
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
		$leaf       = basename( $source );

		// Packages must never keep a versioned folder name (paxdesign-toolbar-8.4.3) inside the ZIP.
		if ( $this->is_versioned_folder_name( $leaf ) && $source !== $normalized ) {
			if ( $wp_filesystem->exists( $normalized ) && ! $this->is_under_plugins_dir( $normalized ) ) {
				$wp_filesystem->delete( $normalized, true );
			}
		}

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

		$this->enforce_canonical_install();
		$this->init_filesystem();
		$this->ensure_upgrade_directories();

		$this->upgrade_finalized           = false;
		$this->upgrade_shutdown_registered = false;

		$target_version = '';
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['new_version'] ) ) {
			$target_version = (string) $hook_extra['new_version'];
		} else {
			$release = $this->fetch_release( false );
			if ( '' !== $release['version'] ) {
				$target_version = $release['version'];
			}
		}

		$this->set_state(
			[
				'upgrading' => true,
				'started'   => time(),
				'from'      => $this->get_installed_version(),
				'target'    => $target_version,
			]
		);

		$this->cleanup_failed_backups();
		$this->cleanup_temp_backup_plugin_dir();
		$this->cleanup_upgrade_working_dirs();
		$this->register_upgrade_shutdown_guard();
		$this->relocate_to_canonical_before_upgrade();

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

		$install_dir = $this->resolve_install_dir_from_result( is_array( $result ) ? $result : [] );
		$main_file   = $install_dir . '/' . self::PLUGIN_MAIN_FILE;

		if ( ! is_readable( $main_file ) ) {
			$this->handle_failed_upgrade( $hook_extra );
			return new WP_Error(
				'pdx_invalid_package',
				__( 'Update package is missing paxdesign-toolbar.php.', 'paxdesign-toolbar' )
			);
		}

		$installed_version = $this->read_plugin_version( $main_file );
		if ( '' === $installed_version || '0.0.0' === $installed_version ) {
			$this->handle_failed_upgrade( $hook_extra );
			return new WP_Error(
				'pdx_invalid_version',
				__( 'Updated package does not contain a valid plugin version.', 'paxdesign-toolbar' )
			);
		}

		$state  = $this->get_state();
		$target = ! empty( $state['target'] ) ? (string) $state['target'] : '';
		if ( '' === $target && is_array( $hook_extra ) && ! empty( $hook_extra['new_version'] ) ) {
			$target = (string) $hook_extra['new_version'];
		}

		if ( $target && version_compare( $installed_version, $target, '<' ) ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log(
					sprintf(
						'[PDX] verify_install failed: installed %s at %s, expected %s',
						$installed_version,
						$install_dir,
						$target
					)
				);
			}
			$this->handle_failed_upgrade( $hook_extra );
			return new WP_Error(
				'pdx_version_mismatch',
				sprintf(
					/* translators: 1: found version, 2: expected version */
					__( 'Update package version %1$s does not match expected %2$s.', 'paxdesign-toolbar' ),
					$installed_version,
					$target
				)
			);
		}

		if ( ! $this->is_canonical_plugin_dir( $install_dir ) ) {
			$this->consolidate_install_to_canonical_folder( is_array( $result ) ? $result : [] );
			$this->remove_all_versioned_plugin_directories();
			$this->maybe_repair_active_plugins_list();
		}

		$this->schedule_deferred_upgrade_cleanup( $result );

		return $response;
	}

	/**
	 * If WordPress marks the upgrade failed but the canonical install is healthy and current, recover success.
	 *
	 * @param mixed $response
	 * @param array<string, mixed> $hook_extra
	 * @param mixed $result
	 * @return mixed
	 */
	public function recover_post_install_success( $response, $hook_extra, $result ) {
		if ( ! $this->is_our_plugin( is_array( $hook_extra ) ? $hook_extra : null ) ) {
			return $response;
		}

		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $this->plugin_passes_health_check() ) {
			return $response;
		}

		$installed = $this->get_installed_version();
		$release   = $this->fetch_release( false );
		if ( '' !== $installed && '' !== ( $release['version'] ?? '' )
			&& version_compare( $installed, $release['version'], '>=' ) ) {
			return is_array( $result ) ? $result : true;
		}

		if ( $this->is_upgrade_successful() ) {
			return is_array( $result ) ? $result : true;
		}

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

		if ( is_wp_error( $result ) && ( $this->plugin_passes_health_check() || $this->is_upgrade_successful() ) ) {
			return true;
		}

		return $result;
	}

	/**
	 * WordPress may set WP_Error on cleanup (temp_backup) even when files installed correctly.
	 *
	 * @param WP_Upgrader $upgrader
	 * @param array<string, mixed> $hook_extra
	 */
	public function reconcile_upgrader_result( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) || ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}

		if ( ! is_object( $upgrader ) || ! isset( $upgrader->result ) ) {
			return;
		}

		if ( is_wp_error( $upgrader->result ) && $this->plugin_passes_health_check() ) {
			$upgrader->result = true;
		}
	}

	public function on_upgrade_complete( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) || ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}

		$failed = is_object( $upgrader ) && isset( $upgrader->result ) && is_wp_error( $upgrader->result );
		if ( $failed && $this->plugin_passes_health_check() ) {
			$failed = false;
			if ( is_object( $upgrader ) ) {
				$upgrader->result = true;
			}
		}

		$this->finalize_upgrade_transaction( ! $failed, $hook_extra );
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

	/**
	 * Flush the release transient after any upgrade that touches our plugin.
	 * Ensures the next admin page load re-fetches from GitHub and reflects the
	 * newly installed version rather than serving stale cached metadata.
	 *
	 * @param WP_Upgrader          $upgrader
	 * @param array<string, mixed> $hook_extra
	 */
	public function flush_release_transient_on_upgrade( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) || ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}
		$this->maybe_repair_active_plugins_list();
		$this->refresh_plugin_update_metadata();
	}

	/**
	 * Keep activation state and update metadata aligned with the loaded plugin basename.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Network activation flag.
	 */
	public function on_activated_plugin( $plugin, $network_wide ): void {
		if ( ! is_string( $plugin ) || ! $this->is_plugin_basename_ours( $plugin ) ) {
			return;
		}

		$this->enforce_canonical_install();
		$this->refresh_plugin_update_metadata();
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
		try {
			$this->maybe_cleanup_stale_maintenance();

			if ( ! $this->plugin_passes_health_check() ) {
				if ( class_exists( 'PDX_Recovery', false ) && PDX_Recovery::should_restore_from_backup() ) {
					PDX_Recovery::restore_from_backup();
					PDX_Recovery::release_maintenance_file();
					PDX_Recovery::clear_upgrade_state();
				}
				return;
			}

			$state = $this->get_state();
			if ( empty( $state['upgrading'] ) ) {
				return;
			}

			// Stale upgrade flag only — never auto-finalize "success" on every request.
			if ( ! empty( $state['started'] ) && ( time() - (int) $state['started'] ) > 900 ) {
				$this->finalize_upgrade_transaction( $this->plugin_passes_health_check(), null );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] bootstrap_recovery: ' . $e->getMessage() );
			}
			$this->release_maintenance_mode();
		}
	}

	public function maybe_cleanup_stale_maintenance(): void {
		try {
			$file = $this->maintenance_file();
			if ( ! $file ) {
				return;
			}

			$state = $this->get_state();
			if ( empty( $state['upgrading'] ) ) {
				$this->release_maintenance_mode();
				return;
			}

			if ( $this->is_maintenance_stale( $file ) ) {
				$this->release_maintenance_mode();
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] maybe_cleanup_stale_maintenance: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Remove WordPress maintenance mode file — never call WP_Upgrader methods (not public API).
	 */
	public function release_maintenance_mode(): void {
		try {
			if ( class_exists( 'PDX_Recovery', false ) ) {
				PDX_Recovery::release_maintenance_file();
				return;
			}

			$this->unlink_maintenance_file();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] release_maintenance_mode: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Delete ABSPATH/.maintenance when present (safe on all WP versions).
	 */
	private function unlink_maintenance_file(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		$file = ABSPATH . '.maintenance';
		if ( ! is_file( $file ) ) {
			return;
		}

		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $file );
		} else {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		clearstatcache( true, ABSPATH );
	}

	/**
	 * Single exit point for upgrade success/failure — maintenance, artifacts, rollback.
	 *
	 * @param array<string, mixed>|null $hook_extra
	 */
	private function finalize_upgrade_transaction( bool $success, ?array $hook_extra = null ): void {
		if ( $this->upgrade_finalized ) {
			$this->release_maintenance_mode();
			return;
		}
		$this->upgrade_finalized = true;

		$this->release_maintenance_mode();
		$this->cleanup_upgrade_artifacts();

		if ( $success || $this->plugin_passes_health_check() ) {
			$this->refresh_plugin_update_metadata();
			$current_backup = isset( $this->get_state()['backup'] ) ? (string) $this->get_state()['backup'] : '';
			$next_state     = [
				'deferred_cleanup' => true,
				'last_success'     => time(),
			];
			if ( '' !== $current_backup ) {
				$next_state['backup'] = $current_backup;
			}
			$this->set_state( $next_state );
		} else {
			$this->maybe_rollback( $hook_extra );
			$this->refresh_plugin_update_metadata();
			$this->cleanup_failed_backups();
			$state = $this->get_state();
			unset( $state['upgrading'] );
			$this->set_state( $state );
		}
	}

	private function register_upgrade_shutdown_guard(): void {
		if ( $this->upgrade_shutdown_registered ) {
			return;
		}
		$this->upgrade_shutdown_registered = true;
		$this->upgrade_finalized           = false;

		register_shutdown_function(
			function (): void {
				try {
					if ( $this->upgrade_finalized ) {
						$this->release_maintenance_mode();
						return;
					}

					$state = $this->get_state();
					if ( empty( $state['upgrading'] ) ) {
						if ( $this->maintenance_file() ) {
							$this->release_maintenance_mode();
						}
						return;
					}

					$this->finalize_upgrade_transaction( $this->is_upgrade_successful(), null );
				} catch ( Throwable $e ) {
					if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						error_log( '[PDX] upgrade_shutdown_guard: ' . $e->getMessage() );
					}
					$this->release_maintenance_mode();
				}
			}
		);
	}

	/**
	 * Heavy filesystem work deferred until after WP_Upgrader releases locks.
	 *
	 * @param array<string, mixed> $result
	 */
	private function schedule_deferred_upgrade_cleanup( array $result ): void {
		$state = $this->get_state();
		$state['deferred_cleanup'] = true;
		$state['install_result']   = [
			'destination'       => $result['destination'] ?? '',
			'local_destination' => $result['local_destination'] ?? '',
		];
		unset( $state['upgrading'] );
		$this->set_state( $state );
	}

	public function run_deferred_upgrade_cleanup(): void {
		static $ran = false;
		if ( $ran ) {
			return;
		}
		$ran = true;

		$state = $this->get_state();
		if ( empty( $state['deferred_cleanup'] ) ) {
			return;
		}

		// Clear flag first so a fatal during cleanup cannot re-trigger endlessly.
		$this->set_state( [ 'last_success' => (int) ( $state['last_success'] ?? time() ) ] );

		try {
			if ( ! $this->plugin_passes_health_check() ) {
				$this->maybe_rollback( null );
				PDX_Recovery::release_maintenance_file();
				return;
			}

			if ( ! empty( $state['install_result'] ) && is_array( $state['install_result'] ) ) {
				$this->consolidate_install_to_canonical_folder( $state['install_result'] );
			}

			if ( $this->plugin_passes_health_check() ) {
				$this->purge_legacy_files();
				$this->cleanup_duplicate_plugin_folders( true );
				$this->cleanup_upgrade_artifacts();
				$this->cleanup_failed_backups();
			} else {
				$this->maybe_rollback( null );
			}
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] deferred_upgrade_cleanup: ' . $e->getMessage() );
			}
			$this->maybe_rollback( null );
		} finally {
			$this->release_maintenance_mode();
		}
	}

	/**
	 * Verify plugin files are present and readable after an update.
	 */
	public function plugin_passes_health_check(): bool {
		if ( class_exists( 'PDX_Recovery', false ) ) {
			return PDX_Recovery::install_is_healthy();
		}

		$main = $this->plugin_dir() . '/' . self::PLUGIN_MAIN_FILE;
		if ( ! is_readable( $main ) ) {
			return false;
		}

		$required = [
			'includes/class-pdx-loader.php',
			'includes/class-pdx-target.php',
			'includes/class-pdx-http.php',
			'includes/class-pdx-intelligence.php',
		];

		foreach ( $required as $rel ) {
			if ( ! is_readable( $this->plugin_dir() . '/' . $rel ) ) {
				return false;
			}
		}

		return true;
	}

	private function cleanup_upgrade_artifacts(): void {
		$this->cleanup_temp_backup_plugin_dir();
		$this->cleanup_upgrade_working_dirs();
	}

	private function cleanup_temp_backup_plugin_dir(): void {
		$base = WP_CONTENT_DIR . '/upgrade-temp-backup/plugins';
		if ( ! is_dir( $base ) ) {
			return;
		}

		foreach ( glob( $base . '/paxdesign-toolbar*' ) ?: [] as $path ) {
			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			}
		}

		// Remove empty plugins backup dir if nothing else is inside.
		$entries = @scandir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $entries ) && count( $entries ) <= 2 ) {
			$this->delete_directory( $base );
			$parent = dirname( $base );
			$parent_entries = @scandir( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $parent_entries ) && count( $parent_entries ) <= 2 ) {
				$this->delete_directory( $parent );
			}
		}
	}

	private function cleanup_upgrade_working_dirs(): void {
		$upgrade = WP_CONTENT_DIR . '/upgrade';
		if ( ! is_dir( $upgrade ) ) {
			return;
		}

		foreach ( glob( $upgrade . '/paxdesign-toolbar*' ) ?: [] as $path ) {
			if ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			}
		}
	}

	private function is_upgrade_successful(): bool {
		if ( ! $this->plugin_passes_health_check() ) {
			return false;
		}

		$main      = $this->plugin_dir() . '/' . self::PLUGIN_MAIN_FILE;
		$installed = $this->read_plugin_version( $main );
		if ( '' === $installed || '0.0.0' === $installed ) {
			return false;
		}

		$state  = $this->get_state();
		$from   = isset( $state['from'] ) ? (string) $state['from'] : '';
		$target = isset( $state['target'] ) ? (string) $state['target'] : '';

		if ( $target && version_compare( $installed, $target, '>=' ) ) {
			return true;
		}

		if ( $from && version_compare( $installed, $from, '>' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Remove legacy files left from older releases (not overwritten by ZIP).
	 */
	private function purge_legacy_files(): void {
		if ( ! class_exists( 'PDX_Recovery', false ) ) {
			return;
		}

		$manifest = PDX_Recovery::upgrade_manifest();
		$paths    = $manifest['legacy_remove'] ?? [];
		if ( empty( $paths ) ) {
			return;
		}

		$base = $this->plugin_dir();
		foreach ( $paths as $rel ) {
			$path = $base . '/' . ltrim( (string) $rel, '/' );
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( is_dir( $path ) ) {
				$this->delete_directory( $path );
			}
		}
	}

	/**
	 * @param array<string, mixed>|null $hook_extra
	 */
	private function handle_failed_upgrade( ?array $hook_extra = null ): void {
		$this->finalize_upgrade_transaction( false, $hook_extra );
	}

	/**
	 * Restore from backup only when the current install is broken or older than the backup.
	 * Never rolls back over a valid install that is equal to or newer than the backup.
	 *
	 * @param array<string, mixed>|null $hook_extra
	 */
	private function maybe_rollback( ?array $hook_extra = null ): void {
		if ( $hook_extra && ! $this->is_our_plugin( $hook_extra ) ) {
			return;
		}

		$state  = $this->get_state();
		$backup = isset( $state['backup'] ) ? (string) $state['backup'] : '';

		if ( '' === $backup || ! is_dir( $backup ) ) {
			return;
		}

		$backup_main = $backup . '/' . self::PLUGIN_MAIN_FILE;
		if ( ! is_readable( $backup_main ) ) {
			return;
		}

		$target      = $this->plugin_dir();
		$target_main = $target . '/' . self::PLUGIN_MAIN_FILE;

		// If the current install is healthy and at least as new as the backup, do not roll back.
		if ( is_readable( $target_main ) ) {
			$current_ver = $this->read_plugin_version( $target_main );
			$backup_ver  = $this->read_plugin_version( $backup_main );
			if ( '' !== $current_ver && '0.0.0' !== $current_ver
				&& '' !== $backup_ver && '0.0.0' !== $backup_ver
				&& version_compare( $current_ver, $backup_ver, '>=' )
				&& $this->plugin_passes_health_check()
			) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( sprintf(
						'[PDX] Rollback skipped: current %s >= backup %s and install is healthy.',
						$current_ver,
						$backup_ver
					) );
				}
				return;
			}
		}

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[PDX] Rolling back to backup: ' . $backup );
		}

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

	public function maybe_migrate_to_canonical_dir(): void {
		$this->enforce_canonical_install();
	}

	private function create_copy_backup(): ?string {
		$canonical = $this->canonical_plugin_dir();
		$source    = is_readable( $canonical . '/' . self::PLUGIN_MAIN_FILE )
			? $canonical
			: $this->plugin_dir();
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
		$canonical   = $this->canonical_plugin_dir();
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

		$canonical = $this->canonical_plugin_dir();
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
		if ( is_readable( $main_file ) && ! $this->is_our_plugin_header_file( $main_file ) ) {
			return;
		}

		$this->delete_directory( $path );
	}

	private function is_versioned_plugin_basename( string $basename ): bool {
		if ( $basename === $this->canonical_plugin_basename() ) {
			return false;
		}

		return (bool) preg_match(
			'#^paxdesign-toolbar-[^/]+/(?:paxdesign-toolbar/)?paxdesign-toolbar\.php$#',
			$basename
		);
	}

	private function is_plugin_active_basename( string $basename ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $basename );
	}

	private function preferred_active_basename(): string {
		$canonical_main = $this->canonical_plugin_dir() . '/' . self::PLUGIN_MAIN_FILE;
		if ( is_readable( $canonical_main ) && $this->is_our_plugin_header_file( $canonical_main ) ) {
			return plugin_basename( $canonical_main );
		}

		return $this->plugin_basename();
	}

	private function maybe_repair_active_plugins_list(): void {
		$correct = $this->preferred_active_basename();
		$active  = (array) get_option( 'active_plugins', [] );
		$changed = false;
		$has_ok  = in_array( $correct, $active, true );

		foreach ( $active as $index => $basename ) {
			if ( $basename === $correct ) {
				continue;
			}
			if ( ! $this->is_plugin_basename_ours( (string) $basename ) ) {
				continue;
			}
			unset( $active[ $index ] );
			$changed = true;
		}

		if ( ! $has_ok && is_readable( $this->canonical_plugin_dir() . '/' . self::PLUGIN_MAIN_FILE ) ) {
			$active[] = $correct;
			$changed  = true;
		}

		if ( $changed ) {
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
		return wp_normalize_path( realpath( $this->canonical_plugin_dir() ) ?: $this->canonical_plugin_dir() );
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function resolve_install_dir_from_result( array $result ): string {
		foreach ( [ 'destination', 'local_destination' ] as $key ) {
			if ( empty( $result[ $key ] ) ) {
				continue;
			}
			$dest = wp_normalize_path( untrailingslashit( (string) $result[ $key ] ) );
			$main = $dest . '/' . self::PLUGIN_MAIN_FILE;
			if ( is_readable( $main ) && $this->is_our_plugin_header_file( $main ) ) {
				return $dest;
			}
		}

		return $this->plugin_dir();
	}

	/**
	 * Move versioned installs (paxdesign-toolbar-x.y.z) into wp-content/plugins/paxdesign-toolbar/
	 * so WordPress and PaxDesign always upgrade the same directory.
	 */
	private function relocate_to_canonical_before_upgrade(): void {
		if ( $this->uses_canonical_plugin_dir() ) {
			return;
		}

		$live    = $this->plugin_dir();
		$target  = $this->canonical_plugin_dir();
		$main    = $live . '/' . self::PLUGIN_MAIN_FILE;

		if ( ! is_readable( $main ) || ! $this->is_our_plugin_header_file( $main ) ) {
			return;
		}

		wp_mkdir_p( $target );
		$this->copy_directory( $live, $target );
		$this->maybe_repair_active_plugins_list();

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[PDX] Relocated live plugin to canonical path before upgrade: ' . $target );
		}
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
			if ( ( time() - (int) $state['started'] ) < self::MAINTENANCE_GRACE_ACTIVE ) {
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

		$this->refresh_plugin_update_metadata();
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
