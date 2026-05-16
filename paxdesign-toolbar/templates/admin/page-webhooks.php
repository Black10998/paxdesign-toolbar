<?php if ( ! defined( 'ABSPATH' ) ) exit;
include __DIR__ . '/partials/header.php';

$webhooks = PDX_Webhook::all();
$events   = PDX_Webhook::available_events();
$log      = PDX_Webhook::get_log( '', 20 );
$stats    = PDX_Webhook::delivery_stats();
$stats_by_id = [];
foreach ( $stats as $s ) { $stats_by_id[ $s['webhook_id'] ] = $s; }
?>
<div class="pdx-admin-section">
  <div class="pdx-admin-card">
    <div class="pdx-admin-card__header">
      <div>
        <h2 class="pdx-admin-card__title">Webhooks</h2>
        <p class="pdx-admin-card__desc">Outbound event delivery to external systems. Signed with HMAC-SHA256.</p>
      </div>
      <button class="pdx-admin-btn pdx-admin-btn--primary" id="pdx-wh-add-btn">+ New Webhook</button>
    </div>
    <div class="pdx-admin-card__body">

      <!-- Create form (hidden by default) -->
      <div id="pdx-wh-form" style="display:none; margin-bottom:24px;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
          <?php wp_nonce_field( 'pdx_webhook_create' ); ?>
          <input type="hidden" name="action" value="pdx_webhook_create" />
          <div class="pdx-admin-form-grid">
            <div class="pdx-admin-field">
              <label class="pdx-admin-label">Name</label>
              <input type="text" name="wh_name" class="pdx-admin-input" placeholder="My Webhook" required />
            </div>
            <div class="pdx-admin-field">
              <label class="pdx-admin-label">URL</label>
              <input type="url" name="wh_url" class="pdx-admin-input" placeholder="https://hooks.example.com/..." required />
            </div>
            <div class="pdx-admin-field">
              <label class="pdx-admin-label">Secret (optional)</label>
              <input type="text" name="wh_secret" class="pdx-admin-input" placeholder="Used for HMAC signature" />
            </div>
            <div class="pdx-admin-field pdx-admin-field--full">
              <label class="pdx-admin-label">Events</label>
              <div class="pdx-admin-checkbox-grid">
                <?php foreach ( $events as $event => $label ) : ?>
                  <label class="pdx-admin-checkbox-label">
                    <input type="checkbox" name="wh_events[]" value="<?php echo esc_attr( $event ); ?>" />
                    <span><?php echo esc_html( $label ); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div style="margin-top:12px; display:flex; gap:8px;">
            <button type="submit" class="pdx-admin-btn pdx-admin-btn--primary">Create Webhook</button>
            <button type="button" class="pdx-admin-btn" id="pdx-wh-cancel">Cancel</button>
          </div>
        </form>
      </div>

      <!-- Webhook list -->
      <?php if ( empty( $webhooks ) ) : ?>
        <div class="pdx-admin-empty">No webhooks configured. Create one to start receiving events.</div>
      <?php else : ?>
        <table class="pdx-admin-table">
          <thead><tr><th>Name</th><th>URL</th><th>Events</th><th>Delivered</th><th>Failed</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ( $webhooks as $wh ) :
              $s = $stats_by_id[ $wh['id'] ] ?? [ 'total' => 0, 'delivered' => 0, 'failed' => 0 ];
            ?>
              <tr>
                <td><strong><?php echo esc_html( $wh['name'] ); ?></strong></td>
                <td><code class="pdx-admin-code"><?php echo esc_html( substr( $wh['url'], 0, 50 ) . ( strlen( $wh['url'] ) > 50 ? '…' : '' ) ); ?></code></td>
                <td><?php echo esc_html( implode( ', ', array_slice( $wh['events'], 0, 3 ) ) . ( count( $wh['events'] ) > 3 ? ' +' . ( count( $wh['events'] ) - 3 ) : '' ) ); ?></td>
                <td class="pdx-admin-stat--green"><?php echo (int) $s['delivered']; ?></td>
                <td class="pdx-admin-stat--red"><?php echo (int) $s['failed']; ?></td>
                <td><span class="pdx-admin-badge pdx-admin-badge--<?php echo $wh['active'] ? 'green' : 'gray'; ?>"><?php echo $wh['active'] ? 'Active' : 'Paused'; ?></span></td>
                <td>
                  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'pdx_webhook_delete_' . $wh['id'] ); ?>
                    <input type="hidden" name="action" value="pdx_webhook_delete" />
                    <input type="hidden" name="wh_id" value="<?php echo esc_attr( $wh['id'] ); ?>" />
                    <button type="submit" class="pdx-admin-btn pdx-admin-btn--danger pdx-admin-btn--sm" onclick="return confirm('Delete this webhook?')">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Delivery log -->
  <div class="pdx-admin-card">
    <div class="pdx-admin-card__header">
      <h2 class="pdx-admin-card__title">Delivery Log</h2>
      <span class="pdx-admin-badge"><?php echo count( $log ); ?> recent</span>
    </div>
    <div class="pdx-admin-card__body">
      <?php if ( empty( $log ) ) : ?>
        <div class="pdx-admin-empty">No deliveries yet.</div>
      <?php else : ?>
        <table class="pdx-admin-table">
          <thead><tr><th>Event</th><th>URL</th><th>Status</th><th>Code</th><th>Sent</th></tr></thead>
          <tbody>
            <?php foreach ( $log as $entry ) : ?>
              <tr>
                <td><code class="pdx-admin-code"><?php echo esc_html( $entry['event'] ); ?></code></td>
                <td><?php echo esc_html( substr( $entry['url'], 0, 40 ) . '…' ); ?></td>
                <td><span class="pdx-admin-badge pdx-admin-badge--<?php echo $entry['delivered'] ? 'green' : 'red'; ?>"><?php echo $entry['delivered'] ? 'Delivered' : 'Failed'; ?></span></td>
                <td><?php echo esc_html( $entry['status_code'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $entry['sent_at'] ); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.getElementById('pdx-wh-add-btn').addEventListener('click', function() {
  document.getElementById('pdx-wh-form').style.display = 'block';
  this.style.display = 'none';
});
document.getElementById('pdx-wh-cancel') && document.getElementById('pdx-wh-cancel').addEventListener('click', function() {
  document.getElementById('pdx-wh-form').style.display = 'none';
  document.getElementById('pdx-wh-add-btn').style.display = '';
});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
