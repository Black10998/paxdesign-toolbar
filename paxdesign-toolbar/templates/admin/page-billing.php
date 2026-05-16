<?php if ( ! defined( 'ABSPATH' ) ) exit;
include __DIR__ . '/partials/header.php';

$plans = PDX_Billing::plans();
$mrr   = PDX_Billing::mrr();
$dist  = PDX_Billing::plan_distribution();
?>
<div class="pdx-page-header">
  <h1>Billing &amp; Subscriptions</h1>
  <p>Revenue overview, plan quotas, and Stripe configuration.</p>
</div>

<div class="pdx-stats-grid">
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">Monthly Recurring Revenue</div>
    <div class="pdx-stat-card__value">$<?php echo number_format( $mrr, 2 ); ?></div>
  </div>
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">Total Subscribers</div>
    <div class="pdx-stat-card__value"><?php echo array_sum( $dist ); ?></div>
  </div>
  <?php foreach ( $dist as $plan_id => $count ) : ?>
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label"><?php echo esc_html( ucfirst( $plan_id ) ); ?> plan</div>
    <div class="pdx-stat-card__value"><?php echo (int) $count; ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="pdx-card pdx-spacer">
  <div class="pdx-card__header"><h2>Plans</h2></div>
  <div class="pdx-card__body pdx-table-scroll">
    <table class="pdx-table">
      <thead>
        <tr>
          <th>Plan</th><th>Monthly</th><th>Annual</th><th>Scans/day</th><th>AI/day</th><th>Team</th><th>Workers</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ( $plans as $pid => $plan ) : ?>
        <?php $q = $plan['quotas'] ?? []; ?>
        <tr>
          <td><strong><?php echo esc_html( $plan['name'] ); ?></strong></td>
          <td>$<?php echo number_format( $plan['price_month'] ?? 0, 2 ); ?></td>
          <td>$<?php echo number_format( $plan['price_year']  ?? 0, 2 ); ?></td>
          <td><?php echo ( $q['scans_per_day'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['scans_per_day'] ?? 0 ); ?></td>
          <td><?php echo ( $q['ai_calls_per_day'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['ai_calls_per_day'] ?? 0 ); ?></td>
          <td><?php echo ( $q['team_members'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['team_members'] ?? 0 ); ?></td>
          <td><?php echo ( $q['worker_nodes'] ?? 0 ) === -1 ? 'Unlimited' : (int) ( $q['worker_nodes'] ?? 0 ); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="pdx-card">
  <div class="pdx-card__header"><h2>Stripe Configuration</h2></div>
  <div class="pdx-card__body">
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
      <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
      <input type="hidden" name="action" value="pdx_save">
      <input type="hidden" name="pdx_tab" value="billing">
      <div class="pdx-field">
        <label for="stripe_secret">Stripe Secret Key</label>
        <input type="password" id="stripe_secret" name="stripe[secret_key]" class="pdx-input" value="<?php echo esc_attr( pdx_settings()->get( 'stripe.secret_key', '' ) ); ?>"/>
      </div>
      <div class="pdx-field">
        <label for="stripe_pub">Stripe Publishable Key</label>
        <input type="text" id="stripe_pub" name="stripe[pub_key]" class="pdx-input" value="<?php echo esc_attr( pdx_settings()->get( 'stripe.pub_key', '' ) ); ?>"/>
      </div>
      <div class="pdx-field">
        <label for="stripe_wh">Stripe Webhook Secret</label>
        <input type="password" id="stripe_wh" name="stripe[webhook_secret]" class="pdx-input" value="<?php echo esc_attr( pdx_settings()->get( 'stripe.webhook_secret', '' ) ); ?>"/>
      </div>
      <div class="pdx-field">
        <label for="stripe_mode">Mode</label>
        <select id="stripe_mode" name="stripe[mode]" class="pdx-input">
          <option value="test" <?php selected( pdx_settings()->get( 'stripe.mode', 'test' ), 'test' ); ?>>Test</option>
          <option value="live" <?php selected( pdx_settings()->get( 'stripe.mode', 'test' ), 'live' ); ?>>Live</option>
        </select>
      </div>
      <button type="submit" class="pdx-btn-primary">Save Stripe Settings</button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
