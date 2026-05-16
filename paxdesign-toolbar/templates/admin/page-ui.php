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
                 placeholder="#c2ff00" maxlength="7">
        </div>
        <p class="pdx-field-hint">Used for active states, CTA buttons, and positive indicators.</p>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════
       MOBILE LAYOUT CONTROLS
  ═══════════════════════════════════════════════════════ -->
  <div class="pdx-card">
    <div class="pdx-card__header">
      <h2>Mobile Layout</h2>
      <span class="pdx-badge">Responsive</span>
    </div>
    <div class="pdx-card__body">

      <!-- Row 1: Enable + Breakpoint -->
      <div class="pdx-grid-2" style="margin-bottom:20px">
        <div class="pdx-field">
          <label class="pdx-toggle">
            <input type="checkbox" name="mobile_enabled" value="1" <?php checked( $s['mobile_enabled'] ); ?>>
            <span class="pdx-toggle__track"></span>
            <span class="pdx-toggle__label">Enable on mobile</span>
          </label>
          <p class="pdx-field-hint">When disabled, the dock is hidden below the breakpoint.</p>
        </div>

        <div class="pdx-field">
          <label for="mobile_breakpoint">Breakpoint (px)</label>
          <input type="number" id="mobile_breakpoint" name="mobile_breakpoint"
                 value="<?php echo esc_attr( $s['mobile_breakpoint'] ); ?>"
                 min="320" max="1280" step="10">
          <p class="pdx-field-hint">Viewport width where mobile layout activates. Default: 680.</p>
        </div>
      </div>

      <!-- Row 2: Dock Position -->
      <div class="pdx-field" style="margin-bottom:20px">
        <label>Dock Position</label>
        <div class="pdx-radio-group">
          <?php foreach ( [
            'under-header'  => 'Top — Under Header',
            'bottom-center' => 'Bottom Centre',
            'bottom-left'   => 'Bottom Left',
            'bottom-right'  => 'Bottom Right',
          ] as $val => $lbl ) : ?>
          <label class="pdx-radio <?php echo ( $s['mobile_dock_position'] ?? 'under-header' ) === $val ? 'is-selected' : ''; ?>">
            <input type="radio" name="mobile_dock_position" value="<?php echo esc_attr( $val ); ?>"
                   <?php checked( $s['mobile_dock_position'] ?? 'under-header', $val ); ?>>
            <?php echo esc_html( $lbl ); ?>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="pdx-field-hint">
          <strong>Top — Under Header</strong> pins a full-width glass bar directly below the browser chrome. Panel slides down from it. Recommended for phones.<br>
          <strong>Bottom</strong> options show a compact floating pill above the safe area with a bottom-sheet panel.
        </p>
      </div>

      <!-- Row 3: Dock Height + Panel Height -->
      <div class="pdx-grid-2" style="margin-bottom:20px">
        <div class="pdx-field">
          <label for="mobile_dock_height">Dock Height (px)</label>
          <input type="number" id="mobile_dock_height" name="mobile_dock_height"
                 value="<?php echo esc_attr( $s['mobile_dock_height'] ?? 48 ); ?>"
                 min="36" max="72" step="2">
          <p class="pdx-field-hint">Height of the dock bar in under-header mode. Default: 48.</p>
        </div>

        <div class="pdx-field">
          <label for="mobile_panel_height">Panel Height (% of screen)</label>
          <input type="number" id="mobile_panel_height" name="mobile_panel_height"
                 value="<?php echo esc_attr( $s['mobile_panel_height'] ?? 90 ); ?>"
                 min="50" max="96" step="1">
          <p class="pdx-field-hint">Height of the bottom-sheet panel. 90 is recommended.</p>
        </div>
      </div>

      <!-- Row 4: Icon Size + Button Size -->
      <div class="pdx-grid-2" style="margin-bottom:20px">
        <div class="pdx-field">
          <label for="mobile_icon_size">Icon Size (px)</label>
          <input type="number" id="mobile_icon_size" name="mobile_icon_size"
                 value="<?php echo esc_attr( $s['mobile_icon_size'] ?? 0 ); ?>"
                 min="0" max="28" step="1" placeholder="0 = auto">
          <p class="pdx-field-hint">Override icon size in dock buttons. 0 uses the CSS default.</p>
        </div>

        <div class="pdx-field">
          <label for="mobile_btn_size">Button Size (px)</label>
          <input type="number" id="mobile_btn_size" name="mobile_btn_size"
                 value="<?php echo esc_attr( $s['mobile_btn_size'] ?? 0 ); ?>"
                 min="0" max="60" step="2" placeholder="0 = auto">
          <p class="pdx-field-hint">Override dock button width/height. 0 uses the CSS default.</p>
        </div>
      </div>

      <!-- Row 5: Spacing + Responsive Scaling -->
      <div class="pdx-grid-2" style="margin-bottom:20px">
        <div class="pdx-field">
          <label for="mobile_spacing">Panel Spacing</label>
          <select id="mobile_spacing" name="mobile_spacing">
            <?php foreach ( [ 'default' => 'Default', 'compact' => 'Compact', 'relaxed' => 'Relaxed' ] as $val => $lbl ) : ?>
            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['mobile_spacing'] ?? 'default', $val ); ?>>
              <?php echo esc_html( $lbl ); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <p class="pdx-field-hint">Controls padding and gap inside panels on mobile.</p>
        </div>

        <div class="pdx-field">
          <label for="mobile_scale">Responsive Scaling</label>
          <select id="mobile_scale" name="mobile_scale">
            <?php foreach ( [ 'auto' => 'Auto (recommended)', 'fixed' => 'Fixed', 'fluid' => 'Fluid' ] as $val => $lbl ) : ?>
            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['mobile_scale'] ?? 'auto', $val ); ?>>
              <?php echo esc_html( $lbl ); ?>
            </option>
            <?php endforeach; ?>
          </select>
          <p class="pdx-field-hint">How the dock and panel scale across different phone sizes.</p>
        </div>
      </div>

      <!-- Row 6: Behaviour toggles -->
      <div class="pdx-grid-2" style="margin-bottom:20px">
        <div class="pdx-field">
          <label class="pdx-toggle">
            <input type="checkbox" name="mobile_compact" value="1" <?php checked( $s['mobile_compact'] ?? false ); ?>>
            <span class="pdx-toggle__track"></span>
            <span class="pdx-toggle__label">Compact mode</span>
          </label>
          <p class="pdx-field-hint">Reduces button and icon sizes for very small screens.</p>
        </div>

        <div class="pdx-field">
          <label class="pdx-toggle">
            <input type="checkbox" name="mobile_safe_area" value="1" <?php checked( $s['mobile_safe_area'] ?? true ); ?>>
            <span class="pdx-toggle__track"></span>
            <span class="pdx-toggle__label">Respect safe area insets</span>
          </label>
          <p class="pdx-field-hint">Adds padding for iPhone notch and home indicator.</p>
        </div>

        <div class="pdx-field">
          <label class="pdx-toggle">
            <input type="checkbox" name="mobile_swipe_close" value="1" <?php checked( $s['mobile_swipe_close'] ?? true ); ?>>
            <span class="pdx-toggle__track"></span>
            <span class="pdx-toggle__label">Swipe to close panel</span>
          </label>
          <p class="pdx-field-hint">Swipe up (top mode) or down (bottom mode) to dismiss the panel.</p>
        </div>

        <div class="pdx-field">
          <label class="pdx-toggle">
            <input type="checkbox" name="mobile_hide_dock" value="1" <?php checked( $s['mobile_hide_dock'] ?? true ); ?>>
            <span class="pdx-toggle__track"></span>
            <span class="pdx-toggle__label">Hide dock when panel is open</span>
          </label>
          <p class="pdx-field-hint">Slides the dock out of view while a panel is open.</p>
        </div>
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
