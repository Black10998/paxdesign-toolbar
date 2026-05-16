<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s        = $this->settings->all();
$modules  = $this->modules->all();
$tiers    = $s['module_tiers']  ?? [];
$prices   = $s['module_prices'] ?? [];
$currency = $s['paypal']['currency'] ?? 'USD';
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Pricing &amp; Access Tiers</h1>
  <p>Set each tool as Free, Preview (limited free trial), or Paid. Changes take effect immediately on the frontend — no page reload required.</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="pricing">

  <div class="pdx-card">
    <div class="pdx-card__header">
      <h2>Tool Pricing</h2>
      <span class="pdx-badge">Currency: <?php echo esc_html( $currency ); ?></span>
    </div>
    <div class="pdx-card__body" style="padding:0;overflow-x:auto">
      <table class="pdx-table pdx-pricing-table">
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
              <div class="pdx-pricing-tool">
                <div class="pdx-pricing-tool__icon">
                  <?php echo $this->get_svg_icon_html( $mod['icon'] ); ?>
                </div>
                <strong class="pdx-pricing-tool__name"><?php echo esc_html( $mod['label'] ); ?></strong>
              </div>
            </td>
            <td><span class="pdx-badge"><?php echo esc_html( $mod['category'] ); ?></span></td>
            <td>
              <select name="module_tiers[<?php echo esc_attr( $id ); ?>]" class="pdx-pricing-select">
                <option value="free"    <?php selected( $tier, 'free' ); ?>>Free</option>
                <option value="preview" <?php selected( $tier, 'preview' ); ?>>Preview</option>
                <option value="paid"    <?php selected( $tier, 'paid' ); ?>>Paid</option>
              </select>
            </td>
            <td>
              <input type="number"
                     name="module_prices[<?php echo esc_attr( $id ); ?>]"
                     value="<?php echo esc_attr( number_format( (float) $price, 2, '.', '' ) ); ?>"
                     min="0" step="0.01"
                     class="pdx-pricing-price-input">
            </td>
            <td class="pdx-pricing-preview">
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
      <p><strong>Free</strong> — always accessible without payment. <strong>Preview</strong> — limited free interactions, then paywall. <strong>Paid</strong> — paywall shown immediately. Set price to 0.00 to make any tier effectively free.</p>
    </div>
  </div>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Pricing</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
