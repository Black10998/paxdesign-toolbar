<?php
/**
 * Frontend — enqueues assets and renders the dock HTML.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Frontend {

	public function __construct(
		private PDX_Settings        $settings,
		private PDX_Module_Registry $modules
	) {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_footer',          [ $this, 'render' ], 100 );
	}

	private function asset_version( string $relative_path ): string {
		$path = PDX_DIR . ltrim( $relative_path, '/' );
		$mtime = is_readable( $path ) ? (string) filemtime( $path ) : '';

		return PDX_VERSION . ( '' !== $mtime ? '.' . $mtime : '' );
	}

	public function enqueue(): void {
		if ( ! $this->settings->should_render() ) return;

		// Tell page-caching plugins not to cache pages that include the dock.
		// PDX_CONFIG contains module state that must be fresh for every visitor.
		// This header is respected by WP Super Cache, W3TC, LiteSpeed Cache, etc.
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}
		// WP Super Cache / W3TC hook-based bypass.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		if ( ! defined( 'DONOTCACHEDB' )   ) define( 'DONOTCACHEDB',   true );

		wp_enqueue_style(
			'pdx-tokens',
			PDX_URL . 'assets/css/pdx-tokens.css',
			[],
			$this->asset_version( 'assets/css/pdx-tokens.css' )
		);

		wp_enqueue_style(
			'pdx-dock',
			PDX_URL . 'assets/css/dock.css',
			[ 'pdx-tokens' ],
			$this->asset_version( 'assets/css/dock.css' )
		);

		wp_enqueue_style(
			'pdx-dock-ui',
			PDX_URL . 'assets/css/pdx-dock-ui.css',
			[ 'pdx-dock', 'pdx-tokens' ],
			$this->asset_version( 'assets/css/pdx-dock-ui.css' )
		);

		wp_enqueue_style(
			'pdx-panel-scroll',
			PDX_URL . 'assets/css/pdx-panel-scroll.css',
			[ 'pdx-dock-ui' ],
			$this->asset_version( 'assets/css/pdx-panel-scroll.css' )
		);

		wp_enqueue_style(
			'pdx-intel-activity',
			PDX_URL . 'assets/css/pdx-intel-activity.css',
			[ 'pdx-dock-ui' ],
			$this->asset_version( 'assets/css/pdx-intel-activity.css' )
		);

		wp_enqueue_style(
			'pdx-ai-analysis-loader',
			PDX_URL . 'assets/css/pdx-ai-analysis-loader.css',
			[ 'pdx-intel-activity' ],
			$this->asset_version( 'assets/css/pdx-ai-analysis-loader.css' )
		);

		wp_enqueue_style(
			'pdx-icons',
			PDX_URL . 'assets/css/pdx-icons.css',
			[ 'pdx-ai-analysis-loader' ],
			$this->asset_version( 'assets/css/pdx-icons.css' )
		);

		wp_enqueue_style(
			'pdx-module-chrome',
			PDX_URL . 'assets/css/pdx-module-chrome.css',
			[ 'pdx-icons' ],
			$this->asset_version( 'assets/css/pdx-module-chrome.css' )
		);

		wp_enqueue_style(
			'pdx-unified-ui',
			PDX_URL . 'assets/css/pdx-unified-ui.css',
			[ 'pdx-module-chrome', 'pdx-tokens' ],
			$this->asset_version( 'assets/css/pdx-unified-ui.css' )
		);

		// Use defer strategy (WP 6.3+) so the script always runs after the DOM is ready.
		// Falls back to in_footer=true on older WordPress, which is safe because
		// dock.js wraps its init in a DOMContentLoaded guard.
		$script_args = [ 'strategy' => 'defer', 'in_footer' => true ];

		wp_enqueue_script(
			'pdx-ai-analysis-loader',
			PDX_URL . 'assets/js/pdx-ai-analysis-loader.js',
			[],
			$this->asset_version( 'assets/js/pdx-ai-analysis-loader.js' ),
			$script_args
		);
		wp_enqueue_script(
			'pdx-module-icons',
			PDX_URL . 'assets/js/pdx-module-icons.js',
			[ 'pdx-ai-analysis-loader' ],
			$this->asset_version( 'assets/js/pdx-module-icons.js' ),
			$script_args
		);
		wp_enqueue_script(
			'pdx-dock',
			PDX_URL . 'assets/js/dock.js',
			[ 'pdx-module-icons' ],
			$this->asset_version( 'assets/js/dock.js' ),
			$script_args
		);

		wp_enqueue_script(
			'pdx-dock-v81',
			PDX_URL . 'assets/js/dock-v81.js',
			[ 'pdx-dock' ],
			$this->asset_version( 'assets/js/dock-v81.js' ),
			$script_args
		);

		// Pass config to JS
		wp_localize_script( 'pdx-dock', 'PDX_CONFIG', $this->js_config() );

		// Sync mobile breakpoint with admin (CSS defaults to 680/681).
		$bp     = min( 1280, max( 320, (int) $this->settings->get( 'mobile_breakpoint', 680 ) ) );
		$accent = sanitize_hex_color( $this->settings->get( 'accent_color', '#ffffff' ) ) ?: '#ffffff';
		wp_add_inline_style(
			'pdx-dock',
			sprintf(
				':root{--pdx-mobile-max:%1$dpx;--pdx-mobile-min:%2$dpx;}#pdx-root{--pdx-accent:%3$s;--pdx-green:%3$s;--pdx-emerald:%3$s;}',
				$bp,
				$bp + 1,
				$accent
			)
		);

		// Custom CSS from admin
		$custom = $this->settings->get( 'custom_css' );
		if ( $custom ) {
			wp_add_inline_style( 'pdx-dock', wp_strip_all_tags( $custom ) );
		}
	}

	private function js_config(): array {
		$modules = $this->modules->all_with_pricing( $this->settings );
		$enabled = [];

		foreach ( $modules as $id => $mod ) {
			if ( $this->settings->module_enabled( $id ) ) {
				$enabled[ $id ] = [
					'id'       => $id,
					'label'    => $mod['label'],
					'icon'     => $mod['icon'],
					'type'     => $mod['panel_type'],
					'category' => $mod['category'],
					'tier'     => $mod['tier'],
					'price'    => $mod['price'],
					'currency' => $mod['currency'],
				];
			}
		}

		return [
			'version'          => PDX_VERSION,
			'contact'          => $this->settings->contact_url(),
			'ctaPrimary'       => esc_html( $this->settings->get( 'cta_primary_label', 'Start a project' ) ),
			'ctaSecondary'     => esc_html( $this->settings->get( 'cta_secondary_label', 'Learn more' ) ),
			'position'         => esc_attr( $this->settings->get( 'dock_position', 'left' ) ),
			'theme'            => esc_attr( $this->settings->get( 'dock_theme', 'dark' ) ),
			'size'             => esc_attr( $this->settings->get( 'dock_size', 'default' ) ),
			'accentColor'      => esc_attr( $this->settings->get( 'accent_color', '#ffffff' ) ),
			'mobileEnabled'      => (bool)   $this->settings->get( 'mobile_enabled',       true ),
			'mobileBreakpoint'   => (int)    $this->settings->get( 'mobile_breakpoint',    680 ),
			'mobileDockPosition' => (string) $this->settings->get( 'mobile_dock_position', 'under-header' ),
			'mobileDockHeight'   => (int)    $this->settings->get( 'mobile_dock_height',   48 ),
			'mobilePanelHeight'  => (int)    $this->settings->get( 'mobile_panel_height',  90 ),
			'mobileIconSize'     => (int)    $this->settings->get( 'mobile_icon_size',     0 ),
			'mobileBtnSize'      => (int)    $this->settings->get( 'mobile_btn_size',      0 ),
			'mobileSpacing'      => (string) $this->settings->get( 'mobile_spacing',       'default' ),
			'mobileScale'        => (string) $this->settings->get( 'mobile_scale',         'auto' ),
			'mobileCompact'      => (bool)   $this->settings->get( 'mobile_compact',       false ),
			'mobileSafeArea'     => (bool)   $this->settings->get( 'mobile_safe_area',     true ),
			'mobileSwipeClose'   => (bool)   $this->settings->get( 'mobile_swipe_close',   true ),
			'mobileHideDock'     => (bool)   $this->settings->get( 'mobile_hide_dock',     true ),
			'analytics'        => (bool) $this->settings->get( 'analytics_enabled', false ),
			'aiMemory'         => (bool) $this->settings->get( 'ai_memory_enabled', true ),
			'workspaceEnabled' => (bool) $this->settings->get( 'workspace_enabled', true ),
			'modules'          => $enabled,
			'restUrl'          => esc_url( rest_url( 'pdx/v1' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'userId'           => get_current_user_id(),
			'isLoggedIn'       => is_user_logged_in(),
		];
	}

	public function render(): void {
		if ( ! $this->settings->should_render() ) return;

		$position      = esc_attr( $this->settings->get( 'dock_position',      'left' ) );
		$theme         = esc_attr( $this->settings->get( 'dock_theme',          'dark' ) );
		$size          = esc_attr( $this->settings->get( 'dock_size',           'default' ) );
		$mobile_dock   = esc_attr( $this->settings->get( 'mobile_dock_position','under-header' ) );
		?>
		<div id="pdx-root"
			 data-position="<?php echo $position; ?>"
			 data-theme="<?php echo $theme; ?>"
			 data-size="<?php echo $size; ?>"
			 data-mobile-dock="<?php echo $mobile_dock; ?>"
			 data-pdx-version="<?php echo esc_attr( PDX_VERSION ); ?>"
			 aria-label="PaxDesign Utility Dock"
			 role="complementary">
			<nav id="pdx-dock" aria-label="Utility dock tools">
				<?php $this->render_dock_items(); ?>
			</nav>
		</div>
		<?php
		// #pdx-backdrop and #pdx-panel are created by dock.js and appended
		// directly to <body> to avoid theme stacking-context traps.
	}

	private function render_dock_items(): void {
		$modules  = $this->modules->all();
		$prev_cat = null;

		foreach ( $modules as $id => $mod ) {
			if ( ! $this->settings->module_enabled( $id ) ) continue;

			// Category separator
			if ( $prev_cat !== null && $prev_cat !== $mod['category'] ) {
				echo '<span class="pdx-sep" aria-hidden="true"></span>';
			}
			$prev_cat = $mod['category'];

			printf(
				'<button class="pdx-btn pdx-btn--mod-%1$s" data-module="%1$s" data-tip="%2$s" type="button" aria-label="%2$s" aria-expanded="false">%3$s</button>',
				esc_attr( $id ),
				esc_attr( $mod['label'] ),
				PDX_Icons::module_html( $id )
			);
		}
	}

}
