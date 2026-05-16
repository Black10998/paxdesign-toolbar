<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="pdx-admin-wrap">
  <header class="pdx-admin-header">
    <div class="pdx-admin-header__brand">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <span>PaxDesign</span>
      <span class="pdx-admin-header__version">v<?php echo esc_html( PDX_VERSION ); ?></span>
    </div>
    <nav class="pdx-admin-nav" aria-label="Admin navigation">
      <?php
      $tabs = [
        PDX_SLUG                  => [ 'label' => 'General',   'icon' => 'M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z' ],
        PDX_SLUG . '-modules'     => [ 'label' => 'Modules',   'icon' => 'M4 6h16M4 12h16M4 18h16' ],
        PDX_SLUG . '-pricing'     => [ 'label' => 'Pricing',   'icon' => 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z' ],
        PDX_SLUG . '-payments'    => [ 'label' => 'PayPal',    'icon' => 'M7 11C7 11 6 17 10 17H15C18 17 20 15 20 12C20 9 18 7 15 7H9C6 7 5 9 5 12' ],
        PDX_SLUG . '-orders'      => [ 'label' => 'Orders',    'icon' => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2' ],
        PDX_SLUG . '-api'         => [ 'label' => 'API Keys',  'icon' => 'M15 7h3a5 5 0 0 1 0 10h-3m-6 0H6A5 5 0 0 1 6 7h3' ],
        PDX_SLUG . '-ui'          => [ 'label' => 'UI',        'icon' => 'M4 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5zM4 13a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-6zM16 13a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1v-6z' ],
        PDX_SLUG . '-billing'     => [ 'label' => 'Billing',   'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3z' ],
        PDX_SLUG . '-webhooks'    => [ 'label' => 'Webhooks',  'icon' => 'M13 10V3L4 14h7v7l9-11h-7z' ],
        PDX_SLUG . '-audit'       => [ 'label' => 'Audit',     'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' ],
        PDX_SLUG . '-privacy'     => [ 'label' => 'Privacy',   'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z' ],
        PDX_SLUG . '-roles'       => [ 'label' => 'Roles',     'icon' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 4v6m3-3h-6' ],
        PDX_SLUG . '-analytics'   => [ 'label' => 'Analytics', 'icon' => 'M18 20V10M12 20V4M6 20v-6' ],
        PDX_SLUG . '-teams'       => [ 'label' => 'Teams',     'icon' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75' ],
        PDX_SLUG . '-workers'     => [ 'label' => 'Workers',   'icon' => 'M5 12h14M12 5l7 7-7 7' ],
        PDX_SLUG . '-platform'    => [ 'label' => 'Platform',  'icon' => 'M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2zm0 0V9a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v10m-6 0a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2m0 0V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2z' ],
        PDX_SLUG . '-dev-tokens'  => [ 'label' => 'Dev Tokens', 'icon' => 'M15 7h3a5 5 0 0 1 0 10h-3m-6 0H6A5 5 0 0 1 6 7h3' ],
        PDX_SLUG . '-cache'       => [ 'label' => 'Cache',     'icon' => 'M13 2L3 14h9l-1 8 10-12h-9l1-8z' ],
      ];
      $current = sanitize_key( $_GET['page'] ?? PDX_SLUG );
      foreach ( $tabs as $slug => $tab ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
           class="pdx-admin-nav__item<?php echo $current === $slug ? ' is-active' : ''; ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="<?php echo esc_attr( $tab['icon'] ); ?>"/>
          </svg>
          <?php echo esc_html( $tab['label'] ); ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </header>
  <main class="pdx-admin-main">
