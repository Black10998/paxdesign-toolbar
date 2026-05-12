<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s = $this->settings->all();
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>UI & Style</h1>
  <p>Dock appearance, positioning, theming, and responsive behaviour.</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="ui">

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Layout</h2></div>
    <div class="pdx-card__body pdx-grid-3">

      <div class="pdx-field">
        <label>Dock Position</label>
        <div class="pdx-radio-group">
          <label class="pdx-radio <?php echo $s['dock_position'] === 'left' ? 'is-selected' : ''; ?>">
            <input type="radio" name="dock_position" value="left" <?php checked( $s['dock_position'], 'left' ); ?>>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><rect x="3" y="3" width="4" height="18" rx="1"/><rect x="9" y="7" width="12" height="10" rx="1"/></svg>
            Left
          </label>
          <label class="pdx-radio <?php echo $s['dock_position'] === 'right' ? 'is-selected' : ''; ?>">
            <input type="radio" name="dock_position" value="right" <?php checked( $s['dock_position'], 'right' ); ?>>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><rect x="17" y="3" width="4" height="18" rx="1"/><rect x="3" y="7" width="12" height="10" rx="1"/></svg>
            Right
          </label>
        </div>
      </div>

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

    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Accent Color</h2></div>
    <div class="pdx-card__body">
      <div class="pdx-field pdx-field--color">
        <label for="accent_color">Accent Color</label>
        <div class="pdx-color-picker">
          <input type="color" id="accent_color" name="accent_color"
                 value="<?php echo esc_attr( $s['accent_color'] ); ?>">
          <input type="text" id="accent_color_hex" class="pdx-color-hex"
                 value="<?php echo esc_attr( $s['accent_color'] ); ?>"
                 placeholder="#3fb950" maxlength="7">
        </div>
        <p class="pdx-field-hint">Used for active states, CTA buttons, and positive indicators.</p>
      </div>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Mobile</h2></div>
    <div class="pdx-card__body pdx-grid-2">
      <div class="pdx-field">
        <label class="pdx-toggle">
          <input type="checkbox" name="mobile_enabled" value="1" <?php checked( $s['mobile_enabled'] ); ?>>
          <span class="pdx-toggle__track"></span>
          <span class="pdx-toggle__label">Enable on mobile</span>
        </label>
        <p class="pdx-field-hint">When disabled, the dock is hidden below the breakpoint.</p>
      </div>
      <div class="pdx-field">
        <label for="mobile_breakpoint">Mobile Breakpoint (px)</label>
        <input type="number" id="mobile_breakpoint" name="mobile_breakpoint"
               value="<?php echo esc_attr( $s['mobile_breakpoint'] ); ?>"
               min="320" max="1280" step="10">
        <p class="pdx-field-hint">Viewport width below which mobile layout activates.</p>
      </div>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header"><h2>Custom CSS</h2></div>
    <div class="pdx-card__body">
      <div class="pdx-field">
        <label for="custom_css">Additional CSS</label>
        <textarea id="custom_css" name="custom_css" rows="10" class="pdx-code-editor"
                  placeholder="/* Target #pdx-root for scoped overrides */"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
        <p class="pdx-field-hint">Injected after the plugin stylesheet. Scope to <code>#pdx-root</code> to avoid conflicts.</p>
      </div>
    </div>
  </div>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Changes</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
