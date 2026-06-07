/**
 * PaxDesign Admin — v7.1
 */
(function () {
  'use strict';

  var wrap = document.querySelector('.pdx-admin-wrap');
  var sidebar = document.getElementById('pdx-sidebar');
  var menuBtn = document.getElementById('pdx-sidebar-toggle');
  var backdrop = document.getElementById('pdx-sidebar-backdrop');
  var themeBtn = document.getElementById('pdx-theme-toggle');

  function setSidebarOpen(open) {
    if (!wrap || !menuBtn) return;
    wrap.classList.toggle('is-sidebar-open', open);
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('pdx-admin-sidebar-open', open);
    if (backdrop) {
      backdrop.hidden = !open;
    }
  }

  /* ── Sidebar (mobile) ─────────────────────────────────── */
  if (wrap && menuBtn) {
    menuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setSidebarOpen(!wrap.classList.contains('is-sidebar-open'));
    });

    if (backdrop) {
      backdrop.addEventListener('click', function () {
        setSidebarOpen(false);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && wrap.classList.contains('is-sidebar-open')) {
        setSidebarOpen(false);
      }
    });
  }

  /* ── Theme toggle ─────────────────────────────────────── */
  var stored = null;
  try { stored = localStorage.getItem('pdx_admin_theme'); } catch (e) {}

  function applyTheme(mode) {
    if (!wrap) return;
    wrap.setAttribute('data-pdx-theme', mode);
    if (themeBtn) themeBtn.textContent = mode === 'light' ? 'Dark' : 'Light';
    try { localStorage.setItem('pdx_admin_theme', mode); } catch (e) {}
  }

  if (stored === 'light' || stored === 'dark') {
    applyTheme(stored);
  }

  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var next = wrap.getAttribute('data-pdx-theme') === 'light' ? 'dark' : 'light';
      applyTheme(next);
    });
  }

  /* ── Color picker sync ──────────────────────────────────── */
  var colorInput = document.getElementById('accent_color');
  var hexInput = document.getElementById('accent_color_hex');

  if (colorInput && hexInput) {
    colorInput.addEventListener('input', function () {
      hexInput.value = colorInput.value;
    });
    hexInput.addEventListener('input', function () {
      var val = hexInput.value.trim();
      if (/^#[0-9a-f]{6}$/i.test(val)) colorInput.value = val;
    });
  }

  /* ── Password reveal ──────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.pdx-input-reveal');
    if (!btn) return;
    var field = document.getElementById(btn.getAttribute('data-target'));
    if (!field) return;
    field.type = field.type === 'password' ? 'text' : 'password';
  });

  /* ── Radio / checkbox cards ───────────────────────────── */
  document.addEventListener('change', function (e) {
    var radio = e.target;
    if (radio.type === 'radio') {
      var group = radio.closest('.pdx-radio-group');
      if (group) {
        group.querySelectorAll('.pdx-radio').forEach(function (l) {
          l.classList.remove('is-selected');
        });
        var parent = radio.closest('.pdx-radio');
        if (parent) parent.classList.add('is-selected');
      }
    }
    if (radio.type === 'checkbox') {
      var roleCard = radio.closest('.pdx-role-card');
      if (roleCard) roleCard.classList.toggle('is-selected', radio.checked);
      var moduleRow = radio.closest('.pdx-module-row');
      if (moduleRow) moduleRow.classList.toggle('is-enabled', radio.checked);
    }
  });

  document.addEventListener('submit', function (e) {
    if (e.target.querySelector('[name="action"][value="pdx_clear_log"]')) {
      if (!confirm('Clear all event logs? This cannot be undone.')) e.preventDefault();
    }
  });

  /* ── Authenticated admin REST (wp_rest nonce) ─────────── */
  var cfg = window.PDX_ADMIN || {};

  function adminRestGet(path) {
    var base = (cfg.restUrl || '').replace(/\/$/, '');
    var url = base + (path.charAt(0) === '/' ? path : '/' + path);
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-WP-Nonce': cfg.restNonce || ''
      }
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        if (text) {
          try {
            data = JSON.parse(text);
          } catch (parseErr) {
            var snippet = text.replace(/\s+/g, ' ').slice(0, 240);
            throw new Error(
              ((cfg.i18n && cfg.i18n.auditParseError) || 'Invalid server response.') +
              ' (HTTP ' + res.status + '): ' + snippet
            );
          }
        }
        return { res: res, data: data || {}, text: text };
      });
    });
  }

  function isAuditPayload(data) {
    return !!(data && Array.isArray(data.providers));
  }

  function showAuditNotice(errEl, message, type) {
    if (!errEl) return;
    errEl.className = 'notice pdx-notice-inline notice-' + (type || 'error');
    errEl.textContent = message;
    errEl.hidden = !message;
  }

  function restErrorMessage(payload) {
    var data = payload.data || {};
    var i18n = cfg.i18n || {};
    if (data.code === 'rest_cookie_invalid_nonce' || data.code === 'rest_invalid_nonce') {
      return i18n.auditNonce || 'REST session expired. Reload this page and try again.';
    }
    if (data.code === 'rest_forbidden' || data.code === 'pdx_rest_forbidden') {
      if (data.data && data.data.required_capability) {
        return (i18n.auditForbidden || 'Access denied.') + ' (' + data.data.required_capability + ')';
      }
      return i18n.auditForbidden || data.message || 'Access denied.';
    }
    if (data.code === 'pdx_rest_unauthorized' || payload.res.status === 401) {
      return data.message || 'You must be logged in as an administrator.';
    }
    return data.message || (cfg.i18n && cfg.i18n.auditFailed) || 'Request failed.';
  }

  function statusClass(status) {
    return 'pdx-audit-status pdx-audit-status--' + String(status || 'error');
  }

  function renderAuditSummary(data) {
    var summary = data.summary || {};
    var providers = data.providers || [];
    var html = '';
    html += '<div class="pdx-audit-summary__totals">';
    html += '<span><strong>OK:</strong> ' + (summary.ok || 0) + '</span>';
    html += '<span><strong>Partial:</strong> ' + (summary.partial || 0) + '</span>';
    html += '<span><strong>Error:</strong> ' + (summary.error || 0) + '</span>';
    html += '<span><strong>Skipped:</strong> ' + (summary.skipped || 0) + '</span>';
    html += '</div>';
    html += '<table class="pdx-table pdx-audit-table"><thead><tr><th>Provider</th><th>Status</th><th>Message</th><th>Latency</th></tr></thead><tbody>';
    providers.forEach(function (row) {
      html += '<tr>';
      html += '<td>' + (row.provider || '') + '</td>';
      html += '<td><span class="' + statusClass(row.status) + '">' + String(row.status || '').toUpperCase() + '</span></td>';
      html += '<td>' + (row.message || '') + '</td>';
      html += '<td>' + (row.latency_ms != null ? row.latency_ms + ' ms' : '—') + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function bindAdminRestButton(btn, opts) {
    if (!btn || !cfg.canManage) return;
    btn.addEventListener('click', function () {
      var endpoint = btn.getAttribute('data-endpoint') || opts.endpoint;
      var label = btn.textContent;
      var errEl = opts.errorEl;
      var outEl = opts.outputEl;
      var summaryEl = opts.summaryEl;
      btn.disabled = true;
      btn.textContent = opts.runningLabel || 'Loading…';
      if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
      if (outEl) { outEl.hidden = true; outEl.textContent = ''; }
      if (summaryEl) { summaryEl.hidden = true; summaryEl.innerHTML = ''; }

      adminRestGet(endpoint)
        .then(function (payload) {
          var data = payload.data || {};

          if (isAuditPayload(data)) {
            if (summaryEl) {
              summaryEl.innerHTML = renderAuditSummary(data);
              summaryEl.hidden = false;
            }
            if (outEl) {
              outEl.textContent = JSON.stringify(data, null, 2);
              outEl.hidden = false;
            }
            if (errEl) {
              if (data.has_provider_errors) {
                showAuditNotice(
                  errEl,
                  data.message || ((cfg.i18n && cfg.i18n.auditPartial) || 'Some providers reported errors.'),
                  'warning'
                );
              } else if (data.fatal_error) {
                showAuditNotice(errEl, data.message || data.fatal_error, 'error');
              } else {
                showAuditNotice(errEl, '', 'warning');
              }
            }
            return;
          }

          if (!payload.res.ok) {
            throw new Error(restErrorMessage(payload));
          }

          if (summaryEl && data.providers) {
            summaryEl.innerHTML = renderAuditSummary(data);
            summaryEl.hidden = false;
          }
          if (outEl) {
            outEl.textContent = JSON.stringify(data, null, 2);
            outEl.hidden = false;
          }
        })
        .catch(function (err) {
          if (errEl) {
            errEl.textContent = err.message || ((cfg.i18n && cfg.i18n.auditFailed) || 'Request failed.');
            errEl.hidden = false;
          } else {
            window.alert(err.message || ((cfg.i18n && cfg.i18n.auditFailed) || 'Request failed.'));
          }
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = label;
        });
    });
  }

  bindAdminRestButton(document.getElementById('pdx-run-integration-audit'), {
    endpoint: '/platform/integration-audit',
    runningLabel: (cfg.i18n && cfg.i18n.auditRunning) || 'Running live audit…',
    errorEl: document.getElementById('pdx-integration-audit-error'),
    outputEl: document.getElementById('pdx-integration-audit-output'),
    summaryEl: document.getElementById('pdx-integration-audit-summary')
  });

  bindAdminRestButton(document.getElementById('pdx-platform-stats-json'), {
    endpoint: '/platform/stats',
    runningLabel: (cfg.i18n && cfg.i18n.statsRunning) || 'Loading…',
    errorEl: null,
    outputEl: (function () {
      var pre = document.createElement('pre');
      pre.className = 'pdx-audit-json';
      pre.hidden = true;
      var host = document.getElementById('pdx-platform-stats-json');
      if (host && host.parentNode) host.parentNode.appendChild(pre);
      return pre;
    })(),
    summaryEl: null
  });
})();
