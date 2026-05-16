<?php if ( ! defined( 'ABSPATH' ) ) exit;
include __DIR__ . '/partials/header.php';

$purged  = isset( $_GET['purged'] ) && $_GET['purged'] === '1';
$results = $purged ? get_transient( 'pdx_purge_results' ) : null;
if ( $purged ) delete_transient( 'pdx_purge_results' );

$cf = PDX_CachePurge::get_cloudflare();
?>
<div class="pdx-page-header">
  <h1>Cache Management</h1>
  <p>Purge every caching layer — transients, object cache, caching plugins, minification, and Cloudflare.</p>
</div>

<?php if ( $purged ) : ?>
<div class="pdx-notice pdx-notice--success" style="margin-bottom:20px">
  <strong>Cache purged successfully.</strong>
  <?php if ( $results ) : ?>
  <details style="margin-top:8px;font-size:12px;opacity:.8">
    <summary style="cursor:pointer">Details</summary>
    <pre style="margin-top:6px;white-space:pre-wrap;word-break:break-all"><?php echo esc_html( json_encode( $results, JSON_PRETTY_PRINT ) ); ?></pre>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Manual Purge ─────────────────────────────────────── -->
<div class="pdx-card" style="margin-bottom:24px">
  <div class="pdx-card__header">
    <h2>Purge All Caches Now</h2>
  </div>
  <div class="pdx-card__body">
    <p style="margin:0 0 16px;color:var(--pa-text-mid,#8b949e)">
      Clears every layer: PDX transients, WordPress object cache, rewrite rules,
      all detected caching plugins (W3TC, WP Rocket, LiteSpeed, Autoptimize, etc.),
      minification caches, and Cloudflare (if configured below).
    </p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
      <?php wp_nonce_field( 'pdx_purge_cache', 'pdx_nonce' ); ?>
      <input type="hidden" name="action" value="pdx_purge_cache">
      <button type="submit" class="pdx-btn-primary" style="padding:10px 24px;font-size:14px">
        Purge All Caches
      </button>
    </form>
  </div>
</div>

<!-- ── What gets purged ─────────────────────────────────── -->
<div class="pdx-card" style="margin-bottom:24px">
  <div class="pdx-card__header"><h2>What Gets Purged</h2></div>
  <div class="pdx-card__body">
    <div class="pdx-grid-2">
      <div>
        <h4 style="margin:0 0 8px;font-size:13px;color:var(--pa-text-hi,#e6edf3)">WordPress Core</h4>
        <ul style="margin:0;padding-left:16px;color:var(--pa-text-mid,#8b949e);font-size:13px;line-height:1.8">
          <li>All <code>pdx_*</code> transients</li>
          <li>WP object cache (<code>wp_cache_flush</code>)</li>
          <li>Rewrite rules</li>
        </ul>
      </div>
      <div>
        <h4 style="margin:0 0 8px;font-size:13px;color:var(--pa-text-hi,#e6edf3)">Caching Plugins</h4>
        <ul style="margin:0;padding-left:16px;color:var(--pa-text-mid,#8b949e);font-size:13px;line-height:1.8">
          <li>W3 Total Cache</li>
          <li>WP Super Cache</li>
          <li>WP Rocket (pages + minify)</li>
          <li>LiteSpeed Cache</li>
          <li>Autoptimize</li>
          <li>Hummingbird</li>
          <li>Cache Enabler</li>
          <li>SG Optimizer</li>
          <li>Breeze (Cloudways)</li>
          <li>Kinsta / WP Engine / Nginx Helper</li>
          <li>Comet Cache, Swift Performance, Varnish</li>
        </ul>
      </div>
      <div>
        <h4 style="margin:0 0 8px;font-size:13px;color:var(--pa-text-hi,#e6edf3)">Asset Minification</h4>
        <ul style="margin:0;padding-left:16px;color:var(--pa-text-mid,#8b949e);font-size:13px;line-height:1.8">
          <li>Autoptimize CSS/JS cache</li>
          <li>WP Rocket minify cache</li>
          <li>Cached copies of <code>paxdesign-*</code> assets</li>
        </ul>
      </div>
      <div>
        <h4 style="margin:0 0 8px;font-size:13px;color:var(--pa-text-hi,#e6edf3)">Auto-Purge Triggers</h4>
        <ul style="margin:0;padding-left:16px;color:var(--pa-text-mid,#8b949e);font-size:13px;line-height:1.8">
          <li>Plugin version change (on update)</li>
          <li>Any settings save in this admin panel</li>
          <li>Manual button above</li>
          <li>REST endpoint <code>POST /pdx/v1/cache/purge</code></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- ── Cloudflare ───────────────────────────────────────── -->
<div class="pdx-card" style="margin-bottom:24px">
  <div class="pdx-card__header"><h2>Cloudflare</h2></div>
  <div class="pdx-card__body">
    <p style="margin:0 0 16px;color:var(--pa-text-mid,#8b949e)">
      Optional. When configured, every cache purge also calls the Cloudflare
      API to purge your zone. Use an API Token with <strong>Cache Purge</strong>
      permission scoped to your zone.
    </p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
      <?php wp_nonce_field( 'pdx_save_cloudflare', 'pdx_nonce' ); ?>
      <input type="hidden" name="action" value="pdx_save_cloudflare">

      <div class="pdx-grid-2">
        <div class="pdx-field">
          <label for="cf_zone_id">Zone ID</label>
          <input type="text" id="cf_zone_id" name="cf_zone_id"
                 value="<?php echo esc_attr( $cf['zone_id'] ); ?>"
                 placeholder="e.g. 023e105f4ecef8ad9ca31a8372d0c353"
                 autocomplete="off" spellcheck="false">
          <p class="pdx-field-hint">Found in your Cloudflare dashboard → Overview → Zone ID.</p>
        </div>
        <div class="pdx-field">
          <label for="cf_api_token">API Token</label>
          <input type="password" id="cf_api_token" name="cf_api_token"
                 value="<?php echo esc_attr( $cf['api_token'] ); ?>"
                 placeholder="Cloudflare API Token"
                 autocomplete="new-password">
          <p class="pdx-field-hint">Create at Cloudflare → My Profile → API Tokens. Needs <em>Cache Purge</em> permission.</p>
        </div>
      </div>

      <button type="submit" class="pdx-btn-primary" style="margin-top:8px;padding:9px 20px;font-size:13px">
        Save Cloudflare Settings
      </button>
    </form>
  </div>
</div>

<!-- ── Browser cache instructions ──────────────────────── -->
<div class="pdx-card">
  <div class="pdx-card__header"><h2>Browser Cache</h2></div>
  <div class="pdx-card__body">
    <p style="margin:0 0 12px;color:var(--pa-text-mid,#8b949e)">
      Plugin assets are versioned with <code>PDX_VERSION</code> (currently <strong><?php echo esc_html( PDX_VERSION ); ?></strong>).
      Every time the version number changes, WordPress appends a new <code>?ver=</code> query string,
      forcing browsers and CDNs to fetch the new file.
    </p>
    <p style="margin:0 0 12px;color:var(--pa-text-mid,#8b949e)">
      If you still see old assets after an update, do a hard refresh:
    </p>
    <ul style="margin:0 0 16px;padding-left:16px;color:var(--pa-text-mid,#8b949e);font-size:13px;line-height:2">
      <li><strong>Chrome / Edge / Firefox:</strong> <kbd>Ctrl+Shift+R</kbd> (Windows/Linux) or <kbd>Cmd+Shift+R</kbd> (Mac)</li>
      <li><strong>Safari:</strong> <kbd>Cmd+Option+R</kbd> or empty cache via Develop menu</li>
      <li><strong>Mobile:</strong> Clear browser data in Settings → Privacy → Clear browsing data</li>
    </ul>
    <p style="margin:0;color:var(--pa-text-mid,#8b949e);font-size:12px">
      If a caching plugin is serving a minified/combined bundle that includes old plugin assets,
      use the <strong>Purge All Caches</strong> button above — it clears minification caches too.
    </p>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
