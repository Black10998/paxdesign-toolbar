<?php if ( ! defined( 'ABSPATH' ) ) exit;
$current = sanitize_key( $_GET['page'] ?? PDX_SLUG );
$nav = [
	'Core' => [
		PDX_SLUG              => [ 'label' => 'General', 'icon' => 'M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z' ],
		PDX_SLUG . '-modules' => [ 'label' => 'Modules', 'icon' => 'M4 6h16M4 12h16M4 18h16' ],
		PDX_SLUG . '-ui'      => [ 'label' => 'UI & Style', 'icon' => 'M4 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5z' ],
		PDX_SLUG . '-roles'   => [ 'label' => 'Roles', 'icon' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2' ],
	],
	'Commerce' => [
		PDX_SLUG . '-pricing'  => [ 'label' => 'Pricing', 'icon' => 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z' ],
		PDX_SLUG . '-payments' => [ 'label' => 'PayPal', 'icon' => 'M7 11C7 11 6 17 10 17H15C18 17 20 15 20 12' ],
		PDX_SLUG . '-orders'   => [ 'label' => 'Orders', 'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7' ],
		PDX_SLUG . '-billing'  => [ 'label' => 'Billing', 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 0 0 3-3V8' ],
	],
	'Platform' => [
		PDX_SLUG . '-api'        => [ 'label' => 'API Keys', 'icon' => 'M15 7h3a5 5 0 0 1 0 10h-3m-6 0H6A5 5 0 0 1 6 7h3' ],
		PDX_SLUG . '-webhooks'   => [ 'label' => 'Webhooks', 'icon' => 'M13 10V3L4 14h7v7l9-11h-7z' ],
		PDX_SLUG . '-audit'      => [ 'label' => 'Audit', 'icon' => 'M9 12l2 2 4-4' ],
		PDX_SLUG . '-analytics'  => [ 'label' => 'Analytics', 'icon' => 'M18 20V10M12 20V4M6 20v-6' ],
		PDX_SLUG . '-teams'      => [ 'label' => 'Teams', 'icon' => 'M17 21v-2a4 4 0 0 0-4-4H5' ],
		PDX_SLUG . '-workers'    => [ 'label' => 'Workers', 'icon' => 'M5 12h14M12 5l7 7-7 7' ],
		PDX_SLUG . '-platform'   => [ 'label' => 'Platform', 'icon' => 'M9 19v-6a2 2 0 0 0-2-2H5' ],
		PDX_SLUG . '-dev-tokens' => [ 'label' => 'Dev Tokens', 'icon' => 'M15 7h3a5 5 0 0 1 0 10h-3' ],
		PDX_SLUG . '-cache'      => [ 'label' => 'Cache', 'icon' => 'M13 2L3 14h9l-1 8 10-12h-9l1-8z' ],
	],
	'Compliance' => [
		PDX_SLUG . '-privacy' => [ 'label' => 'Privacy', 'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z' ],
	],
];
$page_label = 'Dashboard';
foreach ( $nav as $items ) {
	foreach ( $items as $slug => $tab ) {
		if ( $current === $slug ) {
			$page_label = $tab['label'];
			break 2;
		}
	}
}
?>
<div class="pdx-admin-wrap" data-pdx-theme="dark">
  <aside class="pdx-sidebar" id="pdx-sidebar" aria-label="PaxDesign navigation">
    <div class="pdx-sidebar__brand">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <div>
        <strong>PaxDesign</strong>
        <span>v<?php echo esc_html( PDX_VERSION ); ?></span>
      </div>
    </div>
    <nav class="pdx-sidebar__nav">
      <?php foreach ( $nav as $group => $items ) : ?>
        <div class="pdx-sidebar__group">
          <div class="pdx-sidebar__group-label"><?php echo esc_html( $group ); ?></div>
          <?php foreach ( $items as $slug => $tab ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
               class="pdx-sidebar__link<?php echo $current === $slug ? ' is-active' : ''; ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="<?php echo esc_attr( $tab['icon'] ); ?>"/>
              </svg>
              <span><?php echo esc_html( $tab['label'] ); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="pdx-shell">
    <header class="pdx-topbar">
      <button type="button" class="pdx-topbar__menu" id="pdx-sidebar-toggle" aria-label="Toggle navigation" aria-expanded="false">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <h1 class="pdx-topbar__title"><?php echo esc_html( $page_label ); ?></h1>
      <div class="pdx-topbar__actions">
        <button type="button" class="pdx-topbar__theme" id="pdx-theme-toggle" aria-label="Toggle light/dark theme">Theme</button>
      </div>
    </header>
    <main class="pdx-admin-main">
