<?php if ( ! defined( 'ABSPATH' ) ) exit;
$s    = $this->settings->all();
$keys = $s['api_keys'];
include __DIR__ . '/partials/header.php';
?>
<div class="pdx-page-header">
  <h1>API Keys</h1>
  <p>Configure third-party API credentials. Keys are stored in the WordPress options table (not encrypted at rest — use wp-config constants or a secrets manager in production).</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pdx-form">
  <?php wp_nonce_field( 'pdx_save_settings', 'pdx_nonce' ); ?>
  <input type="hidden" name="action"  value="pdx_save">
  <input type="hidden" name="pdx_tab" value="api">

  <div class="pdx-card">
    <div class="pdx-card__header">
      <h2>AI Services</h2>
    </div>
    <div class="pdx-card__body pdx-grid-2">
      <div class="pdx-field">
        <label for="api_openai">OpenAI API Key</label>
        <div class="pdx-input-group">
          <input type="password" id="api_openai" name="api_keys[openai]"
                 value="<?php echo esc_attr( $keys['openai'] ); ?>"
                 placeholder="sk-…" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="api_openai" aria-label="Toggle visibility">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="pdx-field-hint">Used for AI Personas, Builder, and Pipeline modules.</p>
      </div>
    </div>
  </div>

  <div class="pdx-card">
    <div class="pdx-card__header">
      <h2>Security & Intelligence</h2>
    </div>
    <div class="pdx-card__body pdx-grid-2">
      <div class="pdx-field">
        <label for="api_virustotal">VirusTotal API Key</label>
        <div class="pdx-input-group">
          <input type="password" id="api_virustotal" name="api_keys[virustotal]"
                 value="<?php echo esc_attr( $keys['virustotal'] ); ?>"
                 placeholder="64-char hex key" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="api_virustotal" aria-label="Toggle visibility">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="pdx-field-hint">Enhances Trust Check with malware and reputation data.</p>
      </div>
      <div class="pdx-field">
        <label for="api_shodan">Shodan API Key</label>
        <div class="pdx-input-group">
          <input type="password" id="api_shodan" name="api_keys[shodan]"
                 value="<?php echo esc_attr( $keys['shodan'] ); ?>"
                 placeholder="Shodan API key" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="api_shodan" aria-label="Toggle visibility">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="pdx-field-hint">Used for OSINT and infrastructure intelligence.</p>
      </div>
      <div class="pdx-field">
        <label for="api_hunter">Hunter.io API Key</label>
        <div class="pdx-input-group">
          <input type="password" id="api_hunter" name="api_keys[hunter]"
                 value="<?php echo esc_attr( $keys['hunter'] ); ?>"
                 placeholder="Hunter API key" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="api_hunter" aria-label="Toggle visibility">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="pdx-field-hint">Email discovery for OSINT module.</p>
      </div>
      <div class="pdx-field">
        <label for="api_nvd">NVD API Key</label>
        <div class="pdx-input-group">
          <input type="password" id="api_nvd" name="api_keys[nvd]"
                 value="<?php echo esc_attr( $keys['nvd'] ?? '' ); ?>"
                 placeholder="NVD API key" autocomplete="off">
          <button type="button" class="pdx-input-reveal" data-target="api_nvd" aria-label="Toggle visibility">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="pdx-field-hint">
          Used by Threat Intel → CVE Lookup for CVE searches, CVSS data, and keyword queries.
          <strong>Without a key, NVD enforces a strict rate limit of 5 requests per 30 seconds.</strong>
          A free key removes this limit. Register at
          <a href="https://nvd.nist.gov/developers/request-an-api-key" target="_blank" rel="noopener">nvd.nist.gov</a>.
        </p>
      </div>
    </div>
  </div>

  <div class="pdx-card pdx-card--info">
    <div class="pdx-card__body">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
      <p>API keys are stored in the WordPress database. For production environments, consider using environment variables or a secrets manager and referencing them via <code>define()</code> in <code>wp-config.php</code>.</p>
    </div>
  </div>

  <div class="pdx-form-actions">
    <button type="submit" class="pdx-btn-primary">Save Changes</button>
  </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
