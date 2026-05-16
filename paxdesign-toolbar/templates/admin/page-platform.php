<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pdx-admin-wrap">
<h1 class="pdx-admin-title">Platform Stats</h1>

<?php
$audit_total  = PDX_Audit::count();
$audit_hourly = PDX_Audit::stats_by_hour( 24 );
$queue_stats  = PDX_Queue::queue_stats();
$ioc_stats    = PDX_Correlation::stats();
$cache_stats  = PDX_Cache::stats();
$rl_stats     = PDX_RateLimit::stats();
$workers      = PDX_Worker::all();
$mrr          = PDX_Billing::mrr();
?>

<div class="pdx-admin-cards">
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Audit Events (total)</div>
    <div class="pdx-admin-card-value"><?php echo number_format( $audit_total ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">IOCs Tracked</div>
    <div class="pdx-admin-card-value"><?php echo number_format( $ioc_stats['total_iocs'] ?? 0 ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Worker Nodes</div>
    <div class="pdx-admin-card-value"><?php echo count( $workers ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">MRR</div>
    <div class="pdx-admin-card-value">$<?php echo number_format( $mrr, 2 ); ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Cache Hit Rate</div>
    <div class="pdx-admin-card-value"><?php echo isset( $cache_stats['hit_rate'] ) ? round( $cache_stats['hit_rate'], 1 ) . '%' : '—'; ?></div>
  </div>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card-label">Rate Limit Blocks (24h)</div>
    <div class="pdx-admin-card-value"><?php echo (int) ( $rl_stats['blocks_24h'] ?? 0 ); ?></div>
  </div>
</div>

<h2>Queue Status</h2>
<table class="widefat striped" style="max-width:400px">
  <tbody>
    <tr><td>Queued</td><td><?php echo (int) ( $queue_stats['queued'] ?? 0 ); ?></td></tr>
    <tr><td>Running</td><td><?php echo (int) ( $queue_stats['running'] ?? 0 ); ?></td></tr>
    <tr><td>Completed</td><td><?php echo (int) ( $queue_stats['completed'] ?? 0 ); ?></td></tr>
    <tr><td>Failed</td><td><?php echo (int) ( $queue_stats['failed'] ?? 0 ); ?></td></tr>
  </tbody>
</table>

<h2 style="margin-top:24px">IOC Intelligence</h2>
<table class="widefat striped" style="max-width:500px">
  <tbody>
    <tr><td>Total IOCs</td><td><?php echo number_format( $ioc_stats['total_iocs'] ?? 0 ); ?></td></tr>
    <tr><td>Unique Sources</td><td><?php echo (int) ( $ioc_stats['unique_sources'] ?? 0 ); ?></td></tr>
    <tr><td>High Confidence (&gt;80%)</td><td><?php echo (int) ( $ioc_stats['high_confidence'] ?? 0 ); ?></td></tr>
    <tr><td>Added (last 7 days)</td><td><?php echo (int) ( $ioc_stats['recent_7d'] ?? 0 ); ?></td></tr>
  </tbody>
</table>

<h2 style="margin-top:24px">Audit Activity (last 24h)</h2>
<?php if ( ! empty( $audit_hourly ) ) : ?>
<div style="display:flex;gap:3px;align-items:flex-end;height:60px;padding:8px 0">
<?php
$max_h = max( array_column( $audit_hourly, 'count' ) ?: [1] );
for ( $h = 0; $h < 24; $h++ ) :
  $entry = array_values( array_filter( $audit_hourly, fn($r) => (int)$r['hour'] === $h ) )[0] ?? ['count' => 0];
  $pct   = $max_h > 0 ? round( ( $entry['count'] / $max_h ) * 100 ) : 0;
?>
  <div title="<?php echo $h; ?>:00 — <?php echo (int)$entry['count']; ?> events" style="flex:1;background:#6366f1;opacity:<?php echo max( 0.1, $pct / 100 ); ?>;border-radius:2px 2px 0 0;height:<?php echo max( 4, $pct ); ?>%"></div>
<?php endfor; ?>
</div>
<div style="display:flex;justify-content:space-between;font-size:10px;color:#8b949e;padding:0 0 12px">
  <span>0:00</span><span>6:00</span><span>12:00</span><span>18:00</span><span>23:00</span>
</div>
<?php endif; ?>

<h2>Cache Performance</h2>
<table class="widefat striped" style="max-width:400px">
  <tbody>
    <tr><td>Hits</td><td><?php echo (int) ( $cache_stats['hits'] ?? 0 ); ?></td></tr>
    <tr><td>Misses</td><td><?php echo (int) ( $cache_stats['misses'] ?? 0 ); ?></td></tr>
    <tr><td>Writes</td><td><?php echo (int) ( $cache_stats['writes'] ?? 0 ); ?></td></tr>
    <tr><td>Hit Rate</td><td><?php echo isset( $cache_stats['hit_rate'] ) ? round( $cache_stats['hit_rate'], 1 ) . '%' : '—'; ?></td></tr>
    <tr><td>Backend</td><td><?php echo esc_html( $cache_stats['backend'] ?? '—' ); ?></td></tr>
    <tr><td>Local Keys</td><td><?php echo (int) ( $cache_stats['local_keys'] ?? 0 ); ?></td></tr>
  </tbody>
</table>

<p style="margin-top:24px">
  <a href="<?php echo esc_url( rest_url( 'pdx/v1/platform/stats' ) ); ?>" target="_blank" class="button">View Raw JSON</a>
</p>
</div>
