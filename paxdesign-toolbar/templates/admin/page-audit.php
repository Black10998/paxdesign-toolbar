<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$limit    = 50;
$offset   = ( $page - 1 ) * $limit;
$module   = sanitize_key( $_GET['module'] ?? '' );
$severity = sanitize_key( $_GET['severity'] ?? '' );
$search   = sanitize_text_field( $_GET['search'] ?? '' );

$logs     = PDX_Audit::get_recent( $limit, $offset, $module, $severity, $search );
$total    = PDX_Audit::count( $module, $severity );
$stats    = PDX_Audit::stats_by_module();
$hourly   = PDX_Audit::stats_by_hour( 24 );
$pages    = ceil( $total / $limit );

$severity_colors = [ 'info' => 'blue', 'warn' => 'yellow', 'error' => 'red', 'critical' => 'red' ];
?>
<div class="pdx-admin-section">

  <!-- Stats row -->
  <div class="pdx-admin-stats-grid">
    <div class="pdx-admin-stat-card">
      <div class="pdx-admin-stat-num"><?php echo number_format( $total ); ?></div>
      <div class="pdx-admin-stat-label">Total Events</div>
    </div>
    <?php foreach ( $stats as $s ) : ?>
      <div class="pdx-admin-stat-card">
        <div class="pdx-admin-stat-num"><?php echo number_format( $s['total'] ); ?></div>
        <div class="pdx-admin-stat-label"><?php echo esc_html( $s['module'] ); ?></div>
        <?php if ( $s['errors'] > 0 ) : ?>
          <div class="pdx-admin-stat-sub pdx-admin-stat-sub--red"><?php echo (int) $s['errors']; ?> errors</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Activity chart -->
  <?php if ( ! empty( $hourly ) ) : ?>
  <div class="pdx-admin-card">
    <div class="pdx-admin-card__header"><h2 class="pdx-admin-card__title">Activity (24h)</h2></div>
    <div class="pdx-admin-card__body">
      <?php
      $max = max( array_column( $hourly, 'total' ) ) ?: 1;
      echo '<div class="pdx-admin-chart">';
      foreach ( $hourly as $h ) {
        $pct = round( ( $h['total'] / $max ) * 100 );
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

  <!-- Filters + log -->
  <div class="pdx-admin-card">
    <div class="pdx-admin-card__header">
      <h2 class="pdx-admin-card__title">Audit Log</h2>
      <form method="get" class="pdx-admin-filter-row">
        <input type="hidden" name="page" value="<?php echo esc_attr( PDX_SLUG . '-audit' ); ?>" />
        <input type="text" name="search" class="pdx-admin-input-sm" placeholder="Search…" value="<?php echo esc_attr( $search ); ?>" />
        <select name="module" class="pdx-admin-select-sm">
          <option value="">All modules</option>
          <?php foreach ( array_column( $stats, 'module' ) as $m ) : ?>
            <option value="<?php echo esc_attr( $m ); ?>"<?php selected( $module, $m ); ?>><?php echo esc_html( $m ); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="severity" class="pdx-admin-select-sm">
          <option value="">All severities</option>
          <?php foreach ( [ 'info', 'warn', 'error', 'critical' ] as $sev ) : ?>
            <option value="<?php echo $sev; ?>"<?php selected( $severity, $sev ); ?>><?php echo ucfirst( $sev ); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="pdx-admin-btn pdx-admin-btn--sm">Filter</button>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PDX_SLUG . '-audit' ) ); ?>" class="pdx-admin-btn pdx-admin-btn--sm">Reset</a>
      </form>
    </div>
    <div class="pdx-admin-card__body">
      <?php if ( empty( $logs ) ) : ?>
        <div class="pdx-admin-empty">No audit events found.</div>
      <?php else : ?>
        <table class="pdx-admin-table pdx-admin-table--sm">
          <thead><tr><th>Time</th><th>Module</th><th>Action</th><th>Severity</th><th>Actor</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ( $logs as $log ) :
              $color = $severity_colors[ $log['severity'] ] ?? 'gray';
            ?>
              <tr>
                <td class="pdx-admin-mono"><?php echo esc_html( $log['ts'] ); ?></td>
                <td><span class="pdx-admin-badge"><?php echo esc_html( $log['module'] ); ?></span></td>
                <td><?php echo esc_html( $log['action'] ); ?></td>
                <td><span class="pdx-admin-badge pdx-admin-badge--<?php echo $color; ?>"><?php echo esc_html( $log['severity'] ); ?></span></td>
                <td><?php echo esc_html( $log['actor_email'] ?: ( $log['actor_id'] ? 'User #' . $log['actor_id'] : 'Guest' ) ); ?></td>
                <td class="pdx-admin-mono"><?php echo esc_html( $log['actor_ip'] ); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $pages > 1 ) : ?>
          <div class="pdx-admin-pagination">
            <?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
              <a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"
                 class="pdx-admin-page-btn<?php echo $i === $page ? ' is-active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
