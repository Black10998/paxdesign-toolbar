/**
 * PaxDesign Utility Dock — v3.0.0
 * Enterprise AI/Cyber SaaS dock with deep module UX, job queues,
 * workspaces, AI memory, investigation boards, and real-time systems.
 */
(function () {
  'use strict';

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /* ── State ──────────────────────────────────────────────── */
  var state = {
    activeModule: null,
    accessStatus: {},
    jobs: {},
    workspaces: [],
    chatHistory: [],
    aiMemory: {},
    notifications: [],
    scanHistory: {},
    connectorResults: {},
    pipelineTrace: [],
    builderOutputs: [],
  };

  function init() {
    if (typeof PDX_CONFIG === 'undefined') return;
    var C = PDX_CONFIG;

    var dock = document.getElementById('pdx-dock');
    if (!dock) return;

    /* ── Panel + backdrop ─────────────────────────────────── */
    var backdrop = document.createElement('div');
    backdrop.id = 'pdx-backdrop';
    document.body.appendChild(backdrop);

    var panel = document.createElement('aside');
    panel.id = 'pdx-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'Tool panel');
    var inner = document.createElement('div');
    inner.id = 'pdx-panel-inner';
    panel.appendChild(inner);
    document.body.appendChild(panel);

    /* ── Notification container ───────────────────────────── */
    var notifContainer = document.createElement('div');
    notifContainer.id = 'pdx-notif';
    document.body.appendChild(notifContainer);

    /* ── Position panel ───────────────────────────────────── */
    var pos = C.position || 'left';
    panel.style[pos === 'left' ? 'left' : 'right'] = '0';
    panel.style[pos === 'left' ? 'right' : 'left'] = 'auto';
    panel.style.borderRadius = pos === 'left' ? '0 12px 12px 0' : '12px 0 0 12px';

    /* ── Open/close ───────────────────────────────────────── */
    function openPanel(moduleId) {
      state.activeModule = moduleId;
      backdrop.classList.add('is-open');
      panel.classList.add('is-open');
      document.body.classList.add('pdx-no-scroll');
      dock.querySelectorAll('.pdx-btn').forEach(function(b) {
        b.classList.toggle('is-active', b.dataset.module === moduleId);
        b.setAttribute('aria-expanded', b.dataset.module === moduleId ? 'true' : 'false');
      });
      renderPanel(moduleId);
      if (C.analytics) logEvent(moduleId, 'panel_open');
    }

    function closePanel() {
      state.activeModule = null;
      backdrop.classList.remove('is-open');
      panel.classList.remove('is-open');
      document.body.classList.remove('pdx-no-scroll');
      dock.querySelectorAll('.pdx-btn').forEach(function(b) {
        b.classList.remove('is-active');
        b.setAttribute('aria-expanded', 'false');
      });
    }

    backdrop.addEventListener('click', closePanel);

    /* ── Dock button clicks ───────────────────────────────── */
    dock.addEventListener('click', function(e) {
      var btn = e.target.closest('.pdx-btn[data-module]');
      if (!btn) return;
      var mid = btn.dataset.module;
      if (state.activeModule === mid) { closePanel(); return; }
      openPanel(mid);
    });

    /* ── Keyboard ─────────────────────────────────────────── */
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && state.activeModule) closePanel();
    });

    /* ── Load access status ───────────────────────────────── */
    apiFetch('GET', '/pay/status').then(function(data) {
      if (data) state.accessStatus = data;
    });

    /* ── Load AI memory ───────────────────────────────────── */
    if (C.aiMemory) {
      apiFetch('GET', '/ai/memory?module=global').then(function(data) {
        if (data && data.memory) state.aiMemory = data.memory;
      });
    }

    /* ── Mobile ───────────────────────────────────────────── */
    if (C.mobileEnabled) setupMobile(C, panel, dock);

    /* ── Panel renderer ───────────────────────────────────── */
    function renderPanel(moduleId) {
      var mod = C.modules[moduleId];
      if (!mod) { inner.innerHTML = '<div class="pdx-empty">Module not found.</div>'; return; }
      var access = state.accessStatus[moduleId] || {};
      var locked = access.status === 'locked';

      switch (moduleId) {
        case 'trust':      renderTrust(mod, access); break;
        case 'osint':      renderOsint(mod, access, locked); break;
        case 'threat':     renderThreat(mod, access, locked); break;
        case 'personas':   renderPersonas(mod, access, locked); break;
        case 'builder':    renderBuilder(mod, access, locked); break;
        case 'pipeline':   renderPipeline(mod, access, locked); break;
        case 'automation': renderAutomation(mod, access, locked); break;
        case 'connectors': renderConnectors(mod, access, locked); break;
        case 'create':     renderCreate(mod); break;
        case 'workspace':  renderWorkspace(mod); break;
        default:           inner.innerHTML = '<div class="pdx-empty">Coming soon.</div>';
      }
    }


    /* ══════════════════════════════════════════════════════
       TRUST CHECK
    ══════════════════════════════════════════════════════ */
    function renderTrust(mod, access) {
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + svgIcon('shield') + '<span>TrustCheck</span></div>' +
            '<div class="pdx-ph-sub">Domain intelligence & risk scoring</div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-input-row">' +
              '<input id="pdx-trust-input" class="pdx-input" type="text" placeholder="domain.com" autocomplete="off" spellcheck="false"/>' +
              '<button id="pdx-trust-btn" class="pdx-btn-primary">Scan</button>' +
            '</div>' +
            '<div id="pdx-trust-result"></div>' +
            '<div id="pdx-trust-history" class="pdx-section-sm"></div>' +
          '</div>' +
        '</div>';

      renderScanHistory('trust');

      document.getElementById('pdx-trust-btn').addEventListener('click', runTrustScan);
      document.getElementById('pdx-trust-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') runTrustScan();
      });
    }

    function runTrustScan() {
      var input = document.getElementById('pdx-trust-input');
      var result = document.getElementById('pdx-trust-result');
      if (!input || !result) return;
      var domain = input.value.trim().replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
      if (!domain) return;

      result.innerHTML = '<div class="pdx-scanning">' + scanStages(['Querying RDAP registry', 'Analyzing SSL/TLS', 'Computing risk score', 'Detecting anomalies', 'Building report']) + '</div>';
      animateScanStages(result.querySelector('.pdx-stages'), 800);

      apiFetch('GET', '/trust?domain=' + encodeURIComponent(domain)).then(function(data) {
        if (!data) { result.innerHTML = '<div class="pdx-error">Scan failed. Check the domain and try again.</div>'; return; }
        renderTrustResult(result, data, domain);
        addToScanHistory('trust', domain, data.risk);
        renderScanHistory('trust');
        if (data.workspace_id) showNotif('Scan saved to workspace', 'info');
        if (data.anomalies && data.anomalies.length) showNotif('Anomaly detected: ' + data.anomalies[0].message, 'warn');
      });
    }

    function renderTrustResult(container, data, domain) {
      var risk = data.risk || {};
      var score = risk.score || 0;
      var verdict = risk.verdict || 'unknown';
      var rdap = data.sources && data.sources.rdap || {};
      var ssl  = data.sources && data.sources.ssl  || {};
      var anomalies = data.anomalies || [];
      var behavioral = data.behavioral || [];

      var scoreColor = verdict === 'clean' ? 'var(--pdx-green)' : verdict === 'low' ? 'var(--pdx-green)' : verdict === 'medium' ? 'var(--pdx-yellow)' : 'var(--pdx-red)';

      var html = '<div class="pdx-result">';

      // Risk header
      html += '<div class="pdx-risk-header">' +
        '<div class="pdx-risk-score" style="--score-color:' + scoreColor + '">' +
          '<div class="pdx-risk-num">' + score + '</div>' +
          '<div class="pdx-risk-label">' + (risk.label || 'Unknown') + '</div>' +
        '</div>' +
        '<div class="pdx-risk-meta">' +
          '<div class="pdx-risk-domain">' + escHtml(domain) + '</div>' +
          '<div class="pdx-risk-scan-id">Scan ID: ' + escHtml(data.scan_id || '') + '</div>' +
          '<div class="pdx-risk-time">' + (data.duration ? data.duration + 's' : '') + '</div>' +
        '</div>' +
        '<button class="pdx-btn-ghost pdx-export-btn" data-export="trust">Export</button>' +
      '</div>';

      // Risk factors
      if (risk.factors && risk.factors.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Risk Factors</div><div class="pdx-factors">';
        risk.factors.forEach(function(f) {
          var cls = f.weight === 'critical' ? 'pdx-factor--critical' : f.weight === 'high' ? 'pdx-factor--high' : f.weight === 'medium' ? 'pdx-factor--medium' : 'pdx-factor--low';
          html += '<div class="pdx-factor ' + cls + '"><span class="pdx-factor-name">' + escHtml(f.factor) + '</span><span class="pdx-factor-val">' + escHtml(String(f.value)) + '</span><span class="pdx-factor-risk">+' + f.risk + '</span></div>';
        });
        html += '</div></div>';
      }

      // RDAP
      if (rdap.registrar) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Registration</div><div class="pdx-kv-grid">';
        html += kvRow('Registrar', rdap.registrar);
        html += kvRow('Registered', rdap.registered || 'Unknown');
        html += kvRow('Expires', rdap.expires || 'Unknown');
        html += kvRow('Age', rdap.age_days ? rdap.age_days + ' days' : 'Unknown');
        if (rdap.nameservers && rdap.nameservers.length) html += kvRow('Nameservers', rdap.nameservers.slice(0,3).join(', '));
        html += '</div></div>';
      }

      // SSL
      if (ssl.grade) {
        var gradeClass = ssl.grade === 'A+' || ssl.grade === 'A' ? 'pdx-grade--good' : ssl.grade === 'B' ? 'pdx-grade--warn' : 'pdx-grade--bad';
        html += '<div class="pdx-section"><div class="pdx-section-title">SSL / TLS</div><div class="pdx-kv-grid">';
        html += '<div class="pdx-kv-row"><span class="pdx-kv-key">Grade</span><span class="pdx-ssl-grade ' + gradeClass + '">' + escHtml(ssl.grade) + '</span></div>';
        html += kvRow('Status', ssl.status || 'Unknown');
        if (ssl.endpoints && ssl.endpoints.length) html += kvRow('IP', ssl.endpoints[0].ip || 'N/A');
        html += '</div></div>';
      }

      // Anomalies
      if (anomalies.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">Anomalies Detected</div>';
        anomalies.forEach(function(a) {
          html += '<div class="pdx-anomaly"><span class="pdx-anomaly-icon">' + svgIcon('alert') + '</span><span>' + escHtml(a.message) + '</span></div>';
        });
        html += '</div>';
      }

      // Behavioral signals
      if (behavioral.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Behavioral Signals</div>';
        behavioral.forEach(function(s) {
          var cls = s.type === 'positive' ? 'pdx-signal--pos' : s.type === 'negative' ? 'pdx-signal--neg' : 'pdx-signal--neutral';
          html += '<div class="pdx-signal ' + cls + '">' + escHtml(s.signal) + '</div>';
        });
        html += '</div>';
      }

      html += '</div>';
      container.innerHTML = html;

      container.querySelector('.pdx-export-btn') && container.querySelector('.pdx-export-btn').addEventListener('click', function() {
        exportJSON('trust-' + domain, data);
      });
    }


    /* ══════════════════════════════════════════════════════
       OSINT AGENTS
    ══════════════════════════════════════════════════════ */
    function renderOsint(mod, access, locked) {
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + svgIcon('search') + '<span>OSINT Agents</span>' + (mod.badge ? '<span class="pdx-badge">' + mod.badge + '</span>' : '') + '</div>' +
            '<div class="pdx-ph-sub">Multi-source intelligence scan</div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-input-row">' +
              '<input id="pdx-osint-input" class="pdx-input" type="text" placeholder="domain.com or IP" autocomplete="off" spellcheck="false"/>' +
              '<button id="pdx-osint-btn" class="pdx-btn-primary">Scan</button>' +
            '</div>' +
            '<div id="pdx-osint-result"></div>' +
          '</div>' +
        '</div>';

      document.getElementById('pdx-osint-btn').addEventListener('click', runOsintScan);
      document.getElementById('pdx-osint-input').addEventListener('keydown', function(e) { if (e.key === 'Enter') runOsintScan(); });
    }

    function runOsintScan() {
      var input = document.getElementById('pdx-osint-input');
      var result = document.getElementById('pdx-osint-result');
      if (!input || !result) return;
      var target = input.value.trim();
      if (!target) return;

      result.innerHTML = '<div class="pdx-scanning">' + scanStages(['RDAP lookup', 'SSL analysis', 'IP geolocation', 'VirusTotal scan', 'Shodan query', 'Email discovery', 'Building report']) + '</div>';
      animateScanStages(result.querySelector('.pdx-stages'), 600);

      apiFetch('POST', '/osint/scan', { target: target }).then(function(data) {
        if (!data) { result.innerHTML = '<div class="pdx-error">Scan failed.</div>'; return; }
        renderOsintResult(result, data, target);
      });
    }

    function renderOsintResult(container, data, target) {
      var risk = data.risk || {};
      var sources = data.sources || {};
      var paywall = data.paywall;
      var html = '<div class="pdx-result">';

      // Risk badge
      if (risk.score !== undefined) {
        var scoreColor = risk.verdict === 'clean' || risk.verdict === 'low' ? 'var(--pdx-green)' : risk.verdict === 'medium' ? 'var(--pdx-yellow)' : 'var(--pdx-red)';
        html += '<div class="pdx-risk-header"><div class="pdx-risk-score" style="--score-color:' + scoreColor + '"><div class="pdx-risk-num">' + risk.score + '</div><div class="pdx-risk-label">' + (risk.label || 'Unknown') + '</div></div><div class="pdx-risk-meta"><div class="pdx-risk-domain">' + escHtml(target) + '</div><div class="pdx-risk-scan-id">Scan ID: ' + escHtml(data.scan_id || '') + '</div></div>' + (data.paid ? '<button class="pdx-btn-ghost pdx-export-btn" data-export="osint">Export</button>' : '') + '</div>';
      }

      // Sources
      Object.keys(sources).forEach(function(key) {
        var src = sources[key];
        if (!src || !src.label) return;
        html += '<div class="pdx-section"><div class="pdx-section-title">' + escHtml(src.label) + '</div><div class="pdx-kv-grid">';
        Object.keys(src).forEach(function(k) {
          if (k === 'label' || k === 'free') return;
          var v = src[k];
          if (Array.isArray(v)) v = v.slice(0,5).join(', ') || 'None';
          if (v === null || v === undefined || v === '') return;
          html += kvRow(k.replace(/_/g,' '), String(v));
        });
        html += '</div></div>';
      });

      // Paywall
      if (paywall) {
        html += '<div class="pdx-paywall">' +
          '<div class="pdx-paywall-icon">' + svgIcon('shield') + '</div>' +
          '<div class="pdx-paywall-title">Full Intelligence Report</div>' +
          '<div class="pdx-paywall-desc">' + escHtml(paywall.message) + '</div>' +
          '<div class="pdx-paywall-locked"><strong>Locked sources:</strong> ' + (paywall.locked_sources || []).join(', ') + '</div>' +
          '<button class="pdx-btn-primary pdx-unlock-btn" data-module="osint" data-price="' + paywall.price + '" data-currency="' + paywall.currency + '">Unlock for ' + paywall.currency + ' ' + paywall.price + '</button>' +
        '</div>';
      }

      // Anomalies
      if (data.anomalies && data.anomalies.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">Anomalies</div>';
        data.anomalies.forEach(function(a) { html += '<div class="pdx-anomaly">' + svgIcon('alert') + '<span>' + escHtml(a.message) + '</span></div>'; });
        html += '</div>';
      }

      html += '</div>';
      container.innerHTML = html;

      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('osint-' + target, data); });

      var unlockBtn = container.querySelector('.pdx-unlock-btn');
      if (unlockBtn) unlockBtn.addEventListener('click', function() { initiatePayment('osint', parseFloat(unlockBtn.dataset.price), unlockBtn.dataset.currency); });
    }


    /* ══════════════════════════════════════════════════════
       THREAT INTEL
    ══════════════════════════════════════════════════════ */
    function renderThreat(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('alert') + '<span>Threat Intel</span><span class="pdx-badge pdx-badge--new">New</span></div><div class="pdx-ph-sub">CVE lookup, threat feeds, attack surface</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-tabs" id="pdx-threat-tabs">' +
              '<button class="pdx-tab is-active" data-tab="cve">CVE Lookup</button>' +
              '<button class="pdx-tab" data-tab="feeds">Threat Feeds</button>' +
              '<button class="pdx-tab" data-tab="surface">Attack Surface</button>' +
            '</div>' +
            '<div id="pdx-threat-content">' +
              renderThreatCVETab() +
            '</div>' +
          '</div>' +
        '</div>';

      setupTabs('pdx-threat-tabs', 'pdx-threat-content', {
        cve: renderThreatCVETab,
        feeds: renderThreatFeedsTab,
        surface: renderThreatSurfaceTab,
      });
    }

    function renderThreatCVETab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-input-row"><input id="pdx-cve-input" class="pdx-input" placeholder="CVE-2024-XXXX or software name" /><button id="pdx-cve-btn" class="pdx-btn-primary">Lookup</button></div>' +
        '<div id="pdx-cve-result"></div>' +
        '<div class="pdx-info-box">Search the NVD database for CVEs by ID or affected software. Results include CVSS scores, affected versions, and remediation guidance.</div>' +
      '</div>';
    }

    function renderThreatFeedsTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-section-title">Active Threat Feeds</div>' +
        '<div class="pdx-feed-list">' +
          feedItem('AlienVault OTX', 'Indicators of compromise', 'active') +
          feedItem('Abuse.ch URLhaus', 'Malicious URLs', 'active') +
          feedItem('Emerging Threats', 'Network signatures', 'active') +
          feedItem('PhishTank', 'Phishing URLs', 'active') +
          feedItem('CISA KEV', 'Known exploited vulnerabilities', 'active') +
        '</div>' +
        '<div class="pdx-info-box">Configure API keys in admin settings to enable live feed data.</div>' +
      '</div>';
    }

    function renderThreatSurfaceTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-input-row"><input id="pdx-surface-input" class="pdx-input" placeholder="domain.com" /><button id="pdx-surface-btn" class="pdx-btn-primary">Map</button></div>' +
        '<div id="pdx-surface-result"></div>' +
        '<div class="pdx-info-box">Maps exposed services, open ports, and subdomains using Shodan data. Requires Shodan API key.</div>' +
      '</div>';
    }

    function feedItem(name, desc, status) {
      return '<div class="pdx-feed-item"><div class="pdx-feed-dot pdx-feed-dot--' + status + '"></div><div class="pdx-feed-info"><div class="pdx-feed-name">' + escHtml(name) + '</div><div class="pdx-feed-desc">' + escHtml(desc) + '</div></div></div>';
    }

    /* ══════════════════════════════════════════════════════
       AI PERSONAS
    ══════════════════════════════════════════════════════ */
    function renderPersonas(mod, access, locked) {
      var previewUsed = 0;
      var previewMax  = mod.preview_lines || 3;

      inner.innerHTML =
        '<div class="pdx-ph pdx-ph--chat">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + svgIcon('user') + '<span>AI Personas</span>' + (mod.badge ? '<span class="pdx-badge">' + mod.badge + '</span>' : '') + '</div>' +
            '<div class="pdx-persona-select">' +
              '<button class="pdx-persona-btn is-active" data-persona="assistant">Assistant</button>' +
              '<button class="pdx-persona-btn" data-persona="analyst">Analyst</button>' +
              '<button class="pdx-persona-btn" data-persona="developer">Developer</button>' +
              '<button class="pdx-persona-btn" data-persona="strategist">Strategist</button>' +
            '</div>' +
          '</div>' +
          '<div id="pdx-chat-messages" class="pdx-chat-messages"></div>' +
          '<div class="pdx-chat-footer">' +
            (locked && previewUsed >= previewMax ? renderPaywallInline(mod, access) :
              '<div class="pdx-chat-input-row">' +
                '<textarea id="pdx-chat-input" class="pdx-chat-input" placeholder="Ask anything..." rows="2"></textarea>' +
                '<div class="pdx-chat-actions">' +
                  '<button id="pdx-chat-send" class="pdx-btn-primary">Send</button>' +
                  '<button id="pdx-chat-clear" class="pdx-btn-ghost" title="Clear">Clear</button>' +
                  '<button id="pdx-chat-export" class="pdx-btn-ghost" title="Export">Export</button>' +
                '</div>' +
              '</div>'
            ) +
          '</div>' +
        '</div>';

      var currentPersona = 'assistant';
      var messages = state.chatHistory.slice();

      // Render existing history
      var msgContainer = document.getElementById('pdx-chat-messages');
      if (messages.length === 0) {
        appendChatMsg(msgContainer, 'assistant', getPersonaGreeting(currentPersona));
      } else {
        messages.forEach(function(m) { appendChatMsg(msgContainer, m.role, m.content); });
      }

      // Persona switcher
      inner.querySelectorAll('.pdx-persona-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          inner.querySelectorAll('.pdx-persona-btn').forEach(function(b) { b.classList.remove('is-active'); });
          btn.classList.add('is-active');
          currentPersona = btn.dataset.persona;
          appendChatMsg(msgContainer, 'system', 'Switched to ' + btn.textContent + ' persona.');
        });
      });

      var sendBtn = document.getElementById('pdx-chat-send');
      var clearBtn = document.getElementById('pdx-chat-clear');
      var exportBtn = document.getElementById('pdx-chat-export');

      if (sendBtn) sendBtn.addEventListener('click', sendChat);
      if (clearBtn) clearBtn.addEventListener('click', function() {
        state.chatHistory = [];
        msgContainer.innerHTML = '';
        appendChatMsg(msgContainer, 'assistant', getPersonaGreeting(currentPersona));
      });
      if (exportBtn) exportBtn.addEventListener('click', function() {
        exportJSON('chat-history', { persona: currentPersona, messages: state.chatHistory });
      });

      function sendChat() {
        var input = document.getElementById('pdx-chat-input');
        if (!input) return;
        var msg = input.value.trim();
        if (!msg) return;
        input.value = '';
        appendChatMsg(msgContainer, 'user', msg);
        state.chatHistory.push({ role: 'user', content: msg });

        var thinking = appendChatMsg(msgContainer, 'assistant', '...', true);

        apiFetch('POST', '/ai/chat', { module_id: 'personas', message: msg, persona: currentPersona }).then(function(data) {
          thinking.remove();
          if (!data) { appendChatMsg(msgContainer, 'error', 'Request failed.'); return; }
          if (data.error === 'payment_required') {
            appendChatMsg(msgContainer, 'system', 'Preview limit reached. Unlock full access to continue.');
            var pw = document.createElement('div');
            pw.innerHTML = renderPaywallInline(mod, { price: data.price, currency: data.currency });
            msgContainer.appendChild(pw);
            return;
          }
          if (data.error) { appendChatMsg(msgContainer, 'error', data.error); return; }
          appendChatMsg(msgContainer, 'assistant', data.reply);
          state.chatHistory.push({ role: 'assistant', content: data.reply });
          // Store in AI memory
          if (C.aiMemory) {
            apiFetch('POST', '/ai/memory', { key: 'last_chat_' + currentPersona, value: { user: msg, reply: data.reply }, module: 'personas' });
          }
        });
      }

      var chatInput = document.getElementById('pdx-chat-input');
      if (chatInput) chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) sendChat();
      });
    }

    function getPersonaGreeting(persona) {
      var greetings = {
        assistant:  'Hello! I\'m your AI assistant. How can I help you today?',
        analyst:    'Ready to analyze. Provide your data or question and I\'ll deliver structured insights.',
        developer:  'Developer mode active. Ask me about code, architecture, or technical problems.',
        strategist: 'Strategic advisor online. What business challenge can I help you frame?',
      };
      return greetings[persona] || greetings.assistant;
    }

    function appendChatMsg(container, role, content, isThinking) {
      var div = document.createElement('div');
      div.className = 'pdx-chat-msg pdx-chat-msg--' + role + (isThinking ? ' pdx-chat-msg--thinking' : '');
      div.innerHTML = '<div class="pdx-chat-bubble">' + (isThinking ? '<span class="pdx-dots"><span></span><span></span><span></span></span>' : escHtml(content).replace(/\n/g, '<br>')) + '</div>';
      container.appendChild(div);
      container.scrollTop = container.scrollHeight;
      return div;
    }


    /* ══════════════════════════════════════════════════════
       AI BUILDER
    ══════════════════════════════════════════════════════ */
    function renderBuilder(mod, access, locked) {
      if (locked && mod.tier === 'paid') { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('layers') + '<span>AI Builder</span>' + (mod.badge ? '<span class="pdx-badge">' + mod.badge + '</span>' : '') + '</div><div class="pdx-ph-sub">Visual AI workflow builder</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-tabs" id="pdx-builder-tabs">' +
              '<button class="pdx-tab is-active" data-tab="build">Build</button>' +
              '<button class="pdx-tab" data-tab="templates">Templates</button>' +
              '<button class="pdx-tab" data-tab="history">History</button>' +
            '</div>' +
            '<div id="pdx-builder-content"><div id="pdx-builder-build">' + renderBuilderBuildTab() + '</div></div>' +
          '</div>' +
        '</div>';

      setupTabs('pdx-builder-tabs', 'pdx-builder-content', {
        build: function() { return '<div id="pdx-builder-build">' + renderBuilderBuildTab() + '</div>'; },
        templates: renderBuilderTemplatesTab,
        history: function() { return renderJobHistory('builder'); },
      });

      bindBuilderBuild();
    }

    function renderBuilderBuildTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-field"><label class="pdx-label">Flow Name</label><input id="pdx-builder-name" class="pdx-input" value="My Flow" /></div>' +
        '<div class="pdx-field"><label class="pdx-label">Input</label><textarea id="pdx-builder-input" class="pdx-textarea" placeholder="Paste your input text here..." rows="3"></textarea></div>' +
        '<div class="pdx-field"><label class="pdx-label">Steps</label><div id="pdx-builder-steps"><div class="pdx-step" data-idx="0">' + renderStepRow(0, 'llm', 'Summarize the following:') + '</div></div>' +
          '<button id="pdx-add-step" class="pdx-btn-ghost pdx-btn-sm">+ Add Step</button>' +
        '</div>' +
        '<button id="pdx-builder-run" class="pdx-btn-primary pdx-btn-full">Run Flow</button>' +
        '<div id="pdx-builder-result"></div>' +
      '</div>';
    }

    function renderStepRow(idx, type, prompt) {
      return '<div class="pdx-step-inner">' +
        '<select class="pdx-select pdx-step-type" data-idx="' + idx + '">' +
          '<option value="llm"' + (type === 'llm' ? ' selected' : '') + '>LLM</option>' +
          '<option value="transform"' + (type === 'transform' ? ' selected' : '') + '>Transform</option>' +
        '</select>' +
        '<input class="pdx-input pdx-step-prompt" data-idx="' + idx + '" value="' + escHtml(prompt) + '" placeholder="Step prompt..." />' +
        '<button class="pdx-btn-ghost pdx-btn-icon pdx-remove-step" data-idx="' + idx + '">×</button>' +
      '</div>';
    }

    function renderBuilderTemplatesTab() {
      return '<div class="pdx-tab-pane" id="pdx-builder-tpl-pane"><div class="pdx-loading">Loading templates...</div></div>';
    }

    function bindBuilderBuild() {
      var stepsContainer = document.getElementById('pdx-builder-steps');
      var addBtn = document.getElementById('pdx-add-step');
      var runBtn = document.getElementById('pdx-builder-run');
      if (!stepsContainer || !addBtn || !runBtn) return;

      var stepCount = 1;
      addBtn.addEventListener('click', function() {
        var div = document.createElement('div');
        div.className = 'pdx-step';
        div.dataset.idx = stepCount;
        div.innerHTML = renderStepRow(stepCount, 'llm', '');
        stepsContainer.appendChild(div);
        stepCount++;
      });

      stepsContainer.addEventListener('click', function(e) {
        var rm = e.target.closest('.pdx-remove-step');
        if (rm && stepsContainer.querySelectorAll('.pdx-step').length > 1) rm.closest('.pdx-step').remove();
      });

      runBtn.addEventListener('click', function() {
        var name  = (document.getElementById('pdx-builder-name') || {}).value || 'My Flow';
        var input = (document.getElementById('pdx-builder-input') || {}).value || '';
        var steps = [];
        stepsContainer.querySelectorAll('.pdx-step').forEach(function(s) {
          steps.push({ type: s.querySelector('.pdx-step-type').value, prompt: s.querySelector('.pdx-step-prompt').value });
        });
        var result = document.getElementById('pdx-builder-result');
        result.innerHTML = '<div class="pdx-scanning">' + scanStages(['Initializing flow', 'Running step 1', 'Processing output', 'Finalizing']) + '</div>';
        animateScanStages(result.querySelector('.pdx-stages'), 700);

        apiFetch('POST', '/builder/run', { flow_name: name, steps: steps, input: input }).then(function(data) {
          if (!data) { result.innerHTML = '<div class="pdx-error">Flow failed.</div>'; return; }
          if (data.error === 'payment_required') { result.innerHTML = ''; renderPaywall({ label: 'AI Builder', price: data.price, currency: data.currency }, {}); return; }
          renderBuilderResult(result, data);
          showNotif('Flow "' + name + '" completed', 'success');
        });
      });
    }

    function renderBuilderResult(container, data) {
      var r = data.result || {};
      var html = '<div class="pdx-result"><div class="pdx-result-header"><span>' + escHtml(data.flow_name || 'Flow') + '</span><span class="pdx-badge">' + (r.steps_executed || 0) + ' steps</span><button class="pdx-btn-ghost pdx-export-btn">Export</button></div>';
      (r.outputs || []).forEach(function(o) {
        html += '<div class="pdx-step-result"><div class="pdx-step-result-hd">Step ' + o.step + ' <span class="pdx-tag">' + escHtml(o.type) + '</span></div><div class="pdx-step-result-body">' + escHtml(o.output || '').replace(/\n/g,'<br>') + '</div></div>';
      });
      html += '</div>';
      container.innerHTML = html;
      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('builder-' + (data.flow_name || 'flow'), data); });
    }


    /* ══════════════════════════════════════════════════════
       AGENT PIPELINE
    ══════════════════════════════════════════════════════ */
    function renderPipeline(mod, access, locked) {
      if (locked && mod.tier === 'paid') { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('pipeline') + '<span>Agent Pipeline</span></div><div class="pdx-ph-sub">Multi-agent orchestration</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-tabs" id="pdx-pipeline-tabs">' +
              '<button class="pdx-tab is-active" data-tab="run">Run</button>' +
              '<button class="pdx-tab" data-tab="templates">Templates</button>' +
              '<button class="pdx-tab" data-tab="trace">Trace</button>' +
            '</div>' +
            '<div id="pdx-pipeline-content"><div>' + renderPipelineRunTab() + '</div></div>' +
          '</div>' +
        '</div>';

      setupTabs('pdx-pipeline-tabs', 'pdx-pipeline-content', {
        run: function() { return '<div>' + renderPipelineRunTab() + '</div>'; },
        templates: renderPipelineTemplatesTab,
        trace: function() { return renderPipelineTrace(); },
      });

      bindPipelineRun();
    }

    function renderPipelineRunTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-field"><label class="pdx-label">Pipeline Name</label><input id="pdx-pipeline-name" class="pdx-input" value="My Pipeline" /></div>' +
        '<div class="pdx-field"><label class="pdx-label">Objective</label><textarea id="pdx-pipeline-objective" class="pdx-textarea" placeholder="Describe what the pipeline should accomplish..." rows="3"></textarea></div>' +
        '<div class="pdx-field"><label class="pdx-label">Agents</label><div id="pdx-pipeline-agents">' +
          renderAgentRow(0, 'researcher', 'Researcher') +
          renderAgentRow(1, 'analyst', 'Analyst') +
          renderAgentRow(2, 'writer', 'Writer') +
        '</div><button id="pdx-add-agent" class="pdx-btn-ghost pdx-btn-sm">+ Add Agent</button></div>' +
        '<button id="pdx-pipeline-run" class="pdx-btn-primary pdx-btn-full">Run Pipeline</button>' +
        '<div id="pdx-pipeline-result"></div>' +
      '</div>';
    }

    function renderAgentRow(idx, role, name) {
      return '<div class="pdx-agent-row" data-idx="' + idx + '">' +
        '<select class="pdx-select pdx-agent-role" data-idx="' + idx + '">' +
          ['researcher','analyst','writer','critic','coordinator'].map(function(r) {
            return '<option value="' + r + '"' + (r === role ? ' selected' : '') + '>' + r.charAt(0).toUpperCase() + r.slice(1) + '</option>';
          }).join('') +
        '</select>' +
        '<input class="pdx-input pdx-agent-name" data-idx="' + idx + '" value="' + escHtml(name) + '" placeholder="Agent name" />' +
        '<button class="pdx-btn-ghost pdx-btn-icon pdx-remove-agent" data-idx="' + idx + '">×</button>' +
      '</div>';
    }

    function renderPipelineTemplatesTab() {
      return '<div class="pdx-tab-pane" id="pdx-pipeline-tpl-pane"><div class="pdx-loading">Loading templates...</div></div>';
    }

    function renderPipelineTrace() {
      if (!state.pipelineTrace.length) return '<div class="pdx-tab-pane"><div class="pdx-empty">No pipeline runs yet.</div></div>';
      var html = '<div class="pdx-tab-pane">';
      state.pipelineTrace.forEach(function(t) {
        html += '<div class="pdx-trace-item"><div class="pdx-trace-agent">' + svgIcon('user') + escHtml(t.name || t.agent) + '</div><div class="pdx-trace-output">' + escHtml(t.output || '').replace(/\n/g,'<br>') + '</div></div>';
      });
      html += '</div>';
      return html;
    }

    function bindPipelineRun() {
      var agentsContainer = document.getElementById('pdx-pipeline-agents');
      var addBtn = document.getElementById('pdx-add-agent');
      var runBtn = document.getElementById('pdx-pipeline-run');
      if (!agentsContainer || !addBtn || !runBtn) return;

      var agentCount = 3;
      addBtn.addEventListener('click', function() {
        var div = document.createElement('div');
        div.innerHTML = renderAgentRow(agentCount, 'coordinator', 'Agent ' + (agentCount + 1));
        agentsContainer.appendChild(div.firstChild);
        agentCount++;
      });

      agentsContainer.addEventListener('click', function(e) {
        var rm = e.target.closest('.pdx-remove-agent');
        if (rm && agentsContainer.querySelectorAll('.pdx-agent-row').length > 1) rm.closest('.pdx-agent-row').remove();
      });

      runBtn.addEventListener('click', function() {
        var name      = (document.getElementById('pdx-pipeline-name') || {}).value || 'Pipeline';
        var objective = (document.getElementById('pdx-pipeline-objective') || {}).value || '';
        if (!objective) { showNotif('Objective required', 'warn'); return; }
        var agents = [];
        agentsContainer.querySelectorAll('.pdx-agent-row').forEach(function(row) {
          agents.push({ role: row.querySelector('.pdx-agent-role').value, name: row.querySelector('.pdx-agent-name').value });
        });
        var result = document.getElementById('pdx-pipeline-result');
        result.innerHTML = '<div class="pdx-scanning">' + scanStages(agents.map(function(a) { return 'Running ' + a.name; })) + '</div>';
        animateScanStages(result.querySelector('.pdx-stages'), 900);

        apiFetch('POST', '/pipeline/run', { pipeline_name: name, agents: agents, objective: objective }).then(function(data) {
          if (!data) { result.innerHTML = '<div class="pdx-error">Pipeline failed.</div>'; return; }
          if (data.error === 'payment_required') { result.innerHTML = ''; renderPaywall({ label: 'Agent Pipeline', price: data.price, currency: data.currency }, {}); return; }
          state.pipelineTrace = (data.result && data.result.trace) || [];
          renderPipelineResult(result, data);
          showNotif('Pipeline "' + name + '" completed — ' + agents.length + ' agents', 'success');
        });
      });
    }

    function renderPipelineResult(container, data) {
      var r = data.result || {};
      var html = '<div class="pdx-result"><div class="pdx-result-header"><span>' + escHtml(data.pipeline_name || 'Pipeline') + '</span><span class="pdx-badge">' + (r.agents_run || 0) + ' agents</span><button class="pdx-btn-ghost pdx-export-btn">Export</button></div>';
      (r.trace || []).forEach(function(t) {
        html += '<div class="pdx-trace-item"><div class="pdx-trace-agent">' + svgIcon('user') + '<strong>' + escHtml(t.name || t.agent) + '</strong></div><div class="pdx-trace-output">' + escHtml(t.output || '').replace(/\n/g,'<br>') + '</div></div>';
      });
      if (r.final_output) html += '<div class="pdx-final-output"><div class="pdx-section-title">Final Output</div><div class="pdx-output-body">' + escHtml(r.final_output).replace(/\n/g,'<br>') + '</div></div>';
      html += '</div>';
      container.innerHTML = html;
      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('pipeline-' + (data.pipeline_name || 'run'), data); });
    }


    /* ══════════════════════════════════════════════════════
       BROWSER AUTOMATION
    ══════════════════════════════════════════════════════ */
    function renderAutomation(mod, access, locked) {
      if (locked && mod.tier === 'paid') { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('grid') + '<span>Browser Automation</span></div><div class="pdx-ph-sub">AI-assisted task analysis</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-field"><label class="pdx-label">Target URL</label><input id="pdx-auto-url" class="pdx-input" type="url" placeholder="https://example.com" /></div>' +
            '<div class="pdx-field"><label class="pdx-label">Task Description</label><textarea id="pdx-auto-task" class="pdx-textarea" placeholder="Describe what to automate: extract product prices, fill a form, scrape headlines..." rows="4"></textarea></div>' +
            '<div class="pdx-field-row">' +
              '<div class="pdx-field"><label class="pdx-label">Output Format</label><select id="pdx-auto-format" class="pdx-select"><option value="json">JSON</option><option value="csv">CSV</option><option value="markdown">Markdown</option></select></div>' +
            '</div>' +
            '<button id="pdx-auto-submit" class="pdx-btn-primary pdx-btn-full">Analyze Task</button>' +
            '<div id="pdx-auto-result"></div>' +
            '<div id="pdx-auto-jobs" class="pdx-section-sm"></div>' +
          '</div>' +
        '</div>';

      loadJobHistory('automation', 'pdx-auto-jobs');

      document.getElementById('pdx-auto-submit').addEventListener('click', function() {
        var url    = (document.getElementById('pdx-auto-url') || {}).value || '';
        var task   = (document.getElementById('pdx-auto-task') || {}).value || '';
        var format = (document.getElementById('pdx-auto-format') || {}).value || 'json';
        var result = document.getElementById('pdx-auto-result');
        if (!url || !task) { showNotif('URL and task required', 'warn'); return; }

        result.innerHTML = '<div class="pdx-scanning">' + scanStages(['Parsing URL', 'Analyzing task', 'Identifying selectors', 'Building execution plan', 'Estimating complexity']) + '</div>';
        animateScanStages(result.querySelector('.pdx-stages'), 800);

        apiFetch('POST', '/automation/submit', { url: url, task: task, format: format }).then(function(data) {
          if (!data) { result.innerHTML = '<div class="pdx-error">Analysis failed.</div>'; return; }
          if (data.error === 'payment_required') { result.innerHTML = ''; renderPaywall({ label: 'Browser Automation', price: data.price, currency: data.currency }, {}); return; }
          renderAutomationResult(result, data);
          loadJobHistory('automation', 'pdx-auto-jobs');
          showNotif('Task analyzed — Job ' + (data.job_id || ''), 'success');
        });
      });
    }

    function renderAutomationResult(container, data) {
      var r = data.result || {};
      var html = '<div class="pdx-result">';
      html += '<div class="pdx-result-header"><span>Task Analysis</span><span class="pdx-tag">Job: ' + escHtml(data.job_id || '') + '</span><button class="pdx-btn-ghost pdx-export-btn">Export</button></div>';
      if (r.steps && r.steps.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Execution Steps</div><ol class="pdx-steps-list">';
        r.steps.forEach(function(s) { html += '<li>' + escHtml(String(s)) + '</li>'; });
        html += '</ol></div>';
      }
      if (r.data_points && r.data_points.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Data Extraction Points</div><ul class="pdx-list">';
        r.data_points.forEach(function(d) { html += '<li>' + escHtml(String(d)) + '</li>'; });
        html += '</ul></div>';
      }
      if (r.obstacles && r.obstacles.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">Potential Obstacles</div><ul class="pdx-list">';
        r.obstacles.forEach(function(o) { html += '<li>' + escHtml(String(o)) + '</li>'; });
        html += '</ul></div>';
      }
      if (r.approach) html += '<div class="pdx-section"><div class="pdx-section-title">Recommended Approach</div><div class="pdx-prose">' + escHtml(r.approach).replace(/\n/g,'<br>') + '</div></div>';
      if (r.estimated_seconds) html += '<div class="pdx-kv-row">' + kvRow('Estimated Time', r.estimated_seconds + 's') + '</div>';
      if (r.analysis) html += '<div class="pdx-section"><div class="pdx-section-title">Analysis</div><div class="pdx-prose">' + escHtml(r.analysis).replace(/\n/g,'<br>') + '</div></div>';
      html += '</div>';
      container.innerHTML = html;
      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('automation-task', data); });
    }

    /* ══════════════════════════════════════════════════════
       CONNECTORS
    ══════════════════════════════════════════════════════ */
    function renderConnectors(mod, access, locked) {
      if (locked && mod.tier === 'paid') { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('link') + '<span>Connectors</span></div><div class="pdx-ph-sub">API integration testing</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-tabs" id="pdx-conn-tabs">' +
              '<button class="pdx-tab is-active" data-tab="test">Test</button>' +
              '<button class="pdx-tab" data-tab="library">Library</button>' +
            '</div>' +
            '<div id="pdx-conn-content"><div>' + renderConnectorTestTab() + '</div></div>' +
          '</div>' +
        '</div>';

      setupTabs('pdx-conn-tabs', 'pdx-conn-content', {
        test: function() { return '<div>' + renderConnectorTestTab() + '</div>'; },
        library: renderConnectorLibraryTab,
      });

      bindConnectorTest();
    }

    function renderConnectorTestTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-field"><label class="pdx-label">Connector Type</label><select id="pdx-conn-type" class="pdx-select"><option value="rest_api">REST API</option><option value="webhook">Webhook</option><option value="openai">OpenAI</option><option value="slack">Slack</option><option value="airtable">Airtable</option><option value="notion">Notion</option><option value="github">GitHub</option></select></div>' +
        '<div class="pdx-field"><label class="pdx-label">Endpoint URL</label><input id="pdx-conn-endpoint" class="pdx-input" type="url" placeholder="https://api.example.com/v1/test" /></div>' +
        '<div class="pdx-field"><label class="pdx-label">Auth Token (optional)</label><input id="pdx-conn-auth" class="pdx-input" type="password" placeholder="Bearer token or API key" /></div>' +
        '<button id="pdx-conn-test" class="pdx-btn-primary pdx-btn-full">Test Connection</button>' +
        '<div id="pdx-conn-result"></div>' +
      '</div>';
    }

    function renderConnectorLibraryTab() {
      return '<div class="pdx-tab-pane" id="pdx-conn-lib"><div class="pdx-loading">Loading connectors...</div></div>';
    }

    function bindConnectorTest() {
      var testBtn = document.getElementById('pdx-conn-test');
      if (!testBtn) return;
      testBtn.addEventListener('click', function() {
        var type     = (document.getElementById('pdx-conn-type') || {}).value || 'rest_api';
        var endpoint = (document.getElementById('pdx-conn-endpoint') || {}).value || '';
        var auth     = (document.getElementById('pdx-conn-auth') || {}).value || '';
        var result   = document.getElementById('pdx-conn-result');
        if (!endpoint) { showNotif('Endpoint URL required', 'warn'); return; }
        result.innerHTML = '<div class="pdx-scanning">' + scanStages(['Connecting', 'Authenticating', 'Inspecting response']) + '</div>';
        animateScanStages(result.querySelector('.pdx-stages'), 500);

        apiFetch('POST', '/connectors/test', { type: type, endpoint: endpoint, auth_token: auth }).then(function(data) {
          if (!data) { result.innerHTML = '<div class="pdx-error">Test failed.</div>'; return; }
          if (data.error === 'payment_required') { result.innerHTML = ''; renderPaywall({ label: 'Connectors', price: data.price, currency: data.currency }, {}); return; }
          renderConnectorResult(result, data);
        });
      });
    }

    function renderConnectorResult(container, data) {
      var ok = data.ok;
      var html = '<div class="pdx-result">';
      html += '<div class="pdx-conn-status ' + (ok ? 'pdx-conn-status--ok' : 'pdx-conn-status--fail') + '">';
      html += (ok ? '✓ Connected' : '✗ Failed') + ' <span class="pdx-tag">HTTP ' + (data.status_code || 0) + '</span> <span class="pdx-tag">' + (data.latency_ms || 0) + 'ms</span>';
      html += '</div>';
      if (data.error) html += '<div class="pdx-error-msg">' + escHtml(data.error) + '</div>';
      if (data.response) {
        var resp = typeof data.response === 'object' ? JSON.stringify(data.response, null, 2) : String(data.response);
        html += '<div class="pdx-section"><div class="pdx-section-title">Response</div><pre class="pdx-code">' + escHtml(resp.slice(0, 1000)) + '</pre></div>';
      }
      if (data.headers && Object.keys(data.headers).length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Headers</div><div class="pdx-kv-grid">';
        Object.keys(data.headers).forEach(function(k) { html += kvRow(k, data.headers[k]); });
        html += '</div></div>';
      }
      html += '</div>';
      container.innerHTML = html;
    }


    /* ══════════════════════════════════════════════════════
       DEVELOPMENT SERVICES (Create)
    ══════════════════════════════════════════════════════ */
    function renderCreate(mod) {
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('plus') + '<span>Development Services</span></div><div class="pdx-ph-sub">Submit a project brief for a scoped proposal</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-field"><label class="pdx-label">Your Name</label><input id="pdx-brief-name" class="pdx-input" placeholder="Jane Smith" /></div>' +
            '<div class="pdx-field"><label class="pdx-label">Email</label><input id="pdx-brief-email" class="pdx-input" type="email" placeholder="jane@company.com" /></div>' +
            '<div class="pdx-field"><label class="pdx-label">Project Type</label><select id="pdx-brief-type" class="pdx-select"><option value="">Select type...</option><option value="web_app">Web Application</option><option value="ai_integration">AI Integration</option><option value="api">API / Backend</option><option value="automation">Automation</option><option value="security">Security Audit</option><option value="other">Other</option></select></div>' +
            '<div class="pdx-field"><label class="pdx-label">Budget Range</label><select id="pdx-brief-budget" class="pdx-select"><option value="">Select range...</option><option value="<5k">Under $5,000</option><option value="5k-15k">$5,000 – $15,000</option><option value="15k-50k">$15,000 – $50,000</option><option value="50k+">$50,000+</option></select></div>' +
            '<div class="pdx-field"><label class="pdx-label">Project Details</label><textarea id="pdx-brief-details" class="pdx-textarea" placeholder="Describe your project, goals, timeline, and any technical requirements..." rows="5"></textarea></div>' +
            '<button id="pdx-brief-submit" class="pdx-btn-primary pdx-btn-full">Submit Brief</button>' +
            '<div id="pdx-brief-result"></div>' +
            '<div class="pdx-info-box">We respond within 24 hours with a scoped proposal and timeline estimate.</div>' +
          '</div>' +
        '</div>';

      document.getElementById('pdx-brief-submit').addEventListener('click', function() {
        var name    = (document.getElementById('pdx-brief-name') || {}).value || '';
        var email   = (document.getElementById('pdx-brief-email') || {}).value || '';
        var type    = (document.getElementById('pdx-brief-type') || {}).value || '';
        var budget  = (document.getElementById('pdx-brief-budget') || {}).value || '';
        var details = (document.getElementById('pdx-brief-details') || {}).value || '';
        var result  = document.getElementById('pdx-brief-result');
        if (!name || !email || !details) { showNotif('Name, email, and details required', 'warn'); return; }

        var btn = document.getElementById('pdx-brief-submit');
        btn.disabled = true; btn.textContent = 'Sending...';

        apiFetch('POST', '/brief/submit', { name: name, email: email, type: type, budget: budget, details: details }).then(function(data) {
          btn.disabled = false; btn.textContent = 'Submit Brief';
          if (!data || data.error) { result.innerHTML = '<div class="pdx-error">' + escHtml((data && data.error) || 'Submission failed.') + '</div>'; return; }
          result.innerHTML = '<div class="pdx-success"><strong>Brief received!</strong><br>' + escHtml(data.message || '') + '</div>';
          showNotif('Brief submitted successfully', 'success');
        });
      });
    }

    /* ══════════════════════════════════════════════════════
       WORKSPACES
    ══════════════════════════════════════════════════════ */
    function renderWorkspace(mod) {
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('folder') + '<span>Workspaces</span></div><div class="pdx-ph-sub">Saved projects & scan history</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-input-row"><input id="pdx-ws-search" class="pdx-input" placeholder="Search workspaces..." /><button id="pdx-ws-search-btn" class="pdx-btn-ghost">Search</button></div>' +
            '<div class="pdx-tabs" id="pdx-ws-tabs">' +
              '<button class="pdx-tab is-active" data-tab="all">All</button>' +
              '<button class="pdx-tab" data-tab="security">Security</button>' +
              '<button class="pdx-tab" data-tab="ai">AI</button>' +
              '<button class="pdx-tab" data-tab="pinned">Pinned</button>' +
            '</div>' +
            '<div id="pdx-ws-list"><div class="pdx-loading">Loading workspaces...</div></div>' +
          '</div>' +
        '</div>';

      loadWorkspaces('');

      setupTabs('pdx-ws-tabs', 'pdx-ws-list', {
        all:      function() { loadWorkspaces(''); return '<div class="pdx-loading">Loading...</div>'; },
        security: function() { loadWorkspaces('trust'); return '<div class="pdx-loading">Loading...</div>'; },
        ai:       function() { loadWorkspaces('personas'); return '<div class="pdx-loading">Loading...</div>'; },
        pinned:   function() { loadWorkspaces('', 'pinned'); return '<div class="pdx-loading">Loading...</div>'; },
      });

      document.getElementById('pdx-ws-search-btn').addEventListener('click', function() {
        var q = (document.getElementById('pdx-ws-search') || {}).value || '';
        if (q.length < 2) return;
        apiFetch('GET', '/workspace/search?q=' + encodeURIComponent(q)).then(function(data) {
          renderWorkspaceList(document.getElementById('pdx-ws-list'), (data && data.results) || []);
        });
      });
    }

    function loadWorkspaces(module, status) {
      var container = document.getElementById('pdx-ws-list');
      if (!container) return;
      var url = '/workspace?limit=30' + (module ? '&module=' + module : '') + (status ? '&status=' + status : '');
      apiFetch('GET', url).then(function(data) {
        renderWorkspaceList(container, (data && data.workspaces) || []);
      });
    }

    function renderWorkspaceList(container, items) {
      if (!items.length) { container.innerHTML = '<div class="pdx-empty">No workspaces found.</div>'; return; }
      var html = '<div class="pdx-ws-list">';
      items.forEach(function(ws) {
        var tags = Array.isArray(ws.tags) ? ws.tags : [];
        html += '<div class="pdx-ws-item" data-ws-id="' + escHtml(ws.ws_id) + '">' +
          '<div class="pdx-ws-item-hd">' +
            '<span class="pdx-ws-title">' + escHtml(ws.title || 'Untitled') + '</span>' +
            (ws.is_pinned == 1 ? '<span class="pdx-ws-pin">📌</span>' : '') +
          '</div>' +
          '<div class="pdx-ws-meta">' +
            '<span class="pdx-tag">' + escHtml(ws.module || '') + '</span>' +
            '<span class="pdx-tag">' + escHtml(ws.ws_type || '') + '</span>' +
            '<span class="pdx-ws-date">' + formatDate(ws.updated_at) + '</span>' +
          '</div>' +
          (tags.length ? '<div class="pdx-ws-tags">' + tags.map(function(t) { return '<span class="pdx-tag pdx-tag--sm">' + escHtml(t) + '</span>'; }).join('') + '</div>' : '') +
        '</div>';
      });
      html += '</div>';
      container.innerHTML = html;
    }


    /* ══════════════════════════════════════════════════════
       SHARED UTILITIES
    ══════════════════════════════════════════════════════ */

    function renderPaywall(mod, access) {
      var price    = (access && access.price) || mod.price || mod.default_price || 0;
      var currency = (access && access.currency) || 'USD';
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('shield') + '<span>' + escHtml(mod.label || 'Module') + '</span></div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-paywall">' +
              '<div class="pdx-paywall-icon">' + svgIcon('shield') + '</div>' +
              '<div class="pdx-paywall-title">Premium Module</div>' +
              '<div class="pdx-paywall-desc">' + escHtml(mod.description || '') + '</div>' +
              '<div class="pdx-paywall-price">' + currency + ' ' + price + '</div>' +
              '<button class="pdx-btn-primary pdx-btn-full pdx-unlock-btn" data-module="' + escHtml(mod.id || '') + '" data-price="' + price + '" data-currency="' + currency + '">Unlock Access</button>' +
            '</div>' +
          '</div>' +
        '</div>';
      inner.querySelector('.pdx-unlock-btn').addEventListener('click', function(e) {
        var btn = e.currentTarget;
        initiatePayment(btn.dataset.module, parseFloat(btn.dataset.price), btn.dataset.currency);
      });
    }

    function renderPaywallInline(mod, access) {
      var price    = (access && access.price) || mod.price || 0;
      var currency = (access && access.currency) || 'USD';
      return '<div class="pdx-paywall-inline">' +
        '<div class="pdx-paywall-inline-title">Preview limit reached</div>' +
        '<button class="pdx-btn-primary pdx-unlock-btn" data-module="' + escHtml(mod.id || '') + '" data-price="' + price + '" data-currency="' + currency + '">Unlock for ' + currency + ' ' + price + '</button>' +
      '</div>';
    }

    function initiatePayment(moduleId, price, currency) {
      var btn = inner.querySelector('.pdx-unlock-btn');
      if (btn) { btn.disabled = true; btn.textContent = 'Creating order...'; }

      apiFetch('POST', '/pay/create', { module_id: moduleId }).then(function(data) {
        if (!data || data.error) {
          showNotif(data && data.error ? data.error : 'Payment unavailable', 'error');
          if (btn) { btn.disabled = false; btn.textContent = 'Unlock Access'; }
          return;
        }
        if (data.approve_url) {
          var isMobile = window.innerWidth < (C.mobileBreakpoint || 680);
          if (isMobile) {
            window.location.href = data.approve_url;
          } else {
            var popup = window.open(data.approve_url, 'pdx_paypal', 'width=500,height=700,scrollbars=yes');
            if (!popup) window.location.href = data.approve_url;
            else {
              var poll = setInterval(function() {
                try {
                  if (popup.closed) {
                    clearInterval(poll);
                    apiFetch('POST', '/pay/capture', { order_id: data.order_id, module_id: moduleId }).then(function(cap) {
                      if (cap && cap.ok) {
                        showNotif('Access unlocked!', 'success');
                        apiFetch('GET', '/pay/status').then(function(s) { if (s) state.accessStatus = s; });
                        openPanel(moduleId);
                      }
                    });
                  }
                } catch(ex) {}
              }, 800);
            }
          }
        }
      });
    }

    function scanStages(stages) {
      return '<div class="pdx-stages">' + stages.map(function(s, i) {
        return '<div class="pdx-stage" data-idx="' + i + '"><span class="pdx-stage-dot"></span><span class="pdx-stage-label">' + escHtml(s) + '</span></div>';
      }).join('') + '</div>';
    }

    function animateScanStages(container, interval) {
      if (!container) return;
      var stages = container.querySelectorAll('.pdx-stage');
      var i = 0;
      var timer = setInterval(function() {
        if (i > 0 && stages[i-1]) stages[i-1].classList.add('is-done');
        if (i < stages.length) { stages[i].classList.add('is-active'); i++; }
        else clearInterval(timer);
      }, interval || 700);
    }

    function setupTabs(tabsId, contentId, renderers) {
      var tabsEl   = document.getElementById(tabsId);
      var contentEl = document.getElementById(contentId);
      if (!tabsEl || !contentEl) return;
      tabsEl.addEventListener('click', function(e) {
        var tab = e.target.closest('.pdx-tab');
        if (!tab) return;
        tabsEl.querySelectorAll('.pdx-tab').forEach(function(t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        var key = tab.dataset.tab;
        if (renderers[key]) contentEl.innerHTML = renderers[key]();
        // Re-bind after render
        if (key === 'build' && tabsId === 'pdx-builder-tabs') bindBuilderBuild();
        if (key === 'run'   && tabsId === 'pdx-pipeline-tabs') bindPipelineRun();
        if (key === 'test'  && tabsId === 'pdx-conn-tabs') bindConnectorTest();
        if (key === 'templates' && tabsId === 'pdx-builder-tabs') loadBuilderTemplates();
        if (key === 'templates' && tabsId === 'pdx-pipeline-tabs') loadPipelineTemplates();
        if (key === 'library' && tabsId === 'pdx-conn-tabs') loadConnectorLibrary();
      });
    }

    function loadBuilderTemplates() {
      apiFetch('GET', '/builder/templates').then(function(data) {
        var pane = document.getElementById('pdx-builder-tpl-pane');
        if (!pane || !data) return;
        var html = '<div class="pdx-tpl-grid">';
        (data.templates || []).forEach(function(t) {
          html += '<div class="pdx-tpl-card" data-tpl-id="' + escHtml(t.id) + '"><div class="pdx-tpl-name">' + escHtml(t.label) + '</div><div class="pdx-tpl-steps">' + (t.steps || []).length + ' steps</div><button class="pdx-btn-ghost pdx-btn-sm pdx-use-tpl">Use</button></div>';
        });
        html += '</div>';
        pane.innerHTML = html;
      });
    }

    function loadPipelineTemplates() {
      apiFetch('GET', '/pipeline/templates').then(function(data) {
        var pane = document.getElementById('pdx-pipeline-tpl-pane');
        if (!pane || !data) return;
        var html = '<div class="pdx-tpl-grid">';
        (data.templates || []).forEach(function(t) {
          html += '<div class="pdx-tpl-card"><div class="pdx-tpl-name">' + escHtml(t.label) + '</div><div class="pdx-tpl-steps">' + (t.agents || []).length + ' agents</div></div>';
        });
        html += '</div>';
        pane.innerHTML = html;
      });
    }

    function loadConnectorLibrary() {
      apiFetch('GET', '/connectors/list').then(function(data) {
        var pane = document.getElementById('pdx-conn-lib');
        if (!pane || !data) return;
        var html = '<div class="pdx-tab-pane"><div class="pdx-conn-grid">';
        (data.connectors || []).forEach(function(c) {
          html += '<div class="pdx-conn-card"><div class="pdx-conn-name">' + escHtml(c.label) + '</div><div class="pdx-conn-desc">' + escHtml(c.description) + '</div></div>';
        });
        html += '</div></div>';
        pane.innerHTML = html;
      });
    }

    function loadJobHistory(module, containerId) {
      var container = document.getElementById(containerId);
      if (!container) return;
      apiFetch('GET', '/queue/jobs?module=' + module + '&limit=5').then(function(data) {
        var jobs = (data && data.jobs) || [];
        if (!jobs.length) { container.innerHTML = ''; return; }
        var html = '<div class="pdx-section-title">Recent Jobs</div><div class="pdx-job-list">';
        jobs.forEach(function(j) {
          var cls = j.status === 'done' ? 'pdx-job--done' : j.status === 'failed' ? 'pdx-job--fail' : 'pdx-job--pending';
          html += '<div class="pdx-job-item ' + cls + '"><span class="pdx-job-id">' + escHtml(j.job_id || '') + '</span><span class="pdx-job-status">' + escHtml(j.status) + '</span><span class="pdx-job-date">' + formatDate(j.queued_at) + '</span></div>';
        });
        html += '</div>';
        container.innerHTML = html;
      });
    }

    function renderJobHistory(module) {
      return '<div class="pdx-tab-pane" id="pdx-job-history-pane"><div class="pdx-loading">Loading jobs...</div></div>';
    }

    function renderScanHistory(module) {
      var container = document.getElementById('pdx-' + module + '-history');
      if (!container) return;
      var history = state.scanHistory[module] || [];
      if (!history.length) return;
      var html = '<div class="pdx-section-title">Recent Scans</div><div class="pdx-history-list">';
      history.slice(-5).reverse().forEach(function(h) {
        var cls = h.verdict === 'clean' || h.verdict === 'low' ? 'pdx-hist--clean' : h.verdict === 'medium' ? 'pdx-hist--warn' : 'pdx-hist--bad';
        html += '<div class="pdx-hist-item ' + cls + '"><span class="pdx-hist-target">' + escHtml(h.target) + '</span><span class="pdx-hist-score">' + h.score + '</span><span class="pdx-hist-verdict">' + escHtml(h.verdict) + '</span></div>';
      });
      html += '</div>';
      container.innerHTML = html;
    }

    function addToScanHistory(module, target, risk) {
      if (!state.scanHistory[module]) state.scanHistory[module] = [];
      state.scanHistory[module].push({ target: target, score: risk && risk.score || 0, verdict: risk && risk.verdict || 'unknown', ts: Date.now() });
      state.scanHistory[module] = state.scanHistory[module].slice(-20);
    }

    /* ── Notifications ────────────────────────────────────── */
    function showNotif(message, type) {
      var notif = document.createElement('div');
      notif.className = 'pdx-notif-item pdx-notif-item--' + (type || 'info');
      notif.textContent = message;
      var container = document.getElementById('pdx-notif');
      if (!container) return;
      container.appendChild(notif);
      setTimeout(function() { notif.classList.add('is-visible'); }, 10);
      setTimeout(function() {
        notif.classList.remove('is-visible');
        setTimeout(function() { notif.remove(); }, 300);
      }, 4000);
    }

    /* ── API helper ───────────────────────────────────────── */
    function apiFetch(method, path, body) {
      var opts = {
        method: method,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
        credentials: 'same-origin',
      };
      if (body && method !== 'GET') opts.body = JSON.stringify(body);
      return fetch(C.restUrl + path, opts)
        .then(function(r) { return r.json(); })
        .catch(function() { return null; });
    }

    function logEvent(module, action, meta) {
      if (!C.analytics) return;
      apiFetch('POST', '/event', { module: module, action: action, meta: meta || {} });
    }

    /* ── Helpers ──────────────────────────────────────────── */
    function escHtml(str) {
      return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function kvRow(key, value) {
      return '<div class="pdx-kv-row"><span class="pdx-kv-key">' + escHtml(key) + '</span><span class="pdx-kv-val">' + escHtml(String(value)) + '</span></div>';
    }

    function formatDate(str) {
      if (!str) return '';
      var d = new Date(str);
      return isNaN(d) ? str : d.toLocaleDateString();
    }

    function exportJSON(filename, data) {
      var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      var url  = URL.createObjectURL(blob);
      var a    = document.createElement('a');
      a.href = url; a.download = filename + '.json'; a.click();
      URL.revokeObjectURL(url);
    }

    function svgIcon(name) {
      var icons = {
        shield:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        search:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-3.5-3.5"/></svg>',
        alert:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        user:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/></svg>',
        layers:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
        pipeline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 12h4l4-5M7 12l4 1 4 4"/></svg>',
        grid:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v6M14.5 17h6"/></svg>',
        link:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        plus:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
        folder:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
      };
      return icons[name] || icons.shield;
    }

    /* ── Mobile ───────────────────────────────────────────── */
    function setupMobile(C, panel, dock) {
      function check() {
        var mobile = window.innerWidth < (C.mobileBreakpoint || 680);
        panel.classList.toggle('pdx-panel--mobile', mobile);
        dock.classList.toggle('pdx-dock--mobile', mobile);
      }
      check();
      window.addEventListener('resize', check);
    }

  } /* end init */
}());
