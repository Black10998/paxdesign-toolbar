<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s            = $this->settings->all();
$log          = get_option( 'pdx_event_log', [] );
$analytics_on = $s['analytics_enabled'];

// Aggregate stats
$totals = [];
$daily  = [];
foreach ( $log as $event ) {
	$mod = $event['module'] ?? 'unknown';
	$totals[ $mod ] = ( $totals[ $mod ] ?? 0 ) + 1;
	$day = date( 'Y-m-d', $event['ts'] );
	$daily[ $day ] = ( $daily[ $day ] ?? 0 ) + 1;
}
arsort( $totals );
ksort( $daily );

include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Analytics</h1>
  <p>Interaction data from the dock. Enable analytics and logging in Privacy settings to collect data.</p>
</div>

<?php if ( ! $analytics_on ) : ?>
<div class="pdx-card pdx-card--info">
  <div class="pdx-card__body">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
    <p>Analytics is currently disabled. Enable it in <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PDX_SLUG . '-privacy' ) ); ?>">Privacy settings</a>.</p>
  </div>
</div>
<?php endif; ?>

<div class="pdx-stats-grid">
  <div class="pdx-stat-card">
    <span class="pdx-stat-card__value"><?php echo count( $log ); ?></span>
    <span class="pdx-stat-card__label">Total Events</span>
  </div>
  <div class="pdx-stat-card">
    <span class="pdx-stat-card__value"><?php echo count( $totals ); ?></span>
    <span class="pdx-stat-card__label">Active Modules</span>
  </div>
  <div class="pdx-stat-card">
    <span class="pdx-stat-card__value"><?php echo count( $daily ); ?></span>
    <span class="pdx-stat-card__label">Active Days</span>
  </div>
  <div class="pdx-stat-card">
    <span class="pdx-stat-card__value"><?php echo $daily ? max( array_values( $daily ) ) : 0; ?></span>
    <span class="pdx-stat-card__label">Peak Day</span>
  </div>
</div>

<?php if ( ! empty( $totals ) ) : ?>
<div class="pdx-card">
  <div class="pdx-card__header"><h2>Module Opens</h2></div>
  <div class="pdx-card__body">
    <div class="pdx-bar-chart">
      <?php
      $max = max( array_values( $totals ) );
      foreach ( $totals as $mod => $count ) :
        $pct = $max > 0 ? round( ( $count / $max ) * 100 ) : 0;
      ?>
      <div class="pdx-bar-chart__row">
        <span class="pdx-bar-chart__label"><?php echo esc_html( $mod ); ?></span>
        <div class="pdx-bar-chart__bar">
          <div class="pdx-bar-chart__fill" style="width:<?php echo $pct; ?>%"></div>
        </div>
        <span class="pdx-bar-chart__count"><?php echo esc_html( $count ); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ( ! empty( $log ) ) : ?>
<div class="pdx-card">
  <div class="pdx-card__header">
    <h2>Recent Events</h2>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
      <?php wp_nonce_field( 'pdx_clear_log', 'pdx_nonce' ); ?>
      <input type="hidden" name="action" value="pdx_clear_log">
      <button type="submit" class="pdx-btn-ghost pdx-btn-ghost--danger"
              onclick="return confirm('Clear all event logs?')">Clear log</button>
    </form>
  </div>
  <div class="pdx-card__body" style="padding:0">
    <table class="pdx-table">
      <thead>
        <tr><th>Time</th><th>Module</th><th>Action</th><?php if ( ! $s['gdpr_mode'] ) : ?><th>IP</th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php foreach ( array_reverse( array_slice( $log, -50 ) ) as $event ) : ?>
        <tr>
          <td><?php echo esc_html( date( 'Y-m-d H:i', $event['ts'] ) ); ?></td>
          <td><span class="pdx-badge"><?php echo esc_html( $event['module'] ); ?></span></td>
          <td><?php echo esc_html( $event['action'] ); ?></td>
          <?php if ( ! $s['gdpr_mode'] ) : ?>
          <td><?php echo esc_html( $event['ip'] ?? '—' ); ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
