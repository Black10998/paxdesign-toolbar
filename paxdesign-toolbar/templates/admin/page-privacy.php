<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s = $this->settings->all();
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Privacy</h1>
  <p>Data collection, GDPR compliance, and retention controls.</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="privacy">

  <section class="pdx-section">
    <header class="pdx-section__head"><h2>Analytics</h2></header>
    <div class="pdx-section__body">
      <div class="pdx-settings-stack">
        <?php
        $name        = 'analytics_enabled';
        $label       = 'Enable analytics';
        $description = 'Tracks which modules are opened. No personal data is collected unless logging is also enabled.';
        $checked     = $s['analytics_enabled'];
        include __DIR__ . '/partials/settings-toggle.php';

        $name        = 'log_interactions';
        $label       = 'Log interaction events';
        $description = 'Stores module open/close events in the database. Requires analytics to be enabled.';
        $checked     = $s['log_interactions'];
        include __DIR__ . '/partials/settings-toggle.php';
        ?>
      </div>
    </div>
  </section>

  <section class="pdx-section">
    <header class="pdx-section__head"><h2>GDPR</h2></header>
    <div class="pdx-section__body">
      <div class="pdx-settings-stack" style="margin-bottom:var(--pdx-space-4)">
        <?php
        $name        = 'gdpr_mode';
        $label       = 'GDPR mode';
        $description = 'Strips IP addresses from all logged events.';
        $checked     = $s['gdpr_mode'];
        include __DIR__ . '/partials/settings-toggle.php';
        ?>
      </div>
      <div class="pdx-field">
        <label for="data_retention_days">Data Retention (days)</label>
        <input type="number" id="data_retention_days" name="data_retention_days"
               value="<?php echo esc_attr( $s['data_retention_days'] ); ?>"
               min="1" max="365">
        <p class="pdx-field-hint">Events older than this are automatically purged.</p>
      </div>
    </div>
  </section>

  <section class="pdx-section pdx-card--warn">
    <div class="pdx-section__body">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <p>If you collect any user data, ensure your site's privacy policy reflects this. The Trust Check tool makes outbound requests to RDAP, SSL Labs, and Google Safe Browsing — no user data is sent to these services.</p>
    </div>
  </section>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Changes</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
