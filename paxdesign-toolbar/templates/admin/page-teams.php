<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap pdx-admin-wrap">
<h1 class="pdx-admin-title">Team Management</h1>

<?php
global $wpdb;
$table  = $wpdb->prefix . 'pdx_teams';
$teams  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50", ARRAY_A );
$mtable = $wpdb->prefix . 'pdx_team_members';
?>

<?php if ( empty( $teams ) ) : ?>
  <p>No teams created yet. Teams are created by users from the dock interface.</p>
<?php else : ?>
<table class="widefat striped">
  <thead>
    <tr><th>Team ID</th><th>Name</th><th>Owner</th><th>Members</th><th>Cases</th><th>Created</th></tr>
  </thead>
  <tbody>
  <?php foreach ( $teams as $team ) :
    $member_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mtable} WHERE team_id = %s", $team['team_id'] ) );
    $ctable = $wpdb->prefix . 'pdx_cases';
    $case_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ctable} WHERE team_id = %s", $team['team_id'] ) );
    $owner = get_userdata( (int) $team['owner_id'] );
  ?>
    <tr>
      <td><code><?php echo esc_html( $team['team_id'] ); ?></code></td>
      <td><strong><?php echo esc_html( $team['name'] ); ?></strong></td>
      <td><?php echo $owner ? esc_html( $owner->display_name ) : '—'; ?></td>
      <td><?php echo $member_count; ?></td>
      <td><?php echo $case_count; ?></td>
      <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), $team['created_at'] ) ); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2 style="margin-top:24px">RBAC Roles</h2>
<table class="widefat">
  <thead><tr><th>Role</th><th>Permissions</th></tr></thead>
  <tbody>
    <tr><td><strong>owner</strong></td><td>All permissions including billing and team deletion</td></tr>
    <tr><td><strong>admin</strong></td><td>Manage members, cases, investigations, settings</td></tr>
    <tr><td><strong>analyst</strong></td><td>Create/edit cases, run scans, add notes</td></tr>
    <tr><td><strong>viewer</strong></td><td>Read-only access to cases and reports</td></tr>
  </tbody>
</table>
</div>
