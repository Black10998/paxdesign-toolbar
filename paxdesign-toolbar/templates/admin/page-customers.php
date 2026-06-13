<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$search      = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
$page_num    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page    = 25;
$offset      = ( $page_num - 1 ) * $per_page;
$customer_id = (int) ( $_GET['customer_id'] ?? 0 );
$customers   = PDX_Customers::list_customers( $search, $per_page, $offset );
$total       = PDX_Customers::count_customers( $search );
$pages       = (int) ceil( $total / $per_page );
$detail      = $customer_id ? PDX_Customers::customer_detail( $customer_id ) : [];
$all_modules = $this->modules->all();

include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>PAXDesign Customer Accounts</h1>
  <p>Manage customer profiles, verification, purchases, and PaxDesign module access — without granting WordPress administrator roles.</p>
</div>

<form method="get" class="pdx-card" style="margin-bottom:16px;padding:16px">
  <input type="hidden" name="page" value="<?php echo esc_attr( PDX_SLUG . '-customers' ); ?>" />
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name or email…" class="pdx-input" style="min-width:240px;flex:1" />
    <button type="submit" class="pdx-btn-primary">Search</button>
    <?php if ( $search ) : ?>
      <a class="pdx-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PDX_SLUG . '-customers' ) ); ?>">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if ( ! empty( $detail ) ) : ?>
<div class="pdx-card" style="margin-bottom:16px">
  <div class="pdx-card__header"><h2><?php echo esc_html( $detail['display_name'] ); ?></h2></div>
  <div class="pdx-card__body">
    <div class="pdx-stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
      <div class="pdx-stat-card"><span class="pdx-stat-card__label">Email</span><span class="pdx-stat-card__value" style="font-size:13px"><?php echo esc_html( $detail['email'] ); ?></span></div>
      <div class="pdx-stat-card"><span class="pdx-stat-card__label">Email Status</span><span class="pdx-stat-card__value" style="font-size:13px"><?php echo $detail['verified'] ? 'Verified' : 'Not Verified'; ?></span></div>
      <div class="pdx-stat-card"><span class="pdx-stat-card__label">Account</span><span class="pdx-stat-card__value" style="font-size:13px"><?php echo esc_html( ucfirst( $detail['account_status'] ) ); ?></span></div>
      <div class="pdx-stat-card"><span class="pdx-stat-card__label">Payment</span><span class="pdx-stat-card__value" style="font-size:13px"><?php echo esc_html( $detail['payment_summary']['label'] ?? 'Free' ); ?></span></div>
    </div>
    <p style="font-size:12px;color:#8b949e;margin:0 0 12px">Registered: <?php echo esc_html( $detail['registered'] ); ?> · Last login: <?php echo esc_html( $detail['last_login'] ?: '—' ); ?></p>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
      <?php foreach ( [ 'activate' => 'Activate', 'suspend' => 'Suspend', 'resend_verification' => 'Resend Verification' ] as $act => $label ) : ?>
      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'pdx_customer_action', 'pdx_nonce' ); ?>
        <input type="hidden" name="action" value="pdx_customer_action" />
        <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $customer_id ); ?>" />
        <input type="hidden" name="customer_action" value="<?php echo esc_attr( $act ); ?>" />
        <button type="submit" class="pdx-btn-ghost"><?php echo esc_html( $label ); ?></button>
      </form>
      <?php endforeach; ?>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px">
      <?php wp_nonce_field( 'pdx_customer_action', 'pdx_nonce' ); ?>
      <input type="hidden" name="action" value="pdx_customer_action" />
      <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $customer_id ); ?>" />
      <input type="hidden" name="customer_action" value="save_notes" />
      <label class="pdx-label">Internal notes</label>
      <textarea name="admin_notes" class="pdx-input" rows="3" style="width:100%;margin:6px 0 8px"><?php echo esc_textarea( $detail['notes'] ?? '' ); ?></textarea>
      <button type="submit" class="pdx-btn-primary">Save notes</button>
    </form>

    <div class="pdx-card" style="margin-bottom:16px">
      <div class="pdx-card__header"><h3>Grant / Revoke Module Access</h3></div>
      <div class="pdx-card__body">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <?php wp_nonce_field( 'pdx_customer_action', 'pdx_nonce' ); ?>
          <input type="hidden" name="action" value="pdx_customer_action" />
          <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $customer_id ); ?>" />
          <div>
            <label class="pdx-label">Module</label>
            <select name="module_id" class="pdx-select">
              <?php foreach ( $all_modules as $mid => $mod ) : ?>
                <option value="<?php echo esc_attr( $mid ); ?>"><?php echo esc_html( $mod['label'] ?? $mid ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="pdx-label">Days (0 = lifetime)</label>
            <input type="number" name="grant_days" value="0" min="0" class="pdx-input" style="width:90px" />
          </div>
          <button type="submit" name="customer_action" value="grant_module" class="pdx-btn-primary">Grant Access</button>
          <button type="submit" name="customer_action" value="revoke_module" class="pdx-btn-ghost">Revoke Access</button>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:flex-end;margin-top:12px">
          <?php wp_nonce_field( 'pdx_customer_action', 'pdx_nonce' ); ?>
          <input type="hidden" name="action" value="pdx_customer_action" />
          <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $customer_id ); ?>" />
          <input type="hidden" name="customer_action" value="extend_subscription" />
          <label class="pdx-label">Extend subscription (days)</label>
          <input type="number" name="extend_days" value="30" min="1" class="pdx-input" style="width:90px" />
          <button type="submit" class="pdx-btn-ghost">Extend Subscription</button>
        </form>
      </div>
    </div>

    <?php if ( ! empty( $detail['orders'] ) ) : ?>
    <div class="pdx-card">
      <div class="pdx-card__header"><h3>Orders & Invoices</h3></div>
      <div class="pdx-card__body" style="padding:0">
        <table class="pdx-table">
          <thead><tr><th>Order</th><th>Date</th><th>Product</th><th>Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ( $detail['orders'] as $o ) : ?>
            <tr>
              <td><?php echo esc_html( $o['order_id'] ); ?></td>
              <td><?php echo esc_html( $o['paid_at'] ); ?></td>
              <td><?php echo esc_html( $o['product'] ); ?></td>
              <td><?php echo esc_html( $o['currency'] . ' ' . number_format( (float) $o['amount'], 2 ) ); ?></td>
              <td><?php echo esc_html( $o['payment_status'] ); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="pdx-card">
  <div class="pdx-card__header"><h2>Customers (<?php echo number_format( $total ); ?>)</h2></div>
  <div class="pdx-card__body" style="padding:0">
    <?php if ( empty( $customers ) ) : ?>
      <div style="padding:24px;text-align:center;color:#6e7681">No customers found.</div>
    <?php else : ?>
    <table class="pdx-table">
      <thead>
        <tr>
          <th>Name</th><th>Email</th><th>Verified</th><th>Account</th><th>Payment</th><th>Registered</th><th>Last Login</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $customers as $c ) :
          $row = PDX_Customers::customer_row( $c->ID );
        ?>
        <tr>
          <td><?php echo esc_html( $row['display_name'] ); ?></td>
          <td style="font-size:11px"><?php echo esc_html( $row['email'] ); ?></td>
          <td><?php echo $row['verified'] ? '<span style="color:#1D9BF0">Verified</span>' : 'Not Verified'; ?></td>
          <td><?php echo esc_html( ucfirst( $row['account_status'] ) ); ?></td>
          <td><?php echo esc_html( $row['payment_summary']['label'] ?? 'Free' ); ?></td>
          <td><?php echo esc_html( mysql2date( 'Y-m-d', $row['registered'] ) ); ?></td>
          <td><?php echo esc_html( $row['last_login'] ? mysql2date( 'Y-m-d H:i', $row['last_login'] ) : '—' ); ?></td>
          <td><a href="<?php echo esc_url( add_query_arg( [ 'page' => PDX_SLUG . '-customers', 'customer_id' => $c->ID, 's' => $search ?: null ], admin_url( 'admin.php' ) ) ); ?>">Manage</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ( $pages > 1 ) : ?>
<div style="margin-top:12px;display:flex;gap:8px">
  <?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
    <a class="pdx-btn-ghost<?php echo $p === $page_num ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => PDX_SLUG . '-customers', 'paged' => $p, 's' => $search ?: null ], admin_url( 'admin.php' ) ) ); ?>"><?php echo (int) $p; ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

</main></div></div>
