<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pdx-admin-wrap">
<h1 class="pdx-admin-title">Worker Nodes</h1>

<?php
PDX_Worker::check_heartbeats();
$workers = PDX_Worker::all();
$stats   = PDX_Queue::queue_stats();
?>

<div class="pdx-admin-cards">
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Total Workers</div>
    <div class="pdx-admin-card-value"><?php echo count( $workers ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Online</div>
    <div class="pdx-admin-card-value" style="color:#10b981"><?php echo count( array_filter( $workers, fn($w) => $w['status'] === 'online' ) ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Queue: Running</div>
    <div class="pdx-admin-card-value"><?php echo (int) ( $stats['running'] ?? 0 ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Queue: Pending</div>
    <div class="pdx-admin-card-value"><?php echo (int) ( $stats['queued'] ?? 0 ); ?></div>
  </div>
</div>

<?php if ( empty( $workers ) ) : ?>
  <div class="notice notice-info"><p>No worker nodes registered. Workers register via <code>POST /wp-json/pdx/v1/workers/register</code> with a label, endpoint URL, and capabilities array.</p></div>
<?php else : ?>
<table class="widefat striped">
  <thead>
    <tr><th>Worker ID</th><th>Label</th><th>Status</th><th>Capabilities</th><th>Jobs Done</th><th>Last Heartbeat</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach ( $workers as $w ) :
    $last_hb = $w['last_heartbeat'] ?? 0;
    $hb_ago  = $last_hb ? human_time_diff( $last_hb ) . ' ago' : 'Never';
    $status_color = $w['status'] === 'online' ? '#10b981' : ( $w['status'] === 'busy' ? '#f59e0b' : '#6e7681' );
  ?>
    <tr>
      <td><code><?php echo esc_html( $w['worker_id'] ); ?></code></td>
      <td><?php echo esc_html( $w['label'] ?? '' ); ?></td>
      <td><span style="color:<?php echo $status_color; ?>;font-weight:600"><?php echo esc_html( $w['status'] ?? 'unknown' ); ?></span></td>
      <td><?php echo esc_html( implode( ', ', $w['capabilities'] ?? [] ) ); ?></td>
      <td><?php echo (int) ( $w['jobs_completed'] ?? 0 ); ?></td>
      <td><?php echo esc_html( $hb_ago ); ?></td>
      <td>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pdx_deregister_worker&worker_id=' . urlencode( $w['worker_id'] ) ), 'pdx_deregister_worker' ) ); ?>" class="button button-small" onclick="return confirm('Deregister this worker?')">Deregister</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2 style="margin-top:24px">Browser Profiles</h2>
<?php $profiles = PDX_Worker::browser_profiles(); ?>
<table class="widefat striped">
  <thead><tr><th>Profile ID</th><th>Name</th><th>User Agent</th><th>Viewport</th></tr></thead>
  <tbody>
  <?php foreach ( $profiles as $p ) : ?>
    <tr>
      <td><code><?php echo esc_html( $p['id'] ); ?></code></td>
      <td><?php echo esc_html( $p['name'] ); ?></td>
      <td><small><?php echo esc_html( substr( $p['user_agent'] ?? '', 0, 60 ) ); ?>…</small></td>
      <td><?php echo esc_html( ( $p['viewport']['width'] ?? '' ) . 'x' . ( $p['viewport']['height'] ?? '' ) ); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2 style="margin-top:24px">Queue Stats</h2>
<table class="widefat striped">
  <thead><tr><th>Status</th><th>Count</th></tr></thead>
  <tbody>
    <tr><td>Queued</td><td><?php echo (int) ( $stats['queued'] ?? 0 ); ?></td></tr>
    <tr><td>Running</td><td><?php echo (int) ( $stats['running'] ?? 0 ); ?></td></tr>
    <tr><td>Completed</td><td><?php echo (int) ( $stats['completed'] ?? 0 ); ?></td></tr>
    <tr><td>Failed</td><td><?php echo (int) ( $stats['failed'] ?? 0 ); ?></td></tr>
    <tr><td>Expired</td><td><?php echo (int) ( $stats['expired'] ?? 0 ); ?></td></tr>
  </tbody>
</table>
</div>
