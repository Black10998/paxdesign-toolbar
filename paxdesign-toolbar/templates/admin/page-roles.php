<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s            = $this->settings->all();
$wp_roles     = get_editable_roles();
$show_to      = (array) $s['show_to_roles'];
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Roles & Permissions</h1>
  <p>Control which visitors and user roles see the dock.</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="roles">

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Visibility</h2></div>
    <div class="pdx-card__body">
      <label class="pdx-toggle">
        <input type="checkbox" name="hide_for_logged_out" value="1" <?php checked( $s['hide_for_logged_out'] ); ?>>
        <span class="pdx-toggle__track"></span>
        <span class="pdx-toggle__label">Hide for logged-out visitors</span>
      </label>
      <div class="pdx-spacer"></div>
      <label class="pdx-toggle">
        <input type="checkbox" name="hide_for_logged_in" value="1" <?php checked( $s['hide_for_logged_in'] ); ?>>
        <span class="pdx-toggle__track"></span>
        <span class="pdx-toggle__label">Hide for logged-in users</span>
      </label>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Role Access</h2></div>
    <div class="pdx-card__body">
      <p class="pdx-field-hint" style="margin-bottom:16px;">Select which roles can see the dock. Select "All visitors" to show to everyone regardless of role.</p>
      <div class="pdx-role-grid">
        <label class="pdx-role-card <?php echo in_array( 'all', $show_to, true ) ? 'is-selected' : ''; ?>">
          <input type="checkbox" name="show_to_roles[]" value="all" <?php checked( in_array( 'all', $show_to, true ) ); ?>>
          <span class="pdx-role-card__name">All visitors</span>
          <span class="pdx-role-card__desc">Logged in and out</span>
        </label>
        <?php foreach ( $wp_roles as $role_key => $role_data ) : ?>
        <label class="pdx-role-card <?php echo in_array( $role_key, $show_to, true ) ? 'is-selected' : ''; ?>">
          <input type="checkbox" name="show_to_roles[]" value="<?php echo esc_attr( $role_key ); ?>"
                 <?php checked( in_array( $role_key, $show_to, true ) ); ?>>
          <span class="pdx-role-card__name"><?php echo esc_html( $role_data['name'] ); ?></span>
          <span class="pdx-role-card__desc"><?php echo esc_html( $role_key ); ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Changes</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
