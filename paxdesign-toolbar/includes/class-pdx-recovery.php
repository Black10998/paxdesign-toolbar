<?php
/**
 * Minimal recovery layer — no dependencies on other PDX classes.
 * Runs before full plugin bootstrap to restore broken installs and clear maintenance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PDX_Recovery {

	private const STATE_OPTION = 'pdx_updater_state';
	private const PLUGIN_FOLDER = 'paxdesign-toolbar';
	private const MAIN_FILE     = 'paxdesign-toolbar.php';

	/** @var list<string> */
	private const FALLBACK_REQUIRED_FILES = [
		'includes/class-pdx-loader.php',
		'includes/class-pdx-settings.php',
		'includes/class-pdx-target.php',
		'includes/class-pdx-http.php',
		'includes/class-pdx-intelligence.php',
	];

	/**
	 * @return array{required_files:list<string>,legacy_remove:list<string>}
	 */
	public static function upgrade_manifest(): array {
		$file = self::plugin_dir() . '/includes/pdx-upgrade-manifest.php';
		if ( is_readable( $file ) ) {
			$data = include $file;
			if ( is_array( $data ) ) {
				return [
					'required_files' => array_values( array_filter( (array) ( $data['required_files'] ?? [] ) ) ),
					'legacy_remove'    => array_values( array_filter( (array) ( $data['legacy_remove'] ?? [] ) ) ),
				];
			}
		}

		return [
			'required_files' => self::FALLBACK_REQUIRED_FILES,
			'legacy_remove'    => [],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function required_files(): array {
		$files = self::upgrade_manifest()['required_files'];
		return ! empty( $files ) ? $files : self::FALLBACK_REQUIRED_FILES;
	}

	public static function register(): void {
		add_action( 'plugins_loaded', [ self::class, 'boot' ], 0 );
	}

	public static function boot(): void {
		self::release_maintenance_file();

		if ( self::install_is_healthy() ) {
			return;
		}

		if ( self::restore_from_backup() ) {
			self::release_maintenance_file();
			self::clear_upgrade_state();
			return;
		}

		// Prevent fatals from missing classes — stop PaxDesign from booting.
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>PaxDesign Utility Dock</strong> is in a broken state after an update. ';
				echo 'Re-install from <code>releases/paxdesign-toolbar-' . esc_html( self::read_header_version() ) . '.zip</code> via FTP/file manager, ';
				echo 'or restore the plugin folder from backup.</p></div>';
			}
		);
	}

	public static function install_is_healthy(): bool {
		$dir = self::plugin_dir();
		if ( ! is_readable( $dir . '/' . self::MAIN_FILE ) ) {
			return false;
		}
		foreach ( self::required_files() as $rel ) {
			if ( ! is_readable( $dir . '/' . $rel ) ) {
				return false;
			}
		}
		return true;
	}

	public static function plugin_dir(): string {
		return WP_PLUGIN_DIR . '/' . self::PLUGIN_FOLDER;
	}

	public static function release_maintenance_file(): void {
		try {
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
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PDX] release_maintenance_file: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_upgrade_state(): array {
		$state = get_option( self::STATE_OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	public static function clear_upgrade_state(): void {
		delete_option( self::STATE_OPTION );
	}

	public static function restore_from_backup(): bool {
		$state  = self::get_upgrade_state();
		$backup = isset( $state['backup'] ) ? (string) $state['backup'] : '';
		if ( ! $backup || ! is_dir( $backup ) ) {
			return false;
		}
		if ( ! is_readable( $backup . '/' . self::MAIN_FILE ) ) {
			return false;
		}

		$target = self::plugin_dir();
		self::delete_dir( $target );
		return self::copy_dir( $backup, $target );
	}

	private static function read_header_version(): string {
		$main = self::plugin_dir() . '/' . self::MAIN_FILE;
		if ( ! is_readable( $main ) ) {
			return 'latest';
		}
		$data = get_file_data( $main, [ 'Version' => 'Version' ], 'plugin' );
		return ! empty( $data['Version'] ) ? (string) $data['Version'] : 'latest';
	}

	private static function copy_dir( string $from, string $to ): bool {
		if ( ! is_dir( $from ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( $to ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $from, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$sub  = substr( $item->getPathname(), strlen( $from ) + 1 );
			$dest = $to . '/' . $sub;
			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $dest ) ) {
					return false;
				}
			} elseif ( ! copy( $item->getPathname(), $dest ) ) {
				return false;
			}
		}

		return is_readable( $to . '/' . self::MAIN_FILE );
	}

	private static function delete_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
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
}
