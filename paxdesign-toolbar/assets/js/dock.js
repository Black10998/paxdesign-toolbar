/**
 * PaxDesign Utility Dock — v4.0.0
 * Enterprise AI/Cyber SaaS dock — SSE real-time, command palette,
 * infrastructure graph, investigation board, team collaboration,
 * billing enforcement, AI memory, keyboard shortcuts.
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
    // v4
    sseConnections: {},
    commandPaletteOpen: false,
    billingPlan: null,
    billingCredits: 0,
    workers: [],
    teams: [],
    activeTeam: null,
    activeCaseId: null,
    graphData: { nodes: [], edges: [] },
    investigationItems: [],
    liveActivity: [],
    queueStats: {},
    memoryItems: [],
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
      if (e.key === 'Escape') {
        if (state.commandPaletteOpen) { closeCommandPalette(); return; }
        if (state.activeModule) closePanel();
      }
      // Cmd/Ctrl+K → command palette
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        toggleCommandPalette();
      }
      // Cmd/Ctrl+Shift+A → activity feed
      if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key === 'A') {
        e.preventDefault();
        openPanel('workspace');
      }
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

    /* ── v4: Load billing status ──────────────────────────── */
    apiFetch('GET', '/billing/status').then(function(data) {
      if (!data) return;
      state.billingPlan    = data.plan;
      state.billingCredits = data.credits || 0;
      updateBillingBadge();
    });

    /* ── v4: Load worker nodes ────────────────────────────── */
    apiFetch('GET', '/workers').then(function(data) {
      if (data && data.workers) state.workers = data.workers;
    });

    /* ── v4: Load teams ───────────────────────────────────── */
    apiFetch('GET', '/teams').then(function(data) {
      if (data && data.teams && data.teams.length) {
        state.teams = data.teams;
        state.activeTeam = data.teams[0].team_id;
      }
    });

    /* ── v4: SSE activity feed ────────────────────────────── */
    if (C.sseEnabled !== false) {
      startSSE('activity', function(evt) {
        try {
          var d = JSON.parse(evt.data);
          state.liveActivity.unshift(d);
          if (state.liveActivity.length > 100) state.liveActivity.pop();
          if (d.severity === 'critical' || d.severity === 'high') {
            showNotif('[' + (d.module || 'system') + '] ' + (d.action || ''), 'warn');
          }
          if (state.activeModule === 'workspace') refreshActivityFeed();
        } catch(e) {}
      });
      startSSE('queue', function(evt) {
        try {
          var d = JSON.parse(evt.data);
          state.queueStats = d;
          updateQueueBadge(d);
        } catch(e) {}
      });
    }

    /* ── v4: Command palette DOM ──────────────────────────── */
    buildCommandPalette();

    /* ── v4: Billing badge in dock ────────────────────────── */
    buildBillingBadge();

    /* ── Mobile ───────────────────────────────────────────── */
    if (C.mobileEnabled) setupMobile(C, panel, dock);

    /* ── Panel renderer ───────────────────────────────────── */
    function renderPanel(moduleId) {
      var mod = C.modules[moduleId];
      if (!mod) { inner.innerHTML = '<div class="pdx-empty">Module not found.</div>'; return; }
      var access = state.accessStatus[moduleId] || {};
      var locked = access.status === 'locked';

      switch (moduleId) {
        case 'trust':          renderTrust(mod, access); break;
        case 'osint':          renderOsint(mod, access, locked); break;
        case 'threat':         renderThreat(mod, access, locked); break;
        case 'personas':       renderPersonas(mod, access, locked); break;
        case 'builder':        renderBuilder(mod, access, locked); break;
        case 'pipeline':       renderPipeline(mod, access, locked); break;
        case 'automation':     renderAutomation(mod, access, locked); break;
        case 'connectors':     renderConnectors(mod, access, locked); break;
        case 'create':         renderCreate(mod); break;
        case 'workspace':      renderWorkspace(mod); break;
        // v4 modules
        case 'investigation':  renderInvestigation(mod, access, locked); break;
        case 'graph':          renderInfraGraph(mod, access, locked); break;
        case 'team':           renderTeam(mod, access, locked); break;
        case 'billing':        renderBilling(mod); break;
        case 'memory':         renderMemory(mod, access, locked); break;
        default:               inner.innerHTML = '<div class="pdx-empty">Coming soon.</div>';
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


    /* ══════════════════════════════════════════════════════
       v4: SSE INFRASTRUCTURE
    ══════════════════════════════════════════════════════ */
    function startSSE(channel, onMessage) {
      if (!window.EventSource) return;
      var url = C.restUrl.replace('/wp-json/pdx/v1', '') + '/wp-json/pdx/v1/sse?channel=' + channel + '&nonce=' + (C.nonce || '');
      var es = new EventSource(url);
      es.onmessage = onMessage;
      es.onerror = function() { setTimeout(function() { startSSE(channel, onMessage); }, 5000); };
      state.sseConnections[channel] = es;
      return es;
    }

    function stopSSE(channel) {
      if (state.sseConnections[channel]) {
        state.sseConnections[channel].close();
        delete state.sseConnections[channel];
      }
    }

    function updateQueueBadge(stats) {
      var badge = document.getElementById('pdx-queue-badge');
      if (!badge) return;
      var running = (stats && stats.running) || 0;
      badge.textContent = running > 0 ? running : '';
      badge.style.display = running > 0 ? 'flex' : 'none';
    }

    function updateBillingBadge() {
      var el = document.getElementById('pdx-billing-plan-badge');
      if (!el || !state.billingPlan) return;
      var plan = state.billingPlan;
      el.textContent = typeof plan === 'object' ? (plan.name || 'Free') : plan;
    }

    function buildBillingBadge() {
      var badge = document.createElement('div');
      badge.id = 'pdx-billing-plan-badge';
      badge.className = 'pdx-dock-plan-badge';
      badge.title = 'Current plan';
      dock.appendChild(badge);
    }

    /* ══════════════════════════════════════════════════════
       v4: COMMAND PALETTE
    ══════════════════════════════════════════════════════ */
    function buildCommandPalette() {
      var overlay = document.createElement('div');
      overlay.id = 'pdx-cmd-overlay';
      overlay.className = 'pdx-cmd-overlay';
      overlay.innerHTML =
        '<div class="pdx-cmd-box" role="dialog" aria-label="Command palette">' +
          '<div class="pdx-cmd-search-row">' +
            svgIcon('search') +
            '<input id="pdx-cmd-input" class="pdx-cmd-input" type="text" placeholder="Search modules, workspaces, IOCs…" autocomplete="off" spellcheck="false"/>' +
            '<kbd class="pdx-cmd-esc">Esc</kbd>' +
          '</div>' +
          '<div id="pdx-cmd-results" class="pdx-cmd-results"></div>' +
          '<div class="pdx-cmd-footer"><kbd>↑↓</kbd> navigate &nbsp; <kbd>↵</kbd> select &nbsp; <kbd>Esc</kbd> close</div>' +
        '</div>';
      document.body.appendChild(overlay);

      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeCommandPalette();
      });

      var input = document.getElementById('pdx-cmd-input');
      var results = document.getElementById('pdx-cmd-results');
      var selectedIdx = 0;
      var currentResults = [];

      input.addEventListener('input', function() {
        var q = input.value.trim();
        apiFetch('GET', '/command/search?q=' + encodeURIComponent(q)).then(function(data) {
          currentResults = (data && data.results) || [];
          selectedIdx = 0;
          renderCmdResults(results, currentResults, selectedIdx, handleCmdSelect);
        });
      });

      input.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedIdx = Math.min(selectedIdx + 1, currentResults.length - 1); renderCmdResults(results, currentResults, selectedIdx, handleCmdSelect); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); selectedIdx = Math.max(selectedIdx - 1, 0); renderCmdResults(results, currentResults, selectedIdx, handleCmdSelect); }
        if (e.key === 'Enter' && currentResults[selectedIdx]) { handleCmdSelect(currentResults[selectedIdx]); }
      });

      // Initial load
      apiFetch('GET', '/command/search?q=').then(function(data) {
        currentResults = (data && data.results) || [];
        renderCmdResults(results, currentResults, 0, handleCmdSelect);
      });
    }

    function renderCmdResults(container, items, selectedIdx, onSelect) {
      if (!items.length) { container.innerHTML = '<div class="pdx-cmd-empty">No results</div>'; return; }
      container.innerHTML = items.map(function(item, i) {
        return '<div class="pdx-cmd-item' + (i === selectedIdx ? ' is-selected' : '') + '" data-idx="' + i + '">' +
          '<span class="pdx-cmd-icon">' + svgIcon(item.icon || 'shield') + '</span>' +
          '<span class="pdx-cmd-label">' + escHtml(item.label) + '</span>' +
          '<span class="pdx-cmd-desc">' + escHtml(item.description || '') + '</span>' +
          '<span class="pdx-cmd-type">' + escHtml(item.type || '') + '</span>' +
        '</div>';
      }).join('');
      container.querySelectorAll('.pdx-cmd-item').forEach(function(el) {
        el.addEventListener('click', function() { onSelect(items[parseInt(el.dataset.idx)]); });
      });
    }

    function handleCmdSelect(item) {
      closeCommandPalette();
      if (item.type === 'module') { openPanel(item.id); return; }
      if (item.type === 'workspace') { openPanel('workspace'); return; }
      if (item.type === 'action') {
        if (item.id === 'new_scan') openPanel('trust');
        else if (item.id === 'new_investigation') openPanel('investigation');
        else if (item.id === 'open_workspace') openPanel('workspace');
        else if (item.id === 'view_audit') openPanel('workspace');
      }
    }

    function toggleCommandPalette() {
      state.commandPaletteOpen ? closeCommandPalette() : openCommandPalette();
    }

    function openCommandPalette() {
      var overlay = document.getElementById('pdx-cmd-overlay');
      if (!overlay) return;
      overlay.classList.add('is-open');
      state.commandPaletteOpen = true;
      setTimeout(function() { var inp = document.getElementById('pdx-cmd-input'); if (inp) inp.focus(); }, 50);
    }

    function closeCommandPalette() {
      var overlay = document.getElementById('pdx-cmd-overlay');
      if (overlay) overlay.classList.remove('is-open');
      state.commandPaletteOpen = false;
    }


    /* ══════════════════════════════════════════════════════
       v4: INVESTIGATION BOARD
    ══════════════════════════════════════════════════════ */
    function renderInvestigation(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph pdx-ph--investigation">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + svgIcon('search') + '<span>Investigation Board</span><span class="pdx-badge pdx-badge--new">v4</span></div>' +
            '<div class="pdx-ph-sub">Correlate IOCs, build timelines, collaborate</div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-tabs" id="pdx-inv-tabs">' +
              '<button class="pdx-tab is-active" data-tab="correlate">Correlate</button>' +
              '<button class="pdx-tab" data-tab="timeline">Timeline</button>' +
              '<button class="pdx-tab" data-tab="clusters">Clusters</button>' +
              '<button class="pdx-tab" data-tab="cases">Cases</button>' +
            '</div>' +
            '<div id="pdx-inv-content">' + renderInvCorrelateTab() + '</div>' +
          '</div>' +
        '</div>';

      setupTabs('pdx-inv-tabs', 'pdx-inv-content', {
        correlate: renderInvCorrelateTab,
        timeline:  renderInvTimelineTab,
        clusters:  renderInvClustersTab,
        cases:     renderInvCasesTab,
      });
    }

    function renderInvCorrelateTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-input-row">' +
          '<input id="pdx-inv-input" class="pdx-input" placeholder="IP, domain, hash, email…" autocomplete="off"/>' +
          '<select id="pdx-inv-type" class="pdx-select"><option value="">Auto-detect</option><option value="ip">IP</option><option value="domain">Domain</option><option value="hash">Hash</option><option value="email">Email</option></select>' +
          '<button id="pdx-inv-btn" class="pdx-btn-primary">Correlate</button>' +
        '</div>' +
        '<div id="pdx-inv-result"></div>' +
      '</div>';
    }

    function renderInvTimelineTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-input-row"><input id="pdx-tl-input" class="pdx-input" placeholder="Target to reconstruct timeline"/><button id="pdx-tl-btn" class="pdx-btn-primary">Build</button></div>' +
        '<div id="pdx-tl-result"></div>' +
      '</div>';
    }

    function renderInvClustersTab() {
      var html = '<div class="pdx-tab-pane"><div class="pdx-loading-sm">Loading clusters…</div></div>';
      setTimeout(function() {
        var el = document.getElementById('pdx-inv-content');
        if (!el) return;
        apiFetch('GET', '/intel/clusters').then(function(data) {
          var clusters = (data && data.clusters) || [];
          var out = '<div class="pdx-tab-pane">';
          if (!clusters.length) { out += '<div class="pdx-empty">No threat clusters found.</div>'; }
          clusters.forEach(function(c) {
            out += '<div class="pdx-cluster-card">' +
              '<div class="pdx-cluster-hd"><span class="pdx-cluster-name">' + escHtml(c.name || 'Cluster') + '</span><span class="pdx-badge pdx-badge--' + (c.severity || 'low') + '">' + escHtml(c.severity || '') + '</span></div>' +
              '<div class="pdx-cluster-iocs">' + (c.iocs || []).slice(0,5).map(function(i) { return '<span class="pdx-ioc-chip">' + escHtml(i) + '</span>'; }).join('') + '</div>' +
              '<div class="pdx-cluster-meta">Confidence: ' + (c.confidence || 0) + '% · ' + (c.ioc_count || 0) + ' IOCs</div>' +
            '</div>';
          });
          out += '</div>';
          el.innerHTML = out;
        });
      }, 100);
      return html;
    }

    function renderInvCasesTab() {
      var html = '<div class="pdx-tab-pane"><div class="pdx-loading-sm">Loading cases…</div></div>';
      setTimeout(function() {
        var el = document.getElementById('pdx-inv-content');
        if (!el || !state.activeTeam) { if (el) el.innerHTML = '<div class="pdx-tab-pane"><div class="pdx-empty">No team selected. Create a team first.</div></div>'; return; }
        apiFetch('GET', '/teams/' + state.activeTeam + '/cases').then(function(data) {
          var cases = (data && data.cases) || [];
          var out = '<div class="pdx-tab-pane">';
          out += '<button id="pdx-new-case-btn" class="pdx-btn-primary pdx-mb-sm">+ New Case</button>';
          if (!cases.length) { out += '<div class="pdx-empty">No cases yet.</div>'; }
          cases.forEach(function(c) {
            out += '<div class="pdx-case-card" data-case-id="' + escHtml(c.case_id) + '">' +
              '<div class="pdx-case-hd"><span class="pdx-case-title">' + escHtml(c.title) + '</span><span class="pdx-badge pdx-badge--' + (c.priority || 'medium') + '">' + escHtml(c.priority || '') + '</span></div>' +
              '<div class="pdx-case-meta">Status: ' + escHtml(c.status || '') + ' · ' + (c.note_count || 0) + ' notes</div>' +
            '</div>';
          });
          out += '</div>';
          el.innerHTML = out;
          var newBtn = document.getElementById('pdx-new-case-btn');
          if (newBtn) newBtn.addEventListener('click', function() { showNewCaseForm(); });
          el.querySelectorAll('.pdx-case-card').forEach(function(card) {
            card.addEventListener('click', function() { openCase(card.dataset.caseId); });
          });
        });
      }, 100);
      return html;
    }

    function showNewCaseForm() {
      var el = document.getElementById('pdx-inv-content');
      if (!el) return;
      el.innerHTML = '<div class="pdx-tab-pane">' +
        '<div class="pdx-form-group"><label>Title</label><input id="pdx-case-title" class="pdx-input" placeholder="Case title"/></div>' +
        '<div class="pdx-form-group"><label>Description</label><textarea id="pdx-case-desc" class="pdx-input" rows="3" placeholder="What are you investigating?"></textarea></div>' +
        '<div class="pdx-form-group"><label>Priority</label><select id="pdx-case-prio" class="pdx-select"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>' +
        '<div class="pdx-btn-row"><button id="pdx-case-save" class="pdx-btn-primary">Create Case</button><button id="pdx-case-cancel" class="pdx-btn-ghost">Cancel</button></div>' +
      '</div>';
      document.getElementById('pdx-case-save').addEventListener('click', function() {
        var title = document.getElementById('pdx-case-title').value.trim();
        var desc  = document.getElementById('pdx-case-desc').value.trim();
        var prio  = document.getElementById('pdx-case-prio').value;
        if (!title) return;
        apiFetch('POST', '/teams/' + state.activeTeam + '/cases', { title: title, description: desc, priority: prio }).then(function(data) {
          if (data && data.case_id) { showNotif('Case created', 'success'); renderInvCasesTab(); }
        });
      });
      document.getElementById('pdx-case-cancel').addEventListener('click', function() { renderInvCasesTab(); });
    }

    function openCase(caseId) {
      state.activeCaseId = caseId;
      var el = document.getElementById('pdx-inv-content');
      if (!el) return;
      el.innerHTML = '<div class="pdx-tab-pane"><div class="pdx-loading-sm">Loading case…</div></div>';
      apiFetch('GET', '/cases/' + caseId + '/notes').then(function(data) {
        var notes = (data && data.notes) || [];
        var out = '<div class="pdx-tab-pane">' +
          '<button id="pdx-back-cases" class="pdx-btn-ghost pdx-mb-sm">← Back to Cases</button>' +
          '<div class="pdx-case-notes">';
        if (!notes.length) out += '<div class="pdx-empty">No notes yet.</div>';
        notes.forEach(function(n) {
          out += '<div class="pdx-note pdx-note--' + (n.note_type || 'comment') + '">' +
            '<div class="pdx-note-meta">' + escHtml(n.note_type || 'comment') + ' · ' + new Date(n.created_at * 1000).toLocaleString() + '</div>' +
            '<div class="pdx-note-body">' + escHtml(n.content) + '</div>' +
          '</div>';
        });
        out += '</div>' +
          '<div class="pdx-note-input-row">' +
            '<textarea id="pdx-note-input" class="pdx-input" rows="2" placeholder="Add a note…"></textarea>' +
            '<button id="pdx-note-save" class="pdx-btn-primary">Add Note</button>' +
          '</div>' +
        '</div>';
        el.innerHTML = out;
        document.getElementById('pdx-back-cases').addEventListener('click', function() { renderInvCasesTab(); });
        document.getElementById('pdx-note-save').addEventListener('click', function() {
          var content = document.getElementById('pdx-note-input').value.trim();
          if (!content) return;
          apiFetch('POST', '/cases/' + caseId + '/notes', { content: content, type: 'comment' }).then(function() { openCase(caseId); });
        });
      });
    }


    /* ══════════════════════════════════════════════════════
       v4: INFRASTRUCTURE GRAPH
    ══════════════════════════════════════════════════════ */
    function renderInfraGraph(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph pdx-ph--graph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + svgIcon('link') + '<span>Infrastructure Graph</span><span class="pdx-badge pdx-badge--new">v4</span></div>' +
            '<div class="pdx-ph-sub">Visual IOC relationship mapping</div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-input-row">' +
              '<input id="pdx-graph-input" class="pdx-input" placeholder="Seed IOC: domain, IP, hash…" autocomplete="off"/>' +
              '<button id="pdx-graph-btn" class="pdx-btn-primary">Build Graph</button>' +
            '</div>' +
            '<div id="pdx-graph-controls" class="pdx-graph-controls" style="display:none">' +
              '<button class="pdx-graph-ctrl" id="pdx-graph-zoom-in" title="Zoom in">+</button>' +
              '<button class="pdx-graph-ctrl" id="pdx-graph-zoom-out" title="Zoom out">−</button>' +
              '<button class="pdx-graph-ctrl" id="pdx-graph-reset" title="Reset">⟳</button>' +
              '<button class="pdx-graph-ctrl" id="pdx-graph-export" title="Export">↓</button>' +
            '</div>' +
            '<canvas id="pdx-graph-canvas" class="pdx-graph-canvas"></canvas>' +
            '<div id="pdx-graph-legend" class="pdx-graph-legend"></div>' +
            '<div id="pdx-graph-detail" class="pdx-graph-detail"></div>' +
          '</div>' +
        '</div>';

      document.getElementById('pdx-graph-btn').addEventListener('click', buildGraph);
      document.getElementById('pdx-graph-input').addEventListener('keydown', function(e) { if (e.key === 'Enter') buildGraph(); });
    }

    function buildGraph() {
      var input = document.getElementById('pdx-graph-input');
      var canvas = document.getElementById('pdx-graph-canvas');
      var detail = document.getElementById('pdx-graph-detail');
      var controls = document.getElementById('pdx-graph-controls');
      if (!input || !canvas) return;
      var value = input.value.trim();
      if (!value) return;

      detail.innerHTML = '<div class="pdx-loading-sm">Correlating IOCs…</div>';
      apiFetch('POST', '/intel/correlate', { value: value }).then(function(data) {
        if (!data) { detail.innerHTML = '<div class="pdx-error">Correlation failed.</div>'; return; }
        var nodes = data.nodes || [];
        var edges = data.edges || [];
        state.graphData = { nodes: nodes, edges: edges };
        controls.style.display = 'flex';
        drawGraph(canvas, nodes, edges, detail);
        renderGraphLegend(document.getElementById('pdx-graph-legend'));
        if (data.ai_summary) {
          detail.innerHTML = '<div class="pdx-ai-summary"><div class="pdx-ai-label">AI Summary</div><div class="pdx-ai-text">' + escHtml(data.ai_summary) + '</div></div>';
        }
        setupGraphControls(canvas, nodes, edges, detail);
      });
    }

    function drawGraph(canvas, nodes, edges, detail) {
      var W = canvas.parentElement.clientWidth || 600;
      var H = 340;
      canvas.width  = W;
      canvas.height = H;
      var ctx = canvas.getContext('2d');
      if (!ctx) return;

      // Simple force-directed layout approximation
      var positions = {};
      var cx = W / 2, cy = H / 2, r = Math.min(W, H) * 0.35;
      nodes.forEach(function(n, i) {
        var angle = (2 * Math.PI * i) / nodes.length;
        positions[n.id] = { x: cx + r * Math.cos(angle), y: cy + r * Math.sin(angle) };
      });

      ctx.clearRect(0, 0, W, H);

      // Draw edges
      ctx.strokeStyle = 'rgba(99,102,241,0.35)';
      ctx.lineWidth = 1.5;
      edges.forEach(function(e) {
        var a = positions[e.source], b = positions[e.target];
        if (!a || !b) return;
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();
        // Edge label
        ctx.fillStyle = 'rgba(148,163,184,0.7)';
        ctx.font = '9px monospace';
        ctx.fillText(e.relation || '', (a.x + b.x) / 2, (a.y + b.y) / 2);
      });

      // Draw nodes
      nodes.forEach(function(n) {
        var p = positions[n.id];
        if (!p) return;
        var color = n.type === 'ip' ? '#f59e0b' : n.type === 'domain' ? '#6366f1' : n.type === 'hash' ? '#ef4444' : n.type === 'email' ? '#10b981' : '#94a3b8';
        ctx.beginPath();
        ctx.arc(p.x, p.y, 10, 0, 2 * Math.PI);
        ctx.fillStyle = color;
        ctx.fill();
        ctx.strokeStyle = 'rgba(255,255,255,0.15)';
        ctx.lineWidth = 1.5;
        ctx.stroke();
        ctx.fillStyle = '#f1f5f9';
        ctx.font = 'bold 9px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(n.label ? n.label.slice(0, 12) : n.id.slice(0, 12), p.x, p.y + 22);
      });

      // Click to inspect node
      canvas.onclick = function(e) {
        var rect = canvas.getBoundingClientRect();
        var mx = e.clientX - rect.left, my = e.clientY - rect.top;
        nodes.forEach(function(n) {
          var p = positions[n.id];
          if (!p) return;
          var dx = mx - p.x, dy = my - p.y;
          if (Math.sqrt(dx*dx + dy*dy) < 14) {
            detail.innerHTML = '<div class="pdx-graph-node-detail">' +
              '<div class="pdx-kv-grid">' +
                kvRow('ID', n.id) + kvRow('Type', n.type) + kvRow('Source', n.source || '') +
                kvRow('Confidence', (n.confidence || 0) + '%') + kvRow('First seen', n.first_seen || '') +
              '</div>' +
              '<button class="pdx-btn-ghost pdx-mt-sm" onclick="document.getElementById(\'pdx-graph-input\').value=\'' + n.id + '\';buildGraph && buildGraph()">Pivot on this IOC</button>' +
            '</div>';
          }
        });
      };
    }

    function renderGraphLegend(el) {
      if (!el) return;
      el.innerHTML = ['ip:#f59e0b', 'domain:#6366f1', 'hash:#ef4444', 'email:#10b981', 'other:#94a3b8'].map(function(s) {
        var parts = s.split(':');
        return '<span class="pdx-legend-item"><span class="pdx-legend-dot" style="background:' + parts[1] + '"></span>' + parts[0] + '</span>';
      }).join('');
    }

    function setupGraphControls(canvas, nodes, edges, detail) {
      var scale = 1;
      document.getElementById('pdx-graph-zoom-in').onclick  = function() { scale = Math.min(scale + 0.2, 3); canvas.style.transform = 'scale(' + scale + ')'; };
      document.getElementById('pdx-graph-zoom-out').onclick = function() { scale = Math.max(scale - 0.2, 0.4); canvas.style.transform = 'scale(' + scale + ')'; };
      document.getElementById('pdx-graph-reset').onclick    = function() { scale = 1; canvas.style.transform = 'scale(1)'; };
      document.getElementById('pdx-graph-export').onclick   = function() { exportJSON('graph-' + Date.now(), state.graphData); };
    }


    /* ══════════════════════════════════════════════════════
       v4: TEAM MANAGEMENT
    ══════════════════════════════════════════════════════ */
    function renderTeam(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('user') + '<span>Team</span><span class="pdx-badge pdx-badge--new">v4</span></div><div class="pdx-ph-sub">Manage team members and roles</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-tabs" id="pdx-team-tabs">' +
              '<button class="pdx-tab is-active" data-tab="members">Members</button>' +
              '<button class="pdx-tab" data-tab="create">Create Team</button>' +
            '</div>' +
            '<div id="pdx-team-content">' + renderTeamMembersTab() + '</div>' +
          '</div>' +
        '</div>';

      setupTabs('pdx-team-tabs', 'pdx-team-content', {
        members: renderTeamMembersTab,
        create:  renderTeamCreateTab,
      });
    }

    function renderTeamMembersTab() {
      var html = '<div class="pdx-tab-pane"><div class="pdx-loading-sm">Loading team…</div></div>';
      setTimeout(function() {
        var el = document.getElementById('pdx-team-content');
        if (!el) return;
        if (!state.activeTeam) {
          el.innerHTML = '<div class="pdx-tab-pane"><div class="pdx-empty">No team yet. Create one first.</div></div>';
          return;
        }
        apiFetch('GET', '/teams/' + state.activeTeam + '/members').then(function(data) {
          var members = (data && data.members) || [];
          var out = '<div class="pdx-tab-pane">';
          out += '<div class="pdx-team-header"><span class="pdx-team-name">' + escHtml(state.teams[0] && state.teams[0].name || 'Team') + '</span></div>';
          members.forEach(function(m) {
            out += '<div class="pdx-member-row">' +
              '<div class="pdx-member-avatar">' + escHtml((m.display_name || 'U').charAt(0).toUpperCase()) + '</div>' +
              '<div class="pdx-member-info"><div class="pdx-member-name">' + escHtml(m.display_name || m.user_login || '') + '</div><div class="pdx-member-email">' + escHtml(m.user_email || '') + '</div></div>' +
              '<span class="pdx-role-badge pdx-role-badge--' + (m.role || 'viewer') + '">' + escHtml(m.role || 'viewer') + '</span>' +
            '</div>';
          });
          if (!members.length) out += '<div class="pdx-empty">No members yet.</div>';
          out += '</div>';
          el.innerHTML = out;
        });
      }, 100);
      return html;
    }

    function renderTeamCreateTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-form-group"><label>Team Name</label><input id="pdx-team-name" class="pdx-input" placeholder="My Security Team"/></div>' +
        '<button id="pdx-team-create-btn" class="pdx-btn-primary">Create Team</button>' +
        '<div id="pdx-team-create-result"></div>' +
      '</div>';
    }

    /* ══════════════════════════════════════════════════════
       v4: BILLING
    ══════════════════════════════════════════════════════ */
    function renderBilling(mod) {
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('shield') + '<span>Billing & Plans</span></div><div class="pdx-ph-sub">Manage your subscription</div></div>' +
          '<div class="pdx-ph-body"><div class="pdx-loading-sm">Loading plans…</div></div>' +
        '</div>';

      Promise.all([
        apiFetch('GET', '/billing/plans'),
        apiFetch('GET', '/billing/status'),
        apiFetch('GET', '/billing/credits'),
      ]).then(function(results) {
        var plans   = (results[0] && results[0].plans) || {};
        var status  = results[1] || {};
        var credits = (results[2] && results[2].balance) || 0;
        var body = inner.querySelector('.pdx-ph-body');
        if (!body) return;

        var currentPlanId = status.plan && status.plan.id || 'free';
        var html = '<div class="pdx-billing-credits"><span class="pdx-credits-num">' + credits + '</span><span class="pdx-credits-label"> credits remaining</span></div>';
        html += '<div class="pdx-plans-grid">';
        Object.keys(plans).forEach(function(pid) {
          var p = plans[pid];
          var isCurrent = pid === currentPlanId;
          html += '<div class="pdx-plan-card' + (isCurrent ? ' pdx-plan-card--current' : '') + '">' +
            '<div class="pdx-plan-name">' + escHtml(p.name || pid) + (isCurrent ? ' <span class="pdx-plan-current-badge">Current</span>' : '') + '</div>' +
            '<div class="pdx-plan-price">$' + (p.price_month || 0) + '<span>/mo</span></div>' +
            '<ul class="pdx-plan-features">' +
              Object.keys(p.quotas || {}).slice(0, 5).map(function(k) {
                var v = p.quotas[k];
                return '<li>' + escHtml(k.replace(/_/g,' ')) + ': ' + (v === -1 ? 'Unlimited' : v) + '</li>';
              }).join('') +
            '</ul>' +
            (!isCurrent ? '<button class="pdx-btn-primary pdx-plan-upgrade-btn" data-plan="' + pid + '">Upgrade</button>' : '<div class="pdx-plan-active-label">Active Plan</div>') +
          '</div>';
        });
        html += '</div>';
        body.innerHTML = html;

        body.querySelectorAll('.pdx-plan-upgrade-btn').forEach(function(btn) {
          btn.addEventListener('click', function() {
            var planId = btn.dataset.plan;
            btn.textContent = 'Redirecting…';
            btn.disabled = true;
            apiFetch('POST', '/billing/checkout', { plan_id: planId, cycle: 'month' }).then(function(data) {
              if (data && data.url) { window.location.href = data.url; }
              else { showNotif(data && data.error || 'Checkout failed', 'error'); btn.textContent = 'Upgrade'; btn.disabled = false; }
            });
          });
        });
      });
    }

    /* ══════════════════════════════════════════════════════
       v4: AI MEMORY
    ══════════════════════════════════════════════════════ */
    function renderMemory(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      inner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd"><div class="pdx-ph-title">' + svgIcon('layers') + '<span>AI Memory</span><span class="pdx-badge pdx-badge--new">v4</span></div><div class="pdx-ph-sub">Long-term agent memory & semantic search</div></div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-tabs" id="pdx-mem-tabs">' +
              '<button class="pdx-tab is-active" data-tab="search">Search</button>' +
              '<button class="pdx-tab" data-tab="recent">Recent</button>' +
              '<button class="pdx-tab" data-tab="store">Store</button>' +
            '</div>' +
            '<div id="pdx-mem-content">' + renderMemSearchTab() + '</div>' +
          '</div>' +
        '</div>';

      setupTabs('pdx-mem-tabs', 'pdx-mem-content', {
        search: renderMemSearchTab,
        recent: renderMemRecentTab,
        store:  renderMemStoreTab,
      });
    }

    function renderMemSearchTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-input-row"><input id="pdx-mem-search" class="pdx-input" placeholder="Search memories…"/><button id="pdx-mem-search-btn" class="pdx-btn-primary">Search</button></div>' +
        '<div id="pdx-mem-search-result"></div>' +
      '</div>';
    }

    function renderMemRecentTab() {
      var html = '<div class="pdx-tab-pane"><div class="pdx-loading-sm">Loading…</div></div>';
      setTimeout(function() {
        var el = document.getElementById('pdx-mem-content');
        if (!el) return;
        apiFetch('GET', '/memory/recent?agent=global&limit=20').then(function(data) {
          var mems = (data && data.memories) || [];
          var out = '<div class="pdx-tab-pane">';
          if (!mems.length) out += '<div class="pdx-empty">No memories stored yet.</div>';
          mems.forEach(function(m) {
            out += '<div class="pdx-memory-item">' +
              '<div class="pdx-memory-content">' + escHtml(m.content) + '</div>' +
              '<div class="pdx-memory-meta">' + escHtml(m.mem_type || '') + ' · importance: ' + (m.importance || 0) + ' · ' + new Date((m.created_at || 0) * 1000).toLocaleDateString() + '</div>' +
            '</div>';
          });
          out += '</div>';
          el.innerHTML = out;
        });
      }, 100);
      return html;
    }

    function renderMemStoreTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-form-group"><label>Content</label><textarea id="pdx-mem-content" class="pdx-input" rows="3" placeholder="What should the AI remember?"></textarea></div>' +
        '<div class="pdx-form-row">' +
          '<div class="pdx-form-group"><label>Type</label><select id="pdx-mem-type" class="pdx-select"><option value="fact">Fact</option><option value="preference">Preference</option><option value="context">Context</option><option value="finding">Finding</option></select></div>' +
          '<div class="pdx-form-group"><label>Importance (0-100)</label><input id="pdx-mem-importance" class="pdx-input" type="number" min="0" max="100" value="50"/></div>' +
        '</div>' +
        '<button id="pdx-mem-store-btn" class="pdx-btn-primary">Store Memory</button>' +
        '<div id="pdx-mem-store-result"></div>' +
      '</div>';
    }


    /* ══════════════════════════════════════════════════════
       v4: WORKSPACE — upgraded with live activity feed
    ══════════════════════════════════════════════════════ */
    function refreshActivityFeed() {
      var feed = document.getElementById('pdx-activity-feed');
      if (!feed) return;
      var html = '';
      state.liveActivity.slice(0, 30).forEach(function(evt) {
        var cls = evt.severity === 'critical' ? 'pdx-activity--critical' : evt.severity === 'high' ? 'pdx-activity--high' : evt.severity === 'warn' ? 'pdx-activity--warn' : 'pdx-activity--info';
        html += '<div class="pdx-activity-item ' + cls + '">' +
          '<span class="pdx-activity-module">' + escHtml(evt.module || 'system') + '</span>' +
          '<span class="pdx-activity-action">' + escHtml(evt.action || '') + '</span>' +
          '<span class="pdx-activity-time">' + new Date((evt.ts || Date.now() / 1000) * 1000).toLocaleTimeString() + '</span>' +
        '</div>';
      });
      feed.innerHTML = html || '<div class="pdx-empty">No activity yet.</div>';
    }

    /* ══════════════════════════════════════════════════════
       v4: INVESTIGATION — wire up tab event handlers
    ══════════════════════════════════════════════════════ */
    function wireInvCorrelate() {
      var btn = document.getElementById('pdx-inv-btn');
      var inp = document.getElementById('pdx-inv-input');
      if (!btn || !inp) return;
      btn.addEventListener('click', runCorrelate);
      inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') runCorrelate(); });
    }

    function runCorrelate() {
      var inp  = document.getElementById('pdx-inv-input');
      var type = document.getElementById('pdx-inv-type');
      var res  = document.getElementById('pdx-inv-result');
      if (!inp || !res) return;
      var value = inp.value.trim();
      if (!value) return;
      res.innerHTML = '<div class="pdx-loading-sm">Correlating…</div>';
      apiFetch('POST', '/intel/correlate', { value: value, type: type ? type.value : '' }).then(function(data) {
        if (!data) { res.innerHTML = '<div class="pdx-error">Correlation failed.</div>'; return; }
        var html = '<div class="pdx-result">';
        html += '<div class="pdx-section"><div class="pdx-section-title">Relationships (' + (data.edges || []).length + ')</div><div class="pdx-kv-grid">';
        (data.edges || []).slice(0, 10).forEach(function(e) {
          html += kvRow(escHtml(e.source) + ' → ' + escHtml(e.target), escHtml(e.relation || '') + ' (' + (e.confidence || 0) + '%)');
        });
        html += '</div></div>';
        if (data.ai_summary) {
          html += '<div class="pdx-section"><div class="pdx-section-title">AI Summary</div><div class="pdx-ai-text">' + escHtml(data.ai_summary) + '</div></div>';
        }
        html += '<button class="pdx-btn-ghost pdx-mt-sm" id="pdx-corr-graph-btn">View in Graph</button>';
        html += '</div>';
        res.innerHTML = html;
        document.getElementById('pdx-corr-graph-btn').addEventListener('click', function() {
          openPanel('graph');
          setTimeout(function() {
            var gi = document.getElementById('pdx-graph-input');
            if (gi) { gi.value = value; buildGraph && buildGraph(); }
          }, 200);
        });
      });
    }

    function wireInvTimeline() {
      var btn = document.getElementById('pdx-tl-btn');
      var inp = document.getElementById('pdx-tl-input');
      if (!btn || !inp) return;
      btn.addEventListener('click', function() {
        var target = inp.value.trim();
        var res = document.getElementById('pdx-tl-result');
        if (!target || !res) return;
        res.innerHTML = '<div class="pdx-loading-sm">Building timeline…</div>';
        apiFetch('GET', '/intel/timeline?target=' + encodeURIComponent(target) + '&days=90').then(function(data) {
          var events = (data && data.timeline) || [];
          if (!events.length) { res.innerHTML = '<div class="pdx-empty">No timeline data found.</div>'; return; }
          var html = '<div class="pdx-timeline">';
          events.forEach(function(ev) {
            html += '<div class="pdx-tl-event">' +
              '<div class="pdx-tl-dot"></div>' +
              '<div class="pdx-tl-body">' +
                '<div class="pdx-tl-date">' + escHtml(ev.date || '') + '</div>' +
                '<div class="pdx-tl-desc">' + escHtml(ev.description || '') + '</div>' +
                '<div class="pdx-tl-source">' + escHtml(ev.source || '') + '</div>' +
              '</div>' +
            '</div>';
          });
          html += '</div>';
          res.innerHTML = html;
        });
      });
    }

    /* ══════════════════════════════════════════════════════
       v4: MEMORY — wire up tab event handlers
    ══════════════════════════════════════════════════════ */
    function wireMemSearch() {
      var btn = document.getElementById('pdx-mem-search-btn');
      var inp = document.getElementById('pdx-mem-search');
      var res = document.getElementById('pdx-mem-search-result');
      if (!btn || !inp || !res) return;
      btn.addEventListener('click', function() {
        var q = inp.value.trim();
        if (!q) return;
        res.innerHTML = '<div class="pdx-loading-sm">Searching…</div>';
        apiFetch('GET', '/memory/search?q=' + encodeURIComponent(q)).then(function(data) {
          var mems = (data && data.results) || [];
          if (!mems.length) { res.innerHTML = '<div class="pdx-empty">No results.</div>'; return; }
          res.innerHTML = mems.map(function(m) {
            return '<div class="pdx-memory-item">' +
              '<div class="pdx-memory-content">' + escHtml(m.content) + '</div>' +
              '<div class="pdx-memory-meta">' + escHtml(m.mem_type || '') + ' · score: ' + (m.score || 0).toFixed(2) + '</div>' +
            '</div>';
          }).join('');
        });
      });
      inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') btn.click(); });
    }

    function wireMemStore() {
      var btn = document.getElementById('pdx-mem-store-btn');
      if (!btn) return;
      btn.addEventListener('click', function() {
        var content    = document.getElementById('pdx-mem-content').value.trim();
        var mem_type   = document.getElementById('pdx-mem-type').value;
        var importance = parseInt(document.getElementById('pdx-mem-importance').value) || 50;
        var res        = document.getElementById('pdx-mem-store-result');
        if (!content) return;
        apiFetch('POST', '/memory/store', { content: content, agent: 'global', type: mem_type, importance: importance }).then(function(data) {
          if (data && data.mem_id) {
            res.innerHTML = '<div class="pdx-success">Memory stored (ID: ' + escHtml(data.mem_id) + ')</div>';
            document.getElementById('pdx-mem-content').value = '';
          } else {
            res.innerHTML = '<div class="pdx-error">Failed to store memory.</div>';
          }
        });
      });
    }

    function wireTeamCreate() {
      var btn = document.getElementById('pdx-team-create-btn');
      if (!btn) return;
      btn.addEventListener('click', function() {
        var name = document.getElementById('pdx-team-name').value.trim();
        var res  = document.getElementById('pdx-team-create-result');
        if (!name) return;
        apiFetch('POST', '/teams', { name: name }).then(function(data) {
          if (data && data.team_id) {
            state.activeTeam = data.team_id;
            state.teams.push({ team_id: data.team_id, name: name });
            res.innerHTML = '<div class="pdx-success">Team created!</div>';
            showNotif('Team "' + name + '" created', 'success');
          } else {
            res.innerHTML = '<div class="pdx-error">Failed to create team.</div>';
          }
        });
      });
    }


    /* ══════════════════════════════════════════════════════
       v4: EXTENDED svgIcon + utility additions
    ══════════════════════════════════════════════════════ */
    function svgIconV4(name) {
      var extra = {
        cpu:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"/></svg>',
        clock:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
        check:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>',
        x:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>',
        zap:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',
        globe:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
        server: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><circle cx="6" cy="6" r="1" fill="currentColor"/><circle cx="6" cy="18" r="1" fill="currentColor"/></svg>',
        key:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15.5 7.5l3 3"/></svg>',
      };
      return extra[name] || '';
    }

    /* ══════════════════════════════════════════════════════
       v4: WORKER STATUS PANEL (embedded in workspace tab)
    ══════════════════════════════════════════════════════ */
    function renderWorkerStatus(container) {
      if (!container) return;
      apiFetch('GET', '/workers').then(function(data) {
        var workers = (data && data.workers) || [];
        var html = '<div class="pdx-section"><div class="pdx-section-title">Worker Nodes (' + workers.length + ')</div>';
        if (!workers.length) {
          html += '<div class="pdx-empty">No worker nodes registered.</div>';
        } else {
          workers.forEach(function(w) {
            var statusCls = w.status === 'online' ? 'pdx-worker--online' : w.status === 'busy' ? 'pdx-worker--busy' : 'pdx-worker--offline';
            html += '<div class="pdx-worker-row ' + statusCls + '">' +
              '<div class="pdx-worker-dot"></div>' +
              '<div class="pdx-worker-info">' +
                '<div class="pdx-worker-label">' + escHtml(w.label || w.worker_id) + '</div>' +
                '<div class="pdx-worker-caps">' + (w.capabilities || []).join(', ') + '</div>' +
              '</div>' +
              '<span class="pdx-worker-status">' + escHtml(w.status || 'unknown') + '</span>' +
            '</div>';
          });
        }
        html += '</div>';
        container.innerHTML = html;
      });
    }

    /* ══════════════════════════════════════════════════════
       v4: QUEUE STATS PANEL
    ══════════════════════════════════════════════════════ */
    function renderQueueStats(container) {
      if (!container) return;
      apiFetch('GET', '/queue/stats').then(function(data) {
        if (!data) return;
        var html = '<div class="pdx-section"><div class="pdx-section-title">Job Queue</div><div class="pdx-kv-grid">';
        html += kvRow('Queued',    data.queued    || 0);
        html += kvRow('Running',   data.running   || 0);
        html += kvRow('Completed', data.completed || 0);
        html += kvRow('Failed',    data.failed    || 0);
        html += '</div></div>';
        container.innerHTML = html;
      });
    }

    /* ══════════════════════════════════════════════════════
       v4: HEATMAP RENDERER (activity by hour)
    ══════════════════════════════════════════════════════ */
    function renderHeatmap(container, hourlyData) {
      if (!container || !hourlyData) return;
      var max = Math.max.apply(null, hourlyData.map(function(d) { return d.count || 0; })) || 1;
      var html = '<div class="pdx-heatmap">';
      for (var h = 0; h < 24; h++) {
        var entry = hourlyData.find(function(d) { return d.hour === h; }) || { count: 0 };
        var intensity = Math.round((entry.count / max) * 100);
        html += '<div class="pdx-heatmap-cell" style="--intensity:' + intensity + '%" title="' + h + ':00 — ' + entry.count + ' events"></div>';
      }
      html += '</div><div class="pdx-heatmap-labels">';
      [0,6,12,18,23].forEach(function(h) { html += '<span>' + h + ':00</span>'; });
      html += '</div>';
      container.innerHTML = html;
    }

    /* ══════════════════════════════════════════════════════
       v4: WIRE UP DYNAMIC HANDLERS after tab render
    ══════════════════════════════════════════════════════ */
    var _origSetupTabs = setupTabs;
    function setupTabs(tabsId, contentId, renderers) {
      var tabsEl   = document.getElementById(tabsId);
      var contentEl = document.getElementById(contentId);
      if (!tabsEl || !contentEl) return;
      tabsEl.querySelectorAll('.pdx-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
          tabsEl.querySelectorAll('.pdx-tab').forEach(function(t) { t.classList.remove('is-active'); });
          tab.classList.add('is-active');
          var key = tab.dataset.tab;
          if (renderers[key]) {
            contentEl.innerHTML = renderers[key]();
            wireTabHandlers(tabsId, key);
          }
        });
      });
      // Wire initial tab
      var firstKey = tabsEl.querySelector('.pdx-tab.is-active');
      if (firstKey) wireTabHandlers(tabsId, firstKey.dataset.tab);
    }

    function wireTabHandlers(tabsId, tabKey) {
      // Investigation board
      if (tabsId === 'pdx-inv-tabs') {
        if (tabKey === 'correlate') wireInvCorrelate();
        if (tabKey === 'timeline')  wireInvTimeline();
      }
      // Memory
      if (tabsId === 'pdx-mem-tabs') {
        if (tabKey === 'search') wireMemSearch();
        if (tabKey === 'store')  wireMemStore();
      }
      // Team
      if (tabsId === 'pdx-team-tabs') {
        if (tabKey === 'create') wireTeamCreate();
      }
      // CVE lookup in threat tab
      if (tabsId === 'pdx-threat-tabs' && tabKey === 'cve') {
        var cveBtn = document.getElementById('pdx-cve-btn');
        var cveInp = document.getElementById('pdx-cve-input');
        if (cveBtn && cveInp) {
          cveBtn.addEventListener('click', function() {
            var q = cveInp.value.trim();
            var res = document.getElementById('pdx-cve-result');
            if (!q || !res) return;
            res.innerHTML = '<div class="pdx-loading-sm">Looking up…</div>';
            apiFetch('GET', '/threat/cve?q=' + encodeURIComponent(q)).then(function(data) {
              if (!data || !data.cves) { res.innerHTML = '<div class="pdx-empty">No results.</div>'; return; }
              res.innerHTML = data.cves.slice(0, 5).map(function(c) {
                return '<div class="pdx-cve-card">' +
                  '<div class="pdx-cve-id">' + escHtml(c.id || '') + '</div>' +
                  '<div class="pdx-cve-desc">' + escHtml((c.description || '').slice(0, 200)) + '</div>' +
                  '<div class="pdx-cve-meta">CVSS: ' + (c.cvss || 'N/A') + ' · ' + escHtml(c.published || '') + '</div>' +
                '</div>';
              }).join('');
            });
          });
          cveInp.addEventListener('keydown', function(e) { if (e.key === 'Enter') cveBtn.click(); });
        }
      }
      // Attack surface
      if (tabsId === 'pdx-threat-tabs' && tabKey === 'surface') {
        var surfBtn = document.getElementById('pdx-surface-btn');
        var surfInp = document.getElementById('pdx-surface-input');
        if (surfBtn && surfInp) {
          surfBtn.addEventListener('click', function() {
            var domain = surfInp.value.trim();
            var res = document.getElementById('pdx-surface-result');
            if (!domain || !res) return;
            res.innerHTML = '<div class="pdx-loading-sm">Mapping surface…</div>';
            apiFetch('GET', '/threat/surface?domain=' + encodeURIComponent(domain)).then(function(data) {
              if (!data) { res.innerHTML = '<div class="pdx-error">Failed.</div>'; return; }
              var html = '<div class="pdx-kv-grid">';
              html += kvRow('Open Ports', (data.ports || []).join(', ') || 'None');
              html += kvRow('Subdomains', (data.subdomains || []).slice(0,5).join(', ') || 'None');
              html += kvRow('Services', (data.services || []).join(', ') || 'None');
              html += '</div>';
              res.innerHTML = html;
            });
          });
        }
      }
    }

  } /* end init */
}());
