<?php if ( ! defined( 'ABSPATH' ) ) exit;
include __DIR__ . '/partials/header.php';

$audit_total  = PDX_Audit::count();
$audit_hourly = PDX_Audit::stats_by_hour( 24 );
$queue_stats  = PDX_Queue::queue_stats();
$ioc_stats    = PDX_Correlation::stats();
$cache_stats  = PDX_Cache::stats();
$rl_stats     = PDX_RateLimit::stats();
$workers      = PDX_Worker::all();
$mrr          = PDX_Billing::mrr();
?>
<div class="pdx-page-header">
  <h1>Platform Stats</h1>
  <p>Infrastructure health, queue throughput, and cache performance.</p>
</div>

<div class="pdx-stats-grid">
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">Audit Events (total)</div>
    <div class="pdx-stat-card__value"><?php echo number_format( $audit_total ); ?></div>
  </div>
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">IOCs Tracked</div>
    <div class="pdx-stat-card__value"><?php echo number_format( $ioc_stats['total_iocs'] ?? 0 ); ?></div>
  </div>
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">Worker Nodes</div>
    <div class="pdx-stat-card__value"><?php echo count( $workers ); ?></div>
  </div>
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">MRR</div>
    <div class="pdx-stat-card__value">$<?php echo number_format( $mrr, 2 ); ?></div>
  </div>
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">Cache Hit Rate</div>
    <div class="pdx-stat-card__value"><?php echo isset( $cache_stats['hit_rate'] ) ? round( $cache_stats['hit_rate'], 1 ) . '%' : '—'; ?></div>
  </div>
  <div class="pdx-stat-card">
    <div class="pdx-stat-card__label">Rate Limit Blocks (24h)</div>
    <div class="pdx-stat-card__value"><?php echo (int) ( $rl_stats['blocks_24h'] ?? 0 ); ?></div>
  </div>
</div>

<div class="pdx-card pdx-spacer">
  <div class="pdx-card__header"><h2>Queue Status</h2></div>
  <div class="pdx-card__body">
    <table class="pdx-table" style="max-width:400px">
      <tbody>
        <tr><td>Queued</td><td><?php echo (int) ( $queue_stats['queued'] ?? 0 ); ?></td></tr>
        <tr><td>Running</td><td><?php echo (int) ( $queue_stats['running'] ?? 0 ); ?></td></tr>
        <tr><td>Completed</td><td><?php echo (int) ( $queue_stats['completed'] ?? 0 ); ?></td></tr>
        <tr><td>Failed</td><td><?php echo (int) ( $queue_stats['failed'] ?? 0 ); ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="pdx-card pdx-spacer">
  <div class="pdx-card__header"><h2>IOC Intelligence</h2></div>
  <div class="pdx-card__body">
    <table class="pdx-table" style="max-width:500px">
      <tbody>
        <tr><td>Total IOCs</td><td><?php echo number_format( $ioc_stats['total_iocs'] ?? 0 ); ?></td></tr>
        <tr><td>Unique Sources</td><td><?php echo (int) ( $ioc_stats['unique_sources'] ?? 0 ); ?></td></tr>
        <tr><td>High Confidence (&gt;80%)</td><td><?php echo (int) ( $ioc_stats['high_confidence'] ?? 0 ); ?></td></tr>
        <tr><td>Added (last 7 days)</td><td><?php echo (int) ( $ioc_stats['recent_7d'] ?? 0 ); ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php if ( ! empty( $audit_hourly ) ) : ?>
<div class="pdx-card pdx-spacer">
  <div class="pdx-card__header"><h2>Audit Activity (24h)</h2></div>
  <div class="pdx-card__body">
    <?php
    $max = max( array_column( $audit_hourly, 'total' ) ) ?: 1;
    echo '<div class="pdx-admin-chart">';
    foreach ( $audit_hourly as $h ) {
      $pct  = round( ( $h['total'] / $max ) * 100 );
      $hour = substr( $h['hour'], 11, 5 );
      echo '<div class="pdx-admin-chart-bar" title="' . esc_attr( $h['hour'] . ': ' . $h['total'] ) . '">';
      echo '<div class="pdx-admin-chart-fill" style="height:' . $pct . '%"></div>';
      echo '<div class="pdx-admin-chart-label">' . esc_html( $hour ) . '</div>';
      echo '</div>';
    }
    echo '</div>';
    ?>
  </div>
</div>
<?php endif; ?>

<div class="pdx-card pdx-spacer">
  <div class="pdx-card__header"><h2>Cache Performance</h2></div>
  <div class="pdx-card__body">
    <table class="pdx-table" style="max-width:400px">
      <tbody>
        <tr><td>Hits</td><td><?php echo (int) ( $cache_stats['hits'] ?? 0 ); ?></td></tr>
        <tr><td>Misses</td><td><?php echo (int) ( $cache_stats['misses'] ?? 0 ); ?></td></tr>
        <tr><td>Writes</td><td><?php echo (int) ( $cache_stats['writes'] ?? 0 ); ?></td></tr>
        <tr><td>Hit Rate</td><td><?php echo isset( $cache_stats['hit_rate'] ) ? round( $cache_stats['hit_rate'], 1 ) . '%' : '—'; ?></td></tr>
        <tr><td>Backend</td><td><?php echo esc_html( $cache_stats['backend'] ?? '—' ); ?></td></tr>
        <tr><td>Local Keys</td><td><?php echo (int) ( $cache_stats['local_keys'] ?? 0 ); ?></td></tr>
      </tbody>
    </table>
    <p class="pdx-spacer">
      <?php if ( current_user_can( PDX_CAP ) ) : ?>
        <button type="button" class="pdx-btn-ghost" id="pdx-platform-stats-json" data-endpoint="/platform/stats"><?php esc_html_e( 'View Raw JSON', 'paxdesign-toolbar' ); ?></button>
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if ( current_user_can( PDX_CAP ) ) : ?>
<div class="pdx-card pdx-spacer" id="pdx-integration-audit-panel">
  <div class="pdx-card__header"><h2>Live Integration Audit</h2></div>
  <div class="pdx-card__body">
    <p>Probes all configured intelligence providers against real-world targets (RDAP, DNS, GeoIP, OTX, URLhaus, SSL Labs, VirusTotal, Shodan, Hunter, NVD, AbuseIPDB, URL forensics).</p>
    <p class="pdx-spacer">
      <button type="button" class="pdx-btn-primary" id="pdx-run-integration-audit"><?php esc_html_e( 'Run Live Audit', 'paxdesign-toolbar' ); ?></button>
    </p>
    <div id="pdx-integration-audit-error" class="notice notice-error pdx-notice-inline" hidden role="alert"></div>
    <div id="pdx-integration-audit-summary" class="pdx-audit-summary" hidden></div>
    <pre id="pdx-integration-audit-output" class="pdx-audit-json" hidden></pre>
    <p class="pdx-field-hint">CLI: <code>wp eval-file scripts/run-platform-audit.php</code> from the plugin repository root on a WordPress install with API keys configured.</p>
  </div>
</div>
<?php else : ?>
<div class="pdx-card pdx-spacer">
  <div class="pdx-card__header"><h2>Live Integration Audit</h2></div>
  <div class="pdx-card__body">
    <div class="notice notice-warning pdx-notice-inline">
      <?php
      printf(
        esc_html__( 'Integration audits require the "%s" capability. Contact a site administrator.', 'paxdesign-toolbar' ),
        esc_html( PDX_CAP )
      );
      ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
