<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s = $this->settings->all();
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>UI & Style</h1>
  <p>Streamlined dock appearance settings. Navigation layout is now standardized for reliability (desktop left rail, mobile under-header bar).</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="ui">

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Appearance</h2></div>
    <div class="pdx-card__body pdx-grid-3">
      <div class="pdx-field">
        <label>Theme</label>
        <div class="pdx-radio-group">
          <?php foreach ( [ 'dark' => 'Dark', 'light' => 'Light', 'auto' => 'System' ] as $val => $lbl ) : ?>
          <label class="pdx-radio <?php echo $s['dock_theme'] === $val ? 'is-selected' : ''; ?>">
            <input type="radio" name="dock_theme" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['dock_theme'], $val ); ?>>
            <?php echo esc_html( $lbl ); ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pdx-field">
        <label>Dock Size</label>
        <div class="pdx-radio-group">
          <?php foreach ( [ 'compact' => 'Compact', 'default' => 'Default', 'large' => 'Large' ] as $val => $lbl ) : ?>
          <label class="pdx-radio <?php echo $s['dock_size'] === $val ? 'is-selected' : ''; ?>">
            <input type="radio" name="dock_size" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['dock_size'], $val ); ?>>
            <?php echo esc_html( $lbl ); ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pdx-field pdx-field--color">
        <label for="accent_color">Accent Color</label>
        <div class="pdx-color-picker">
          <input type="color" id="accent_color" name="accent_color" value="<?php echo esc_attr( $s['accent_color'] ); ?>">
          <input type="text" id="accent_color_hex" class="pdx-color-hex" value="<?php echo esc_attr( $s['accent_color'] ); ?>" placeholder="#ffffff" maxlength="7">
        </div>
      </div>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Responsive</h2></div>
    <div class="pdx-card__body">
      <div class="pdx-settings-stack pdx-stack-section">
        <?php
        $name        = 'mobile_enabled';
        $label       = 'Enable on mobile';
        $description = 'Shows the horizontal dock directly below the header.';
        $checked     = $s['mobile_enabled'];
        include __DIR__ . '/partials/settings-toggle.php';
        ?>
      </div>

      <div class="pdx-grid-2">
        <div class="pdx-field">
          <label for="mobile_breakpoint">Mobile Breakpoint (px)</label>
          <input type="number" id="mobile_breakpoint" name="mobile_breakpoint" value="<?php echo esc_attr( $s['mobile_breakpoint'] ); ?>" min="320" max="1280" step="10">
        </div>
        <div class="pdx-field">
          <label for="mobile_dock_height">Dock Height (px)</label>
          <input type="number" id="mobile_dock_height" name="mobile_dock_height" value="<?php echo esc_attr( $s['mobile_dock_height'] ?? 48 ); ?>" min="36" max="72" step="2">
        </div>
        <div class="pdx-field">
          <label for="mobile_panel_height">Panel Height (%)</label>
          <input type="number" id="mobile_panel_height" name="mobile_panel_height" value="<?php echo esc_attr( $s['mobile_panel_height'] ?? 90 ); ?>" min="50" max="96" step="1">
        </div>
      </div>

      <div class="pdx-settings-stack" style="margin-top:var(--pdx-space-4)">
        <?php
        $name = 'mobile_hide_dock'; $label = 'Hide dock while panel is open';
        $description = 'Prevents overlap while viewing module content.';
        $checked = $s['mobile_hide_dock'] ?? true;
        include __DIR__ . '/partials/settings-toggle.php';
        ?>
      </div>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Custom CSS</h2></div>
    <div class="pdx-card__body">
      <div class="pdx-field">
        <label for="custom_css">Additional CSS</label>
        <textarea id="custom_css" name="custom_css" rows="10" class="pdx-code-editor" placeholder="/* Optional: scoped enhancements for #pdx-root */"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
        <p class="pdx-field-hint">Use only for cosmetic tweaks. Core navigation layout is managed automatically.</p>
      </div>
    </div>
  </div>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Changes</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
