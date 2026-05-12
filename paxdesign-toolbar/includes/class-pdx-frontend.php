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

	public function enqueue(): void {
		if ( ! $this->settings->should_render() ) return;

		wp_enqueue_style(
			'pdx-dock',
			PDX_URL . 'assets/css/dock.css',
			[],
			PDX_VERSION
		);

		wp_enqueue_script(
			'pdx-dock',
			PDX_URL . 'assets/js/dock.js',
			[],
			PDX_VERSION,
			true
		);

		// Pass config to JS
		wp_localize_script( 'pdx-dock', 'PDX_CONFIG', $this->js_config() );

		// Custom CSS from admin
		$custom = $this->settings->get( 'custom_css' );
		if ( $custom ) {
			wp_add_inline_style( 'pdx-dock', wp_strip_all_tags( $custom ) );
		}
	}

	private function js_config(): array {
		$modules = $this->modules->all();
		$enabled = [];

		foreach ( $modules as $id => $mod ) {
			if ( $this->settings->module_enabled( $id ) ) {
				$enabled[ $id ] = [
					'id'       => $id,
					'label'    => $mod['label'],
					'icon'     => $mod['icon'],
					'type'     => $mod['panel_type'],
					'category' => $mod['category'],
				];
			}
		}

		return [
			'version'         => PDX_VERSION,
			'contact'         => $this->settings->contact_url(),
			'ctaPrimary'      => esc_html( $this->settings->get( 'cta_primary_label', 'Start a project' ) ),
			'ctaSecondary'    => esc_html( $this->settings->get( 'cta_secondary_label', 'Learn more' ) ),
			'position'        => esc_attr( $this->settings->get( 'dock_position', 'left' ) ),
			'theme'           => esc_attr( $this->settings->get( 'dock_theme', 'dark' ) ),
			'size'            => esc_attr( $this->settings->get( 'dock_size', 'default' ) ),
			'accentColor'     => esc_attr( $this->settings->get( 'accent_color', '#3fb950' ) ),
			'mobileEnabled'   => (bool) $this->settings->get( 'mobile_enabled', true ),
			'mobileBreakpoint'=> (int)  $this->settings->get( 'mobile_breakpoint', 680 ),
			'analytics'       => (bool) $this->settings->get( 'analytics_enabled', false ),
			'modules'         => $enabled,
			'restUrl'         => esc_url( rest_url( 'pdx/v1' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
		];
	}

	public function render(): void {
		if ( ! $this->settings->should_render() ) return;

		$position = esc_attr( $this->settings->get( 'dock_position', 'left' ) );
		$theme    = esc_attr( $this->settings->get( 'dock_theme', 'dark' ) );
		$size     = esc_attr( $this->settings->get( 'dock_size', 'default' ) );
		?>
		<div id="pdx-root"
			 data-position="<?php echo $position; ?>"
			 data-theme="<?php echo $theme; ?>"
			 data-size="<?php echo $size; ?>"
			 aria-label="PaxDesign Utility Dock"
			 role="complementary">
			<nav id="pdx-dock" aria-label="Utility dock tools">
				<?php $this->render_dock_items(); ?>
			</nav>
			<div id="pdx-backdrop" aria-hidden="true"></div>
			<aside id="pdx-panel" role="dialog" aria-modal="true" aria-label="Tool panel">
				<div id="pdx-panel-inner"></div>
			</aside>
		</div>
		<?php
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
				'<button class="pdx-btn" data-module="%s" data-tip="%s" type="button" aria-label="%s" aria-expanded="false">%s</button>',
				esc_attr( $id ),
				esc_attr( $mod['label'] ),
				esc_attr( $mod['label'] ),
				$this->get_svg_icon( $mod['icon'] )
			);
		}
	}

	private function get_svg_icon( string $name ): string {
		$icons = [
			'shield'   => '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
			'plus'     => '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
			'user'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/></svg>',
			'grid'     => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v6M14.5 17h6"/></svg>',
			'search'   => '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-3.5-3.5"/></svg>',
			'link'     => '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
			'layers'   => '<svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
			'pipeline' => '<svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 12h4l4-5M7 12l4 1 4 4"/></svg>',
			'circle'   => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>',
		];

		$svg = $icons[ $name ] ?? $icons['circle'];
		// Inject standard attributes
		return str_replace( '<svg ', '<svg fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" ', $svg );
	}
}
