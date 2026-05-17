<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s       = $this->settings->all();
$modules = $this->modules->all();
$cats    = [
  'security' => 'Security & Intelligence',
  'ai'       => 'AI Services',
  'services' => 'Development Services',
];
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>Modules</h1>
  <p>Enable or disable individual dock modules. Disabled modules are hidden from the frontend.</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="modules">

  <?php foreach ( $cats as $cat_key => $cat_label ) :
    $cat_modules = array_filter( $modules, static fn( $m ) => $m['category'] === $cat_key );
    if ( empty( $cat_modules ) ) continue;
  ?>
  <section class="pdx-section">
    <header class="pdx-section__head"><h2><?php echo esc_html( $cat_label ); ?></h2></header>
    <div class="pdx-section__body">
      <div class="pdx-module-list">
        <?php foreach ( $cat_modules as $id => $mod ) :
          $enabled = $s['modules'][ $id ] ?? true;
        ?>
        <div class="pdx-module-row <?php echo $enabled ? 'is-enabled' : ''; ?>">
          <div class="pdx-module-row__icon">
            <?php echo $this->get_svg_icon_html( $id ); ?>
          </div>
          <div class="pdx-module-row__info">
            <strong><?php echo esc_html( $mod['label'] ); ?></strong>
            <span><?php echo esc_html( $mod['description'] ); ?></span>
          </div>
          <label class="pdx-toggle pdx-toggle--sm pdx-settings-row__control">
            <input type="checkbox" name="modules[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $enabled ); ?> aria-label="<?php echo esc_attr( $mod['label'] ); ?>">
            <span class="pdx-toggle__track" aria-hidden="true"></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endforeach; ?>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Changes</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
