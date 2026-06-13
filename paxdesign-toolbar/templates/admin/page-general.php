<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s = $this->settings->all();
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>General Settings</h1>
  <p>Core platform configuration and contact/CTA settings.</p>
</div>

<?php include __DIR__ . '/partials/updates-panel.php'; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="general">

  <section class="pdx-section">
    <header class="pdx-section__head"><h2>Contact & CTA</h2></header>
    <div class="pdx-section__body pdx-grid-2">
      <div class="pdx-field">
        <label for="contact_url">Contact Page URL</label>
        <input type="url" id="contact_url" name="contact_url"
               value="<?php echo esc_attr( $s['contact_url'] ); ?>"
               placeholder="https://yoursite.com/contact">
        <p class="pdx-field-hint">Leave blank to auto-detect a page named "contact".</p>
      </div>
      <div class="pdx-field">
        <label for="cta_primary_label">Primary CTA Label</label>
        <input type="text" id="cta_primary_label" name="cta_primary_label"
               value="<?php echo esc_attr( $s['cta_primary_label'] ); ?>"
               placeholder="Start a project">
      </div>
      <div class="pdx-field">
        <label for="cta_secondary_label">Secondary CTA Label</label>
        <input type="text" id="cta_secondary_label" name="cta_secondary_label"
               value="<?php echo esc_attr( $s['cta_secondary_label'] ); ?>"
               placeholder="Learn more">
      </div>
    </div>
  </section>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Changes</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
