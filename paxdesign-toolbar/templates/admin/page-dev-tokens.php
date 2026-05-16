<?php if ( ! defined( 'ABSPATH' ) ) exit;
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Dev Tokens</h1>
  <p>API tokens for development and automation.</p>
</div>

<p>Users manage their own tokens from the dock interface (<strong>Cmd+K → Developer Tokens</strong>). This page shows a platform-level overview.</p>

<?php
global $wpdb;
$users_with_tokens = $wpdb->get_results(
	"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'pdx_dev_tokens' AND meta_value != '' AND meta_value != 'a:0:{}' LIMIT 100",
	ARRAY_A
);
?>

<h2>Users with Active Tokens</h2>
<?php if ( empty( $users_with_tokens ) ) : ?>
  <p>No developer tokens issued yet.</p>
<?php else : ?>
<table class="widefat striped">
  <thead><tr><th>User</th><th>Email</th><th>Token Count</th><th>Token IDs</th></tr></thead>
  <tbody>
  <?php foreach ( $users_with_tokens as $row ) :
    $tokens = maybe_unserialize( $row['meta_value'] );
    if ( ! is_array( $tokens ) ) continue;
    $user = get_userdata( (int) $row['user_id'] );
  ?>
    <tr>
      <td><?php echo $user ? esc_html( $user->display_name ) : '(deleted)'; ?></td>
      <td><?php echo $user ? esc_html( $user->user_email ) : '—'; ?></td>
      <td><?php echo count( $tokens ); ?></td>
      <td>
        <?php foreach ( $tokens as $tid => $tok ) : ?>
          <code><?php echo esc_html( $tid ); ?></code> (<?php echo esc_html( $tok['label'] ?? '' ); ?>)<br>
        <?php endforeach; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2 style="margin-top:24px">REST API Reference</h2>
<p>Base URL: <code><?php echo esc_html( rest_url( 'pdx/v1' ) ); ?></code></p>
<table class="widefat striped">
  <thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>
  <tbody>
    <tr><td>GET</td><td>/trust</td><td>Domain TrustCheck scan</td></tr>
    <tr><td>POST</td><td>/osint/scan</td><td>Multi-source OSINT scan</td></tr>
    <tr><td>POST</td><td>/intel/correlate</td><td>IOC correlation + graph</td></tr>
    <tr><td>GET</td><td>/intel/timeline</td><td>Threat timeline reconstruction</td></tr>
    <tr><td>GET</td><td>/intel/clusters</td><td>Threat cluster analysis</td></tr>
    <tr><td>GET</td><td>/billing/plans</td><td>Available subscription plans</td></tr>
    <tr><td>GET</td><td>/billing/status</td><td>Current user billing status</td></tr>
    <tr><td>POST</td><td>/billing/checkout</td><td>Create Stripe checkout session</td></tr>
    <tr><td>POST</td><td>/workers/register</td><td>Register a worker node</td></tr>
    <tr><td>POST</td><td>/workers/heartbeat</td><td>Worker heartbeat + job poll</td></tr>
    <tr><td>POST</td><td>/memory/store</td><td>Store AI memory entry</td></tr>
    <tr><td>GET</td><td>/memory/search</td><td>Semantic memory search</td></tr>
    <tr><td>GET</td><td>/teams</td><td>List user teams</td></tr>
    <tr><td>POST</td><td>/teams</td><td>Create team</td></tr>
    <tr><td>GET</td><td>/teams/{id}/cases</td><td>List team cases</td></tr>
    <tr><td>POST</td><td>/teams/{id}/cases</td><td>Create investigation case</td></tr>
    <tr><td>GET</td><td>/command/search</td><td>Command palette search</td></tr>
    <tr><td>GET</td><td>/platform/stats</td><td>Platform-wide statistics</td></tr>
    <tr><td>GET</td><td>/dev/tokens</td><td>List developer tokens</td></tr>
    <tr><td>POST</td><td>/dev/tokens</td><td>Create developer token</td></tr>
    <tr><td>DELETE</td><td>/dev/tokens/{id}</td><td>Revoke developer token</td></tr>
    <tr><td>GET</td><td>/sse</td><td>SSE stream (channel param)</td></tr>
  </tbody>
</table>

<h2 style="margin-top:24px">Authentication</h2>
<p>All endpoints accept WordPress cookie auth (nonce) or a developer token via <code>Authorization: Bearer pdx_&lt;token&gt;</code> header.</p>


<?php include __DIR__ . '/partials/footer.php'; ?>
