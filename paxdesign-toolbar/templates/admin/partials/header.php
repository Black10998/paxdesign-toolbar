<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="pdx-admin-wrap">
  <header class="pdx-admin-header">
    <div class="pdx-admin-header__brand">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <span>PaxDesign</span>
      <span class="pdx-admin-header__version">v<?php echo esc_html( PDX_VERSION ); ?></span>
    </div>
    <nav class="pdx-admin-nav">
      <?php
      $tabs = [
        PDX_SLUG                => [ 'label' => 'General',    'icon' => 'M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z' ],
        PDX_SLUG . '-modules'   => [ 'label' => 'Modules',    'icon' => 'M4 6h16M4 12h16M4 18h16' ],
        PDX_SLUG . '-api'       => [ 'label' => 'API Keys',   'icon' => 'M15 7h3a5 5 0 0 1 0 10h-3m-6 0H6A5 5 0 0 1 6 7h3' ],
        PDX_SLUG . '-ui'        => [ 'label' => 'UI & Style', 'icon' => 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z' ],
        PDX_SLUG . '-privacy'   => [ 'label' => 'Privacy',    'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z' ],
        PDX_SLUG . '-roles'     => [ 'label' => 'Roles',      'icon' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 4v6m3-3h-6' ],
        PDX_SLUG . '-analytics' => [ 'label' => 'Analytics',  'icon' => 'M18 20V10M12 20V4M6 20v-6' ],
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
