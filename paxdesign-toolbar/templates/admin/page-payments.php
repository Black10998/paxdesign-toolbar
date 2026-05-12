<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s  = $this->settings->all();
$pp = $s['paypal'] ?? [];
$mode = $pp['mode'] ?? 'sandbox';
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>PayPal Configuration</h1>
  <p>Connect your PayPal account to accept payments for premium tools.</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="payments">

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Mode</h2></div>
    <div class="pdx-card__body">
      <div class="pdx-radio-group">
        <label class="pdx-radio <?php echo $mode === 'sandbox' ? 'is-selected' : ''; ?>">
          <input type="radio" name="paypal[mode]" value="sandbox" <?php checked( $mode, 'sandbox' ); ?>>
          Sandbox (Testing)
        </label>
        <label class="pdx-radio <?php echo $mode === 'live' ? 'is-selected' : ''; ?>">
          <input type="radio" name="paypal[mode]" value="live" <?php checked( $mode, 'live' ); ?>>
          Live (Production)
        </label>
      </div>
      <p class="pdx-field-hint" style="margin-top:10px">Use Sandbox for testing. Switch to Live when ready to accept real payments.</p>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Sandbox Credentials</h2></div>
    <div class="pdx-card__body pdx-grid-2">
      <div class="pdx-field">
        <label for="pp_sb_client">Sandbox Client ID</label>
        <div class="pdx-input-group">
          <input type="password" id="pp_sb_client" name="paypal[sandbox_client_id]"
                 value="<?php echo esc_attr( $pp['sandbox_client_id'] ?? '' ); ?>"
                 placeholder="AXxx\u2026" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="pp_sb_client" aria-label="Toggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <div class="pdx-field">
        <label for="pp_sb_secret">Sandbox Secret</label>
        <div class="pdx-input-group">
          <input type="password" id="pp_sb_secret" name="paypal[sandbox_secret]"
                 value="<?php echo esc_attr( $pp['sandbox_secret'] ?? '' ); ?>"
                 placeholder="EXxx\u2026" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="pp_sb_secret" aria-label="Toggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Live Credentials</h2></div>
    <div class="pdx-card__body pdx-grid-2">
      <div class="pdx-field">
        <label for="pp_live_client">Live Client ID</label>
        <div class="pdx-input-group">
          <input type="password" id="pp_live_client" name="paypal[live_client_id]"
                 value="<?php echo esc_attr( $pp['live_client_id'] ?? '' ); ?>"
                 placeholder="AXxx\u2026" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="pp_live_client" aria-label="Toggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
      <div class="pdx-field">
        <label for="pp_live_secret">Live Secret</label>
        <div class="pdx-input-group">
          <input type="password" id="pp_live_secret" name="paypal[live_secret]"
                 value="<?php echo esc_attr( $pp['live_secret'] ?? '' ); ?>"
                 placeholder="EXxx\u2026" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="pp_live_secret" aria-label="Toggle">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Currency</h2></div>
    <div class="pdx-card__body">
      <div class="pdx-field" style="max-width:200px">
        <label for="pp_currency">Default Currency</label>
        <select id="pp_currency" name="paypal[currency]" class="pdx-field input">
          <?php foreach ( PDX_Commerce::supported_currencies() as $cur ) : ?>
          <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $pp['currency'] ?? 'USD', $cur ); ?>><?php echo esc_html( $cur ); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="pdx-card pdx-card--info">
    <div class="pdx-card__body">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
      <p>Get credentials from <a href="https://developer.paypal.com/dashboard/" target="_blank" rel="noopener" style="color:#388bfd">developer.paypal.com</a> → My Apps &amp; Credentials. Create a REST API app and copy the Client ID and Secret.</p>
    </div>
  </div>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save PayPal Settings</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
