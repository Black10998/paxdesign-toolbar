<?php
/**
 * Setup wizard — runs on first activation, guides the user through
 * creating required pages and configuring the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Setup {

	/** Option key that tracks wizard state */
	const OPT = 'pdx_setup_state';

	public function __construct() {
		add_action( 'admin_notices',          [ $this, 'maybe_show_wizard' ] );
		add_action( 'admin_post_pdx_setup',   [ $this, 'handle_setup' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_wizard_css' ] );
	}

	/** Called on plugin activation */
	public static function on_activate(): void {
		if ( ! get_option( self::OPT ) ) {
			update_option( self::OPT, [
				'step'           => 'welcome',
				'contact_page'   => 0,
				'pages_created'  => false,
				'dismissed'      => false,
			] );
		}
	}

	private function state(): array {
		return wp_parse_args( get_option( self::OPT, [] ), [
			'step'          => 'welcome',
			'contact_page'  => 0,
			'pages_created' => false,
			'dismissed'     => false,
		] );
	}

	public function maybe_show_wizard(): void {
		$state = $this->state();
		if ( $state['dismissed'] ) return;
		if ( ! current_user_can( PDX_CAP ) ) return;

		/* Don't show on the plugin's own settings pages */
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( strpos( $page, PDX_SLUG ) !== false ) return;

		$step = $state['step'];
		if ( $step === 'done' ) return;

		$this->render_wizard( $step, $state );
	}

	private function render_wizard( string $step, array $state ): void {
		$action_url = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="pdx-wizard-notice notice">
			<div class="pdx-wizard">
				<div class="pdx-wizard__brand">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					<strong>PaxDesign Utility Dock</strong>
					<span class="pdx-wizard__version">v<?php echo esc_html( PDX_VERSION ); ?></span>
				</div>

				<?php if ( $step === 'welcome' ) : ?>
				<div class="pdx-wizard__body">
					<h2>Plugin activated — quick setup</h2>
					<p>The dock is already live on your frontend. Complete these optional steps to get the most out of it.</p>
					<div class="pdx-wizard__steps">
						<div class="pdx-wizard__step <?php echo $state['contact_page'] ? 'is-done' : ''; ?>">
							<span class="pdx-wizard__step-num">1</span>
							<div>
								<strong>Contact page</strong>
								<span>Used for CTA buttons in every service panel.</span>
							</div>
							<?php if ( $state['contact_page'] ) : ?>
							<span class="pdx-wizard__check">&#10003;</span>
							<?php endif; ?>
						</div>
						<div class="pdx-wizard__step <?php echo $state['pages_created'] ? 'is-done' : ''; ?>">
							<span class="pdx-wizard__step-num">2</span>
							<div>
								<strong>Required pages</strong>
								<span>Creates a /contact page if one doesn't exist.</span>
							</div>
							<?php if ( $state['pages_created'] ) : ?>
							<span class="pdx-wizard__check">&#10003;</span>
							<?php endif; ?>
						</div>
						<div class="pdx-wizard__step">
							<span class="pdx-wizard__step-num">3</span>
							<div>
								<strong>Configure modules</strong>
								<span>Enable or disable individual dock tools.</span>
							</div>
						</div>
					</div>
					<div class="pdx-wizard__actions">
						<form method="post" action="<?php echo $action_url; ?>" style="display:inline">
							<?php wp_nonce_field( 'pdx_setup', 'pdx_nonce' ); ?>
							<input type="hidden" name="action"     value="pdx_setup">
							<input type="hidden" name="pdx_action" value="create_pages">
							<button type="submit" class="pdx-wbtn pdx-wbtn--primary">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
								Create Required Pages
							</button>
						</form>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PDX_SLUG ) ); ?>" class="pdx-wbtn pdx-wbtn--secondary">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
							Open Settings
						</a>
						<form method="post" action="<?php echo $action_url; ?>" style="display:inline">
							<?php wp_nonce_field( 'pdx_setup', 'pdx_nonce' ); ?>
							<input type="hidden" name="action"     value="pdx_setup">
							<input type="hidden" name="pdx_action" value="dismiss">
							<button type="submit" class="pdx-wbtn pdx-wbtn--ghost">Dismiss</button>
						</form>
					</div>
				</div>

				<?php elseif ( $step === 'pages_created' ) : ?>
				<div class="pdx-wizard__body">
					<h2>&#10003; Pages created successfully</h2>
					<?php
					$cp = $state['contact_page'] ? get_permalink( $state['contact_page'] ) : '';
					if ( $cp ) : ?>
					<p>Contact page created at <a href="<?php echo esc_url( $cp ); ?>" target="_blank"><?php echo esc_html( $cp ); ?></a> and set as the CTA destination.</p>
					<?php else : ?>
					<p>A contact page already existed — it has been set as the CTA destination automatically.</p>
					<?php endif; ?>
					<div class="pdx-wizard__actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PDX_SLUG . '-modules' ) ); ?>" class="pdx-wbtn pdx-wbtn--primary">
							Configure Modules
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PDX_SLUG ) ); ?>" class="pdx-wbtn pdx-wbtn--secondary">
							Open Settings
						</a>
						<form method="post" action="<?php echo $action_url; ?>" style="display:inline">
							<?php wp_nonce_field( 'pdx_setup', 'pdx_nonce' ); ?>
							<input type="hidden" name="action"     value="pdx_setup">
							<input type="hidden" name="pdx_action" value="dismiss">
							<button type="submit" class="pdx-wbtn pdx-wbtn--ghost">Dismiss</button>
						</form>
					</div>
				</div>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	public function handle_setup(): void {
		if ( ! current_user_can( PDX_CAP ) ) wp_die( 'Unauthorized', 403 );
		check_admin_referer( 'pdx_setup', 'pdx_nonce' );

		$pdx_action = sanitize_key( $_POST['pdx_action'] ?? '' );
		$state      = $this->state();

		switch ( $pdx_action ) {

			case 'create_pages':
				$contact_id = $this->ensure_contact_page();
				$state['contact_page']  = $contact_id;
				$state['pages_created'] = true;
				$state['step']          = 'pages_created';

				/* Save contact URL into plugin settings */
				$settings = new PDX_Settings();
				$settings->save( [ 'contact_url' => get_permalink( $contact_id ) ] );

				update_option( self::OPT, $state );
				wp_safe_redirect( admin_url( '?pdx_setup=1' ) );
				exit;

			case 'dismiss':
				$state['dismissed'] = true;
				$state['step']      = 'done';
				update_option( self::OPT, $state );
				wp_safe_redirect( wp_get_referer() ?: admin_url() );
				exit;
		}

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Find or create a /contact page. Returns the page ID.
	 */
	private function ensure_contact_page(): int {
		/* Check by slug */
		$existing = get_page_by_path( 'contact' );
		if ( $existing ) return $existing->ID;

		/* Check by title search */
		$pages = get_pages( [ 'search' => 'contact', 'number' => 1 ] );
		if ( ! empty( $pages ) ) return $pages[0]->ID;

		/* Create it */
		$id = wp_insert_post( [
			'post_title'   => 'Contact',
			'post_name'    => 'contact',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:paragraph --><p>Get in touch with us using the form below or reach out directly.</p><!-- /wp:paragraph -->',
			'meta_input'   => [ '_pdx_created' => '1' ],
		] );

		return is_wp_error( $id ) ? 0 : $id;
	}

	public function enqueue_wizard_css( string $hook ): void {
		/* Only on admin pages that show notices */
		if ( strpos( $hook, 'pdx' ) !== false ) return;
		$state = $this->state();
		if ( $state['dismissed'] || $state['step'] === 'done' ) return;
		?>
		<style>
		.pdx-wizard-notice.notice { padding: 0; border-left: 3px solid #3fb950; background: #0d1117; }
		.pdx-wizard { padding: 20px 24px; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
		.pdx-wizard__brand { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
		.pdx-wizard__brand svg { width: 16px; height: 16px; stroke: #3fb950; }
		.pdx-wizard__brand strong { font-size: 13px; color: #e6edf3; }
		.pdx-wizard__version { font-size: 11px; color: #6e7681; background: #161b22; border: 1px solid rgba(255,255,255,.08); padding: 2px 7px; border-radius: 20px; }
		.pdx-wizard__body h2 { font-size: 15px; font-weight: 600; color: #e6edf3; margin: 0 0 6px; }
		.pdx-wizard__body > p { font-size: 13px; color: #8b949e; margin: 0 0 18px; }
		.pdx-wizard__steps { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
		.pdx-wizard__step { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: #161b22; border: 1px solid rgba(255,255,255,.08); border-radius: 8px; }
		.pdx-wizard__step.is-done { border-color: rgba(63,185,80,.3); }
		.pdx-wizard__step-num { width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: #8b949e; flex-shrink: 0; }
		.pdx-wizard__step.is-done .pdx-wizard__step-num { background: rgba(63,185,80,.15); border-color: rgba(63,185,80,.3); color: #3fb950; }
		.pdx-wizard__step div { flex: 1; }
		.pdx-wizard__step strong { display: block; font-size: 12px; color: #e6edf3; margin-bottom: 2px; }
		.pdx-wizard__step span  { font-size: 11px; color: #6e7681; }
		.pdx-wizard__check { color: #3fb950; font-size: 14px; }
		.pdx-wizard__actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
		.pdx-wbtn { display: inline-flex; align-items: center; gap: 6px; font: 500 12px/1 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; padding: 8px 14px; border-radius: 6px; text-decoration: none; cursor: pointer; border: 1px solid transparent; transition: background .15s; }
		.pdx-wbtn svg { width: 12px; height: 12px; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
		.pdx-wbtn--primary   { background: #238636; border-color: rgba(46,160,67,.4); color: #fff; }
		.pdx-wbtn--primary:hover { background: #2ea043; color: #fff; }
		.pdx-wbtn--secondary { background: #161b22; border-color: rgba(255,255,255,.1); color: #8b949e; }
		.pdx-wbtn--secondary:hover { color: #e6edf3; }
		.pdx-wbtn--ghost     { background: transparent; border-color: transparent; color: #6e7681; }
		.pdx-wbtn--ghost:hover { color: #8b949e; }
		</style>
		<?php
	}
}
