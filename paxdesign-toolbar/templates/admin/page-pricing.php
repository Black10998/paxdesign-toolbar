<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s       = $this->settings->all();
$modules = $this->modules->all();
$tiers   = $s['module_tiers']  ?? [];
$prices  = $s['module_prices'] ?? [];
$currency = $s['paypal']['currency'] ?? 'USD';
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Pricing & Access Tiers</h1>
  <p>Set each tool as Free, Preview (limited free trial), or Paid. Configure prices per tool.</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="pricing">

  <div class="pdx-card">
    <div class="pdx-card__header">
      <h2>Tool Pricing</h2>
      <span style="font:11px/1 var(--pa-font);color:var(--pa-text-lo)">Currency: <?php echo esc_html( $currency ); ?></span>
    </div>
    <div class="pdx-card__body" style="padding:0">
      <table class="pdx-table">
        <thead>
          <tr>
            <th>Tool</th>
            <th>Category</th>
            <th>Access Tier</th>
            <th>Price (<?php echo esc_html( $currency ); ?>)</th>
            <th>Preview Limit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( $modules as $id => $mod ) :
            $tier          = $tiers[ $id ]  ?? $mod['default_tier'];
            $price         = $prices[ $id ] ?? $mod['default_price'];
            $preview_lines = $mod['preview_lines'] ?? 0;
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="width:24px;height:24px;background:#161b22;border:1px solid rgba(255,255,255,.08);border-radius:5px;display:flex;align-items:center;justify-content:center;color:#6e7681">
                  <?php echo $this->get_svg_icon_html( $mod['icon'] ); ?>
                </div>
                <strong style="font-size:12px;color:#e6edf3"><?php echo esc_html( $mod['label'] ); ?></strong>
              </div>
            </td>
            <td><span class="pdx-badge"><?php echo esc_html( $mod['category'] ); ?></span></td>
            <td>
              <select name="module_tiers[<?php echo esc_attr( $id ); ?>]" class="pdx-field input" style="background:#0d1117;border:1px solid rgba(255,255,255,.08);border-radius:5px;color:#e6edf3;font:12px/1 var(--pa-font);padding:5px 8px;outline:none">
                <option value="free"    <?php selected( $tier, 'free' ); ?>>Free</option>
                <option value="preview" <?php selected( $tier, 'preview' ); ?>>Preview</option>
                <option value="paid"    <?php selected( $tier, 'paid' ); ?>>Paid</option>
              </select>
            </td>
            <td>
              <input type="number" name="module_prices[<?php echo esc_attr( $id ); ?>]"
                     value="<?php echo esc_attr( number_format( (float) $price, 2, '.', '' ) ); ?>"
                     min="0" step="0.01" style="width:80px;background:#0d1117;border:1px solid rgba(255,255,255,.08);border-radius:5px;color:#e6edf3;font:12px/1 var(--pa-font);padding:5px 8px;outline:none">
            </td>
            <td style="color:#6e7681;font-size:12px">
              <?php echo $preview_lines > 0 ? esc_html( $preview_lines ) . ' free' : '—'; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="pdx-card pdx-card--info">
    <div class="pdx-card__body">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
      <p><strong>Free</strong> — always accessible. <strong>Preview</strong> — limited free use, then paywall. <strong>Paid</strong> — paywall shown immediately. Set price to 0 to make any tier effectively free.</p>
    </div>
  </div>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Pricing</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
