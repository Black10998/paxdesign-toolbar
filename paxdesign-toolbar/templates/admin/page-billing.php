<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pdx-admin-wrap">
<h1 class="pdx-admin-title">Billing &amp; Subscriptions</h1>

<?php
$plans = PDX_Billing::plans();
$mrr   = PDX_Billing::mrr();
$dist  = PDX_Billing::plan_distribution();
?>

<div class="pdx-admin-cards">
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Monthly Recurring Revenue</div>
    <div class="pdx-admin-card-value">$<?php echo number_format( $mrr, 2 ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Total Subscribers</div>
    <div class="pdx-admin-card-value"><?php echo array_sum( $dist ); ?></div>
  </div>
  <?php foreach ( $dist as $plan_id => $count ) : ?>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label"><?php echo esc_html( ucfirst( $plan_id ) ); ?> plan</div>
    <div class="pdx-admin-card-value"><?php echo (int) $count; ?></div>
  </div>
  <?php endforeach; ?>
</div>

<h2>Plans</h2>
<table class="widefat striped">
  <thead>
    <tr>
      <th>Plan</th><th>Monthly Price</th><th>Annual Price</th><th>Scans/day</th><th>AI Calls/day</th><th>Team Members</th><th>Workers</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ( $plans as $pid => $plan ) : ?>
    <tr>
      <td><strong><?php echo esc_html( $plan['name'] ); ?></strong></td>
      <td>$<?php echo number_format( $plan['price_month'] ?? 0, 2 ); ?></td>
      <td>$<?php echo number_format( $plan['price_year']  ?? 0, 2 ); ?></td>
      <td><?php $q = $plan['quotas'] ?? []; echo ( $q['scans_per_day'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['scans_per_day'] ?? 0 ); ?></td>
      <td><?php echo ( $q['ai_calls_per_day'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['ai_calls_per_day'] ?? 0 ); ?></td>
      <td><?php echo ( $q['team_members'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['team_members'] ?? 0 ); ?></td>
      <td><?php echo ( $q['worker_nodes'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['worker_nodes'] ?? 0 ); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2 style="margin-top:24px">Stripe Configuration</h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save_settings">
  <input type="hidden" name="pdx_tab" value="billing">
  <table class="form-table">
    <tr>
      <th>Stripe Secret Key</th>
      <td><input type="password" name="stripe[secret_key]" class="regular-text" value="<?php echo esc_attr( pdx_settings()->get( 'stripe.secret_key', '' ) ); ?>"/></td>
    </tr>
    <tr>
      <th>Stripe Publishable Key</th>
      <td><input type="text" name="stripe[pub_key]" class="regular-text" value="<?php echo esc_attr( pdx_settings()->get( 'stripe.pub_key', '' ) ); ?>"/></td>
    </tr>
    <tr>
      <th>Stripe Webhook Secret</th>
      <td><input type="password" name="stripe[webhook_secret]" class="regular-text" value="<?php echo esc_attr( pdx_settings()->get( 'stripe.webhook_secret', '' ) ); ?>"/></td>
    </tr>
    <tr>
      <th>Mode</th>
      <td>
        <select name="stripe[mode]">
          <option value="test" <?php selected( pdx_settings()->get( 'stripe.mode', 'test' ), 'test' ); ?>>Test</option>
          <option value="live" <?php selected( pdx_settings()->get( 'stripe.mode', 'test' ), 'live' ); ?>>Live</option>
        </select>
      </td>
    </tr>
  </table>
  <?php submit_button( 'Save Stripe Settings' ); ?>
</form>
</div>
