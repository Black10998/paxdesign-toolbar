<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s        = $this->settings->all();
$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 25;
$offset   = ( $page_num - 1 ) * $per_page;
$orders   = PDX_Access::get_all_orders( $per_page, $offset );
$total    = PDX_Access::count_orders();
$revenue  = PDX_Access::revenue_total();
$by_mod   = PDX_Access::revenue_by_module();
$pages    = ceil( $total / $per_page );
$currency = $s['paypal']['currency'] ?? 'USD';
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Orders & Revenue</h1>
  <p>All payment records and customer access status.</p>
</div>

<div class="pdx-stats-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="pdx-stat-card">
    <span class="pdx-stat-card__value"><?php echo number_format( $total ); ?></span>
    <span class="pdx-stat-card__label">Total Orders</span>
  </div>
  <div class="pdx-stat-card">
    <span class="pdx-stat-card__value"><?php echo esc_html( $currency ); ?> <?php echo number_format( $revenue, 2 ); ?></span>
    <span class="pdx-stat-card__label">Total Revenue</span>
  </div>
  <div class="pdx-stat-card">
    <span class="pdx-stat-card__value"><?php echo count( array_filter( $orders, fn($o) => $o['status'] === 'active' ) ); ?></span>
    <span class="pdx-stat-card__label">Active (this page)</span>
  </div>
</div>

<?php if ( ! empty( $by_mod ) ) : ?>
<div class="pdx-card" style="margin-bottom:16px">
  <div class="pdx-card__header"><h2>Revenue by Module</h2></div>
  <div class="pdx-card__body">
    <div class="pdx-bar-chart">
      <?php
      $max_rev = max( array_column( $by_mod, 'revenue' ) );
      foreach ( $by_mod as $row ) :
        $pct = $max_rev > 0 ? round( ( $row['revenue'] / $max_rev ) * 100 ) : 0;
      ?>
      <div class="pdx-bar-chart__row">
        <span class="pdx-bar-chart__label"><?php echo esc_html( $row['module_id'] ); ?></span>
        <div class="pdx-bar-chart__bar"><div class="pdx-bar-chart__fill" style="width:<?php echo $pct; ?>%"></div></div>
        <span class="pdx-bar-chart__count"><?php echo esc_html( $currency . ' ' . number_format( $row['revenue'], 2 ) ); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="pdx-card">
  <div class="pdx-card__header"><h2>Order Log</h2></div>
  <div class="pdx-card__body" style="padding:0">
    <?php if ( empty( $orders ) ) : ?>
    <div style="padding:24px;text-align:center;color:#6e7681;font:13px/1 var(--pa-font)">No orders yet.</div>
    <?php else : ?>
    <table class="pdx-table">
      <thead>
        <tr>
          <th>#</th><th>Date</th><th>Customer</th><th>Email</th>
          <th>Module</th><th>Amount</th><th>PayPal Order</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $orders as $o ) :
          $status_colors = [ 'active' => 'green', 'pending' => 'yellow', 'refunded' => 'red', 'expired' => 'mute' ];
          $sc = $status_colors[ $o['status'] ] ?? 'mute';
        ?>
        <tr>
          <td style="color:#484f58"><?php echo esc_html( $o['id'] ); ?></td>
          <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $o['created_at'] ) ) ); ?></td>
          <td><?php echo esc_html( $o['display_name'] ?: ( $o['user_login'] ?: '—' ) ); ?></td>
          <td style="font-size:11px"><?php echo esc_html( $o['email'] ?: '—' ); ?></td>
          <td><span class="pdx-badge"><?php echo esc_html( $o['module_id'] ); ?></span></td>
          <td style="font-weight:600;color:#e6edf3"><?php echo esc_html( $o['currency'] . ' ' . number_format( $o['amount'], 2 ) ); ?></td>
          <td style="font-size:10px;color:#484f58;font-family:monospace"><?php echo esc_html( substr( $o['paypal_order'] ?? '—', 0, 20 ) ); ?></td>
          <td><span class="pdx-tag pdx-tag--<?php echo esc_attr( $sc ); ?>"><?php echo esc_html( PDX_Access::status_label( $o['status'] ) ); ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ( $pages > 1 ) : ?>
    <div style="padding:14px 16px;display:flex;gap:6px;align-items:center">
      <?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
      <a href="<?php echo esc_url( add_query_arg( [ 'page' => PDX_SLUG . '-orders', 'paged' => $p ], admin_url( 'admin.php' ) ) ); ?>"
         class="pdx-btn-ghost" style="padding:5px 10px;font-size:11px<?php echo $p === $page_num ? ';border-color:rgba(63,185,80,.4);color:#3fb950' : ''; ?>">
        <?php echo $p; ?>
      </a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
