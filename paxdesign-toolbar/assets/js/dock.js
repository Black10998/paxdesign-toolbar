/**
 * PaxDesign Utility Dock — v7.0.0
 * Enterprise AI/Cyber SaaS dock — SSE real-time, command palette,
 * infrastructure graph, investigation board, team collaboration,
 * billing enforcement, AI memory, keyboard shortcuts.
 */
(function () {
  'use strict';

  /* ── State — must be declared before init() is called ───── */
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
    panelState: {},
  };

  var PANEL_ANIM_MS = 200;

  function panelStateStorageKey(moduleId) {
    var uid = (typeof PDX_CONFIG !== 'undefined' && PDX_CONFIG.userId) ? String(PDX_CONFIG.userId) : '0';
    return 'pdx_panel_v1_' + uid + '_' + moduleId;
  }

  function savePanelState(moduleId, payload) {
    if (!moduleId || !payload) return;
    try {
      var data = Object.assign({ savedAt: Date.now() }, payload);
      sessionStorage.setItem(panelStateStorageKey(moduleId), JSON.stringify(data));
      state.panelState[moduleId] = data;
    } catch (e) {}
  }

  function loadPanelState(moduleId) {
    if (state.panelState[moduleId]) return state.panelState[moduleId];
    try {
      var raw = sessionStorage.getItem(panelStateStorageKey(moduleId));
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function clearPanelState(moduleId) {
    try {
      sessionStorage.removeItem(panelStateStorageKey(moduleId));
    } catch (e) {}
    delete state.panelState[moduleId];
  }

  function prependRestoreBanner(container, moduleId, target, onRerun) {
    if (!container) return;
    var bar = document.createElement('div');
    bar.className = 'pdx-session-restore';
    bar.innerHTML =
      '<span>Restored <strong>' + escHtml(target || 'result') + '</strong> from this session</span>' +
      '<button type="button" class="pdx-btn-ghost pdx-btn-sm pdx-session-rerun">Run again</button>';
    var rerun = bar.querySelector('.pdx-session-rerun');
    if (rerun) {
      rerun.addEventListener('click', function () {
        clearPanelState(moduleId);
        if (onRerun) onRerun();
      });
    }
    container.insertBefore(bar, container.firstChild);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    if (typeof PDX_CONFIG === 'undefined') return;
    var C = PDX_CONFIG;
    var win = typeof window !== 'undefined' ? window : globalThis;

    var dock = document.getElementById('pdx-dock');
    if (!dock) return;

    /* ── Stamp mobile layout immediately — before any async work ── */
    // The base CSS already handles mobile layout without JS.
    // We stamp CSS custom properties here so --pdx-dock-top and
    // --pdx-panel-top are correct from the very first paint.
    // This runs synchronously so there is zero flash of wrong layout.
    var pdxRoot = document.getElementById('pdx-root');
    var bp      = C.mobileBreakpoint || 680;
    var dockH   = Math.min(72, Math.max(36, parseInt(C.mobileDockHeight, 10) || 48));

    function getAdminBarH() {
      var bar = document.getElementById('wpadminbar');
      return bar ? Math.round(bar.getBoundingClientRect().height) : 0;
    }

    function stampLayoutVars() {
      var abH      = getAdminBarH();
      var dockTop  = abH;
      var panelTop = dockTop + dockH;
      var vh       = window.innerHeight * 0.01;
      document.documentElement.style.setProperty('--pdx-dock-top',  dockTop  + 'px');
      document.documentElement.style.setProperty('--pdx-dock-h',    dockH    + 'px');
      document.documentElement.style.setProperty('--pdx-panel-top', panelTop + 'px');
      document.documentElement.style.setProperty('--pdx-vh',        vh       + 'px');
    }

    // Run immediately — synchronous, no RAF needed here.
    if (window.innerWidth <= bp) {
      stampLayoutVars();
      // Stamp data-mobile-dock so Mode B CSS fires if configured.
      var dockPos = C.mobileDockPosition || 'under-header';
      if (pdxRoot) pdxRoot.dataset.mobileDock = dockPos;
    }

    /* ── Panel + backdrop ─────────────────────────────────── */
    var backdrop = document.createElement('div');
    backdrop.id = 'pdx-backdrop';
    document.body.appendChild(backdrop);

    var panel = document.createElement('aside');
    panel.id = 'pdx-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'Tool panel');
    panel.setAttribute('aria-hidden', 'true');
    var panelInner = document.createElement('div');
    panelInner.id = 'pdx-panel-inner';
    panel.appendChild(panelInner);
    document.body.appendChild(panel);

    /* ── Module registry + panel theme (must exist before openPanel) ── */
    var PDX_KNOWN_MODULES = [
      'trust', 'osint', 'threat', 'personas', 'builder', 'pipeline', 'automation',
      'connectors', 'create', 'investigation', 'graph', 'memory', 'team', 'workspace', 'billing'
    ];

    var PDX_MODULE_ALIASES = {
      trustcheck: 'trust',
      scan: 'trust',
      workflow: 'builder',
      workflows: 'builder',
      agents: 'pipeline',
      ea: 'personas'
    };

    var MODULE_ACCENTS = {
      trust: '#ffffff', osint: '#f3f6fd', threat: '#888888', personas: '#7e7e7e',
      builder: '#555555', pipeline: '#8b8b8b', automation: '#363636',
      investigation: '#f3f6fd', graph: '#7e7e7e', memory: '#555555',
      team: '#ffffff', connectors: '#8b8b8b', create: '#7e7e7e', workspace: '#555555'
    };

    function normalizeModuleId(moduleId) {
      var id = ((moduleId && String(moduleId)) || '').toLowerCase();
      if (PDX_MODULE_ALIASES[id]) id = PDX_MODULE_ALIASES[id];
      if (PDX_KNOWN_MODULES.indexOf(id) >= 0) return id;
      if (C.modules && C.modules[id]) return id;
      return 'trust';
    }

    function resolvePanelEl() {
      return document.getElementById('pdx-panel') || panel;
    }

    function resolvePanelInner() {
      return document.getElementById('pdx-panel-inner') || panelInner;
    }

    function setPanelModuleTheme(moduleId) {
      var mid = normalizeModuleId(moduleId);
      var panelEl = resolvePanelEl();
      var innerEl = resolvePanelInner();
      if (!panelEl || !innerEl) {
        if (win.console && win.console.warn) {
          win.console.warn('[PDX] Panel DOM not ready for module:', mid);
        }
        return;
      }
      panelEl.setAttribute('data-pdx-module', mid);
      innerEl.className = 'pdx-panel-inner pdx-panel-inner--' + mid;
      document.documentElement.style.setProperty(
        '--pdx-mod-accent',
        MODULE_ACCENTS[mid] || MODULE_ACCENTS.trust
      );
    }

    function applyDockModuleIcons() {
      if (typeof win.pdxModuleIcon !== 'function') return;
      dock.querySelectorAll('.pdx-btn[data-module]').forEach(function (btn) {
        var mid = normalizeModuleId(btn.dataset.module || '');
        btn.innerHTML = win.pdxModuleIcon(mid);
        btn.className = 'pdx-btn pdx-btn--mod-' + mid + ' pdx-btn--' + mid;
        btn.setAttribute('data-module', mid);
        btn.setAttribute('type', 'button');
      });
    }

    applyDockModuleIcons();

    /* ── Notification container ───────────────────────────── */
    var notifContainer = document.createElement('div');
    notifContainer.id = 'pdx-notif';
    document.body.appendChild(notifContainer);

    /* ── Panel position — CSS-driven, no inline styles ────── */
    // Desktop: #pdx-root[data-position] selectors in CSS handle left/right.
    // Mobile:  base #pdx-panel rule is full-width, top = --pdx-panel-top.
    // No inline left/right/borderRadius set here — CSS owns all geometry.

    /* ── Open/close ───────────────────────────────────────── */
    // Track scroll position so iOS body-lock doesn't jump the page.
    var _scrollY = 0;
    var _bodyScrollLocked = false;
    var _panelFocusReturn = null;
    var _panelRenderGen = 0;

    function isMobileViewport() {
      return window.innerWidth <= (C.mobileBreakpoint || 680);
    }

    function lockBodyScroll() {
      if (_bodyScrollLocked || !isMobileViewport()) return;
      _scrollY = window.scrollY || window.pageYOffset;
      document.documentElement.style.setProperty('--pdx-scroll-lock-top', '-' + _scrollY + 'px');
      document.documentElement.classList.add('pdx-no-scroll');
      document.body.classList.add('pdx-no-scroll');
      _bodyScrollLocked = true;
    }

    function unlockBodyScroll() {
      if (!_bodyScrollLocked) return;
      document.documentElement.classList.remove('pdx-no-scroll');
      document.body.classList.remove('pdx-no-scroll');
      document.documentElement.style.removeProperty('--pdx-scroll-lock-top');
      window.scrollTo(0, _scrollY);
      _bodyScrollLocked = false;
    }

    function focusPanel() {
      panel.setAttribute('tabindex', '-1');
      var target = panel.querySelector('#pdx-panel-close') ||
        panel.querySelector('input, button, [href], textarea, select');
      if (target && target.focus) target.focus();
    }

    function showPanelLoadingShell() {
      panelInner = resolvePanelInner();
      if (!panelInner) return;
      panelInner.innerHTML =
        '<div class="pdx-panel-loading" aria-busy="true" aria-live="polite">' +
          '<div class="pdx-loading">Opening panel…</div>' +
        '</div>';
    }

    function openPanel(moduleId) {
      var mid = normalizeModuleId(moduleId);
      var renderGen = ++_panelRenderGen;
      if (state.commandPaletteOpen) closeCommandPalette();
      state.activeModule = mid;
      setPanelModuleTheme(mid);
      _panelFocusReturn = document.activeElement;
      panel.setAttribute('aria-hidden', 'false');
      backdrop.classList.add('is-entering');
      panel.classList.add('is-entering');
      requestAnimationFrame(function () {
        backdrop.classList.add('is-open');
        panel.classList.add('is-open');
        requestAnimationFrame(function () {
          backdrop.classList.remove('is-entering');
          panel.classList.remove('is-entering');
        });
      });
      lockBodyScroll();

      // Hide dock on mobile so it doesn't overlap the panel (unless admin disabled it).
      if (dock.dataset.pdxHideDock !== 'false') {
        dock.classList.add('pdx-dock--panel-open');
      }

      dock.querySelectorAll('.pdx-btn').forEach(function(b) {
        b.classList.toggle('is-active', b.dataset.module === mid);
        b.setAttribute('aria-expanded', b.dataset.module === mid ? 'true' : 'false');
      });

      // Render immediately from cached access — no blank panel while /pay/status loads.
      if (state.accessStatus) {
        renderPanel(mid);
        injectCloseBtnGlobal();
        focusPanel();
        var _bodyCached = panel.querySelector('.pdx-ph-body');
        if (_bodyCached && !state._skipPanelScrollReset) _bodyCached.scrollTop = 0;
        state._skipPanelScrollReset = false;
      } else {
        showPanelLoadingShell();
        injectCloseBtnGlobal();
      }

      // Refresh access tier/pricing in background; re-render when data arrives or changes.
      apiFetch('GET', '/pay/status').then(function(s) {
        var prevMid = state.accessStatus && state.accessStatus[mid] ? JSON.stringify(state.accessStatus[mid]) : '';
        if (s) state.accessStatus = s;
        if (renderGen !== _panelRenderGen || state.activeModule !== mid || !panel.classList.contains('is-open')) return;
        var nextMid = s && s[mid] ? JSON.stringify(s[mid]) : '';
        if (!state.accessStatus || prevMid !== nextMid || !panel.querySelector('.pdx-ph')) {
          renderPanel(mid);
          injectCloseBtnGlobal();
          focusPanel();
        }
        var _body = panel.querySelector('.pdx-ph-body');
        if (_body && !state._skipPanelScrollReset) _body.scrollTop = 0;
        state._skipPanelScrollReset = false;
      }).catch(function() {
        if (renderGen !== _panelRenderGen || state.activeModule !== mid || !panel.classList.contains('is-open')) return;
        if (!panel.querySelector('.pdx-ph')) {
          renderPanel(mid);
          injectCloseBtnGlobal();
          focusPanel();
        }
      });
      if (C.analytics) logEvent(mid, 'panel_open');
    }

    function closePanel() {
      if (panel.classList.contains('pdx-panel--closing')) return;
      if (state._paypalPoll) {
        clearInterval(state._paypalPoll);
        state._paypalPoll = null;
      }
      panel.classList.add('pdx-panel--closing');
      backdrop.classList.add('pdx-panel--closing');
      backdrop.classList.remove('is-open');
      panel.classList.remove('is-open');
      panel.setAttribute('aria-hidden', 'true');
      unlockBodyScroll();
      setTimeout(function () {
        panel.classList.remove('pdx-panel--closing');
        backdrop.classList.remove('pdx-panel--closing');
        state.activeModule = null;
      }, PANEL_ANIM_MS);

      if (_panelFocusReturn && _panelFocusReturn.focus) {
        try { _panelFocusReturn.focus(); } catch (e) {}
        _panelFocusReturn = null;
      }

      // Restore dock visibility.
      if (dock.dataset.pdxHideDock !== 'false') {
        dock.classList.remove('pdx-dock--panel-open');
      }

      dock.querySelectorAll('.pdx-btn').forEach(function(b) {
        b.classList.remove('is-active');
        b.setAttribute('aria-expanded', 'false');
      });
    }

    backdrop.addEventListener('click', closePanel);

    /* Instant press feedback on panel controls */
    panel.addEventListener('pointerdown', function (e) {
      var btn = e.target.closest('.pdx-btn-primary, .pdx-btn-ghost, .pdx-btn-sm, .pdx-graph-ctrl');
      if (btn && !btn.disabled) btn.classList.add('pdx-btn--pressed');
    }, true);
    panel.addEventListener('pointerup', function () {
      panel.querySelectorAll('.pdx-btn--pressed').forEach(function (b) {
        b.classList.remove('pdx-btn--pressed');
      });
    }, true);
    panel.addEventListener('pointercancel', function () {
      panel.querySelectorAll('.pdx-btn--pressed').forEach(function (b) {
        b.classList.remove('pdx-btn--pressed');
      });
    }, true);

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
    // Fetched once at init so the first openPanel() has state immediately.
    apiFetch('GET', '/pay/status').then(function(data) {
      if (data) state.accessStatus = data;
    });

    /* ── Load AI memory ───────────────────────────────────── */
    if (C.aiMemory) {
      apiFetch('GET', '/ai/memory?module=global').then(function(data) {
        if (data && data.memory) state.aiMemory = data.memory;
      });
    }

    /* ── Defer non-critical prefetch until idle (faster first paint / clicks) ── */
    function loadDeferredInitData() {
      apiFetch('GET', '/billing/status').then(function(data) {
        if (!data) return;
        state.billingPlan    = data.plan;
        state.billingCredits = data.credits || 0;
        updateBillingBadge();
      });

      apiFetch('GET', '/workers').then(function(data) {
        if (data && data.workers) {
          state.workers = data.workers;
          var workerEl = document.getElementById('pdx-worker-status');
          if (workerEl) renderWorkerStatus(workerEl);
        }
      });

      apiFetch('GET', '/queue/stats').then(function(data) {
        if (data) {
          state.queueStats = data;
          updateQueueBadge(data);
        }
      });

      apiFetch('GET', '/teams').then(function(data) {
        if (data && data.teams && data.teams.length) {
          state.teams = data.teams;
          state.activeTeam = data.teams[0].team_id;
        }
      });

      startLiveStreams();
    }

    if (typeof win.requestIdleCallback === 'function') {
      win.requestIdleCallback(loadDeferredInitData, { timeout: 2500 });
    } else {
      setTimeout(loadDeferredInitData, 350);
    }

    /* ── v4: SSE activity + queue (pause when tab hidden) ─── */
    function ingestActivityEvents(payload) {
      if (!payload || typeof payload !== 'object') return;
      var events = Array.isArray(payload.events)
        ? payload.events
        : (payload.module || payload.action ? [payload] : []);
      if (!events.length) return;
      if (!Array.isArray(state.liveActivity)) state.liveActivity = [];
      events.forEach(function(d) {
        if (!d || typeof d !== 'object') return;
        state.liveActivity.unshift(d);
        if (d.severity === 'critical' || d.severity === 'high') {
          showNotif('[' + (d.module || 'system') + '] ' + (d.action || ''), 'warn');
        }
      });
      if (state.liveActivity.length > 100) state.liveActivity.length = 100;
      if (state.activeModule === 'workspace') refreshActivityFeed();
    }

    function onActivitySSE(evt) {
      try { ingestActivityEvents(JSON.parse(evt.data)); } catch (e) {}
    }

    function onQueueSSE(evt) {
      try {
        var d = JSON.parse(evt.data);
        if (!d || typeof d !== 'object') return;
        state.queueStats = d;
        updateQueueBadge(d);
      } catch (e) {}
    }

    function startSSEChannels() {
      if (C.sseEnabled === false || !window.EventSource || !C.restUrl) return;
      startSSE('activity', onActivitySSE);
      startSSE('queue', onQueueSSE);
    }

    function startLiveStreams() {
      startSSEChannels();
    }

    document.addEventListener('visibilitychange', function() {
      if (document.hidden) {
        Object.keys(state.sseConnections || {}).forEach(function(ch) { stopSSE(ch); });
      } else {
        startSSEChannels();
      }
    });

    /* ── v4: Command palette DOM ──────────────────────────── */
    buildCommandPalette();

    /* ── v4: Billing badge in dock ────────────────────────── */
    buildBillingBadge();
    buildQueueBadge();

    /* Accent + breakpoint tokens from admin settings */
    if (C.accentColor) {
      var accent = C.accentColor;
      document.documentElement.style.setProperty('--pdx-accent', accent);
      if (pdxRoot) pdxRoot.style.setProperty('--pdx-accent', accent);
    }
    var mobileBp = C.mobileBreakpoint || 680;
    document.documentElement.style.setProperty('--pdx-mobile-max', mobileBp + 'px');
    document.documentElement.style.setProperty('--pdx-mobile-min', (mobileBp + 1) + 'px');

    window.addEventListener('pagehide', function() {
      Object.keys(state.sseConnections || {}).forEach(function(ch) { stopSSE(ch); });
    });

    /* ── Close button ─────────────────────────────────────────────
       Injected into #pdx-panel-inner (NOT .pdx-ph-hd) so it is never
       clipped by overflow:hidden on .pdx-ph or any ancestor.
       position:absolute on the button + position:relative on #pdx-panel-inner
       keeps it pinned top-right above all content at all times.
    ─────────────────────────────────────────────────────────────── */
    var _closeSvg = '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="1" y1="1" x2="13" y2="13"/><line x1="13" y1="1" x2="1" y2="13"/></svg>';

    function injectCloseBtnGlobal() {
      panelInner = resolvePanelInner();
      if (!panelInner) return;
      // Always remove any existing close button first — renderPanel replaces
      // panelInner.innerHTML so any previously appended button is already gone,
      // but guard against double-injection on rapid calls.
      var existing = panelInner.querySelector('.pdx-mobile-close');
      if (existing) existing.remove();

      // Only inject when a panel module is rendered (.pdx-ph exists).
      if (!panelInner.querySelector('.pdx-ph')) return;

      var btn = document.createElement('button');
      btn.className = 'pdx-panel-close';
      btn.type = 'button';
      btn.setAttribute('aria-label', 'Close panel');
      btn.innerHTML = _closeSvg;
      btn.addEventListener('click', closePanel);
      // Append to inner (not .pdx-ph-hd) — avoids overflow:hidden clipping.
      // position:absolute on the button + position:relative on #pdx-panel-inner
      // keeps it pinned top-right above all content at all times.
      panelInner.appendChild(btn);
    }

    /* ── Mobile ───────────────────────────────────────────── */
    if (C.mobileEnabled) setupMobile(C, panel, dock);

    function bindClickOnce(el, handler) {
      if (!el || el.dataset.pdxBound) return;
      el.dataset.pdxBound = '1';
      el.addEventListener('click', handler);
    }

    function modIcon(moduleId) {
      var id = normalizeModuleId(moduleId);
      if (typeof win.pdxModuleIcon === 'function') {
        return win.pdxModuleIcon(id);
      }
      return svgIcon(id);
    }

    function renderPhishingIntelHero(forensics, urlFx, phish) {
      forensics = forensics || {};
      urlFx = urlFx || {};
      phish = phish || {};
      var score = forensics.phishing_score != null ? forensics.phishing_score : (phish.score != null ? phish.score : null);
      if (score == null && !urlFx.redirect_count && !(phish.reasons && phish.reasons.length)) return '';

      var verdict = (forensics.phishing_verdict || phish.verdict || 'unknown').toLowerCase();
      if (verdict === 'skipped') return '';
      var heroCls = verdict === 'clean' ? 'pdx-intel-hero--clean' : (verdict === 'low' || verdict === 'unknown' ? 'pdx-intel-hero--warn' : (verdict === 'medium' ? 'pdx-intel-hero--warn' : ''));
      var html = '<div class="pdx-intel-hero ' + heroCls + '">' +
        '<div class="pdx-intel-hero__head">' + modIcon('threat') +
          '<span class="pdx-intel-hero__title">Phishing & URL intelligence</span>';
      if (score != null) html += '<span class="pdx-intel-hero__score">' + escHtml(String(score)) + '</span>';
      html += '</div><div class="pdx-intel-hero__grid">';
      if (urlFx.redirect_count) html += '<span class="pdx-intel-hero__chip">Redirects: ' + escHtml(String(urlFx.redirect_count)) + '</span>';
      if (forensics.has_login_form) html += '<span class="pdx-intel-hero__chip">Credential form</span>';
      if (forensics.brand_impersonation) html += '<span class="pdx-intel-hero__chip">Brand impersonation</span>';
      if (forensics.punycode_detected) html += '<span class="pdx-intel-hero__chip">Punycode / IDN</span>';
      if (forensics.infrastructure_fingerprint) html += '<span class="pdx-intel-hero__chip">Infra fingerprint</span>';
      html += '<span class="pdx-intel-hero__chip">Verdict: ' + escHtml(verdict) + '</span></div></div>';
      return html.replace(/<motion\.div/g, '<div').replace(/<\/motion\.motion\.div>/g, '</div>');
    }

    /* ── Panel renderer ───────────────────────────────────── */
    function renderPanel(moduleId) {
      var mid = normalizeModuleId(moduleId);
      if (!mid) return;
      panelInner = resolvePanelInner();
      if (!panelInner) return;
      setPanelModuleTheme(mid);
      var mod = (C.modules && C.modules[mid]) || null;
      if (!mod) { panelInner.innerHTML = '<div class="pdx-empty">Module not found.</div>'; return; }

      // access comes from /pay/status — always live, never stale.
      // Merge live tier/price into mod so all render functions see current values.
      var access = (state.accessStatus && state.accessStatus[mid]) || {};
      var locked = access.status === 'locked';

      // Sync mod tier/price/description from live access status so paywall shows correct values.
      // access.price can be 0 (free), so check != null not just truthiness.
      var liveOverrides = {};
      if (access.tier)              liveOverrides.tier        = access.tier;
      if (access.price != null)     liveOverrides.price       = access.price;
      if (access.currency)          liveOverrides.currency    = access.currency;
      if (access.description)       liveOverrides.description = access.description;
      if (Object.keys(liveOverrides).length) mod = Object.assign({}, mod, liveOverrides);

      switch (mid) {
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
        default:               panelInner.innerHTML = '<div class="pdx-empty">Coming soon.</div>';
      }
    }


    /* ══════════════════════════════════════════════════════
       TRUST CHECK  — Deep Analysis UX
    ══════════════════════════════════════════════════════ */
    function renderTrust(mod, access) {
      panelInner.innerHTML =
        '<div class="pdx-ph pdx-ph--trust">' +
          '<div class="pdx-ph-hd pdx-ph-hd--trust">' +
            '<div class="pdx-ph-title pdx-ph-title--trust">' + modIcon('trust') + '<span>TrustCheck</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="System online"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Analyze domain reputation, SSL posture, infrastructure trust signals, DNS configuration, and behavioral indicators to identify potential risks.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag pdx-cap-tag--intel">Phishing Heuristics</span>' +
              '<span class="pdx-cap-tag">RDAP/WHOIS</span>' +
              '<span class="pdx-cap-tag">SSL/TLS</span>' +
              '<span class="pdx-cap-tag">DNS Analysis</span>' +
              '<span class="pdx-cap-tag">Threat Feeds</span>' +
              '<span class="pdx-cap-tag">Behavioral</span>' +
              '<span class="pdx-cap-tag">Risk Scoring</span>' +
            '</div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-input-row">' +
              '<input id="pdx-trust-input" class="pdx-input" type="text" placeholder="domain.com or IP address" autocomplete="off" spellcheck="false"/>' +
              '<button id="pdx-trust-btn" class="pdx-btn-primary pdx-btn-primary--trust">Analyze</button>' +
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
      restoreTrustPanel();
    }

    function restoreTrustPanel() {
      var saved = loadPanelState('trust');
      if (!saved || saved.view !== 'result' || !saved.data) return;
      var input = document.getElementById('pdx-trust-input');
      var result = document.getElementById('pdx-trust-result');
      if (input && saved.target) input.value = saved.target;
      if (!result) return;
      state._skipPanelScrollReset = true;
      renderTrustResult(result, saved.data, saved.target);
      prependRestoreBanner(result, 'trust', saved.target, function () {
        var inp = document.getElementById('pdx-trust-input');
        if (inp && saved.target) inp.value = saved.target;
        runTrustScan();
      });
    }

    function runTrustScan() {
      var input  = document.getElementById('pdx-trust-input');
      var result = document.getElementById('pdx-trust-result');
      var btn    = document.getElementById('pdx-trust-btn');
      if (!input || !result) return;
      var norm = normalizePdxTarget(input.value);
      if (!norm.host) { showNotif('Enter a valid domain, IP, or URL', 'warn'); return; }
      var domain = applyNormalizedInput(input, norm);
      clearPanelState('trust');

      var trustStages = [
        { label: 'Initializing intelligence pipeline',    detail: 'Loading analysis modules and threat databases',              duration: 520 },
        { label: 'Collecting WHOIS / RDAP records',       detail: 'Querying regional registries for registration data',         duration: 820 },
        { label: 'Analyzing SSL/TLS posture',             detail: 'Inspecting certificate chain, cipher suites, and expiry',    duration: 900 },
        { label: 'Inspecting DNS infrastructure',         detail: 'Resolving A, MX, NS, TXT, SPF, and DMARC records',          duration: 740 },
        { label: 'Querying threat intelligence feeds',    detail: 'Cross-referencing AlienVault OTX, Abuse.ch, CISA KEV',      duration: 980 },
        { label: 'URL forensics & redirect chain',        detail: 'Following redirects, HTML/JS/form phishing signals',       duration: 720 },
        { label: 'Correlating behavioral indicators',     detail: 'Analyzing registration patterns and infrastructure signals', duration: 700 },
        { label: 'Building reputation profile',           detail: 'Aggregating multi-source trust signals',                    duration: 610 },
        { label: 'Calculating anomaly confidence',        detail: 'Running statistical deviation analysis',                    duration: 660 },
        { label: 'Generating risk assessment',            detail: 'Compiling final intelligence report',                       duration: 500 },
      ];

      runIntelPipeline({
        btn: btn,
        result: result,
        module: 'trust',
        id: 'pdx-trust-pipeline',
        stages: trustStages,
        title: 'TrustCheck — ' + domain,
        busyLabel: 'Analyzing…',
        logLines: [
          'TrustCheck pipeline initialized for: ' + domain,
          'Connecting to RDAP bootstrap registry…',
          'Fetching SSL Labs assessment endpoint…',
          'Resolving DNS records via recursive resolver…',
          'Querying AlienVault OTX pulse database…',
          'Running behavioral pattern correlation engine…',
          'Aggregating multi-source reputation signals…',
          'Computing anomaly deviation scores…',
          'Finalizing risk verdict and confidence score…',
        ],
        api: function () { return apiFetch('GET', '/trust?domain=' + encodeURIComponent(domain)); },
        onSuccess: function (el, data) { finalizeTrustResult(el, data, domain); },
        errorMsg: 'Scan failed. Check the domain and try again.',
      });
    }

    function finalizeTrustResult(result, data, domain) {
      if (!data) { result.innerHTML = '<div class="pdx-error">Scan failed. Check the domain and try again.</div>'; return; }
      var scanTarget = data.target || domain;
      renderTrustResult(result, data, scanTarget);
      savePanelState('trust', {
        view: 'result',
        target: scanTarget,
        data: data,
        paymentRequired: data.error === 'payment_required',
      });
      addToScanHistory('trust', scanTarget, data.risk);
      renderScanHistory('trust');
      if (data.workspace_id) showNotif('Scan saved to workspace', 'info');
      if (data.anomalies && data.anomalies.length) showNotif('Anomaly detected: ' + data.anomalies[0].message, 'warn');
    }

    function renderTrustResult(container, data, domain) {
      var displayTarget = (data && data.target) ? data.target : domain;
      var targetType = (data && data.target_type) || detectTargetTypeFromString(displayTarget);
      var risk      = data.risk      || {};
      var score     = risk.score     != null ? risk.score : 0;
      var verdict   = risk.verdict   || 'insufficient_data';
      var src       = data.sources   || {};
      var rdap      = src.rdap       || {};
      var ssl       = src.ssl        || {};
      var dns       = src.dns        || {};
      var threat    = src.threat     || {};
      var vt        = src.virustotal || {};
      if (!threat.checked && vt.malicious !== undefined) {
        threat = {
          malicious: vt.malicious,
          suspicious: vt.suspicious,
          harmless: vt.harmless,
          feeds: ['VirusTotal'],
          categories: vt.categories || [],
          checked: true
        };
      }
      var geo       = src.geo || src.geolocation || {};
      var ipNetwork = src.ip_network || {};
      var reverseDns = src.reverse_dns || {};
      var hibp      = src.hibp || {};
      var srcStatus = data.source_status || {};
      var banner    = scanBannerMeta(data, displayTarget, 'Analysis complete');
      var unverified = isReportUnverified(data);
      var partial    = isReportPartial(data);
      var displayScore = unverified ? (risk.indicative_score != null ? risk.indicative_score : 0) : score;
      var scoreLabel = unverified ? (risk.indicative_score != null && risk.indicative_score !== score ? 'Indic.' : 'N/A') : 'Risk';
      if (unverified && risk.indicative_score == null) displayScore = 0;
      var anomalies = data.anomalies  || [];
      var behavioral= data.behavioral || [];
      var confidence= data.confidence != null ? data.confidence : (risk.confidence || 0);

      /* Filter risk factors that don't apply to this target type */
      if (risk.factors && targetType === 'email') {
        risk.factors = risk.factors.filter(function(f) {
          var name = (f.factor || '').toLowerCase();
          return !name.includes('ssl') && !name.includes('tls') && !name.includes('certificate') && !name.includes('dns');
        });
      }
      if (risk.factors && targetType === 'ip') {
        risk.factors = risk.factors.filter(function(f) {
          var name = (f.factor || '').toLowerCase();
          return !name.includes('ssl') && !name.includes('registrar') && !name.includes('domain age');
        });
      }

      var scoreColor = verdict === 'clean' ? 'var(--pdx-green)' : verdict === 'low' ? '#7e7e7e' : verdict === 'medium' ? 'var(--pdx-yellow)' : verdict === 'insufficient_data' ? 'var(--pdx-lo)' : 'var(--pdx-red)';
      var verdictLabel = risk.label || (
        verdict === 'clean' ? 'Clean' :
        verdict === 'low' ? 'Low Risk' :
        verdict === 'medium' ? 'Medium Risk' :
        verdict === 'high' ? 'High Risk' :
        verdict === 'insufficient_data' ? 'Insufficient Data' :
        'Critical'
      );

      var html = '<div class="pdx-result">';

      /* ── Scan complete banner ── */
      html += '<div class="pdx-scan-complete' + (banner.warn ? ' pdx-scan-complete--warn' : '') + '">' +
        '<div class="pdx-scan-complete-dot"></div>' +
        '<span>' + escHtml(banner.message) + '</span>' +
        '<span class="pdx-scan-complete-time">' + (data.duration ? data.duration + 's' : '') + '</span>' +
      '</div>';

      var forensicsEarly = data.forensics || {};
      var urlFxEarly = (src && src.url_forensics) || {};
      var phishEarly = urlFxEarly.phishing || {};
      html += renderPhishingIntelHero(forensicsEarly, urlFxEarly, phishEarly);

      /* ── Risk header with score ring ── */
      var circumference = 2 * Math.PI * 26; // r=26
      var dashOffset = unverified && risk.indicative_score == null
        ? circumference
        : circumference - (displayScore / 100) * circumference;
      var ringStroke = unverified ? '#888888' : (verdict === 'clean' ? '#ffffff' : verdict === 'low' ? '#7e7e7e' : verdict === 'medium' ? '#7e7e7e' : verdict === 'insufficient_data' ? '#888888' : '#888888');
      html += '<div class="pdx-risk-header' + (unverified ? ' pdx-risk-header--unverified' : '') + (partial ? ' pdx-risk-header--partial' : '') + '">' +
        '<div class="pdx-risk-ring">' +
          '<svg viewBox="0 0 64 64"><circle class="pdx-risk-ring-track" cx="32" cy="32" r="26"/>' +
          '<circle class="pdx-risk-ring-fill" cx="32" cy="32" r="26" stroke="' + ringStroke + '" stroke-dasharray="' + circumference.toFixed(1) + '" stroke-dashoffset="' + dashOffset.toFixed(1) + '"/></svg>' +
          '<div class="pdx-risk-ring-label"><div class="pdx-risk-ring-num">' + (unverified && risk.indicative_score == null ? '—' : displayScore) + '</div><div class="pdx-risk-ring-text">' + scoreLabel + '</div></div>' +
        '</div>' +
        '<div class="pdx-risk-meta">' +
          '<div class="pdx-risk-domain">' + escHtml(displayTarget) + '</div>' +
          '<div style="margin-top:4px"><span class="pdx-tag" style="background:' + ringStroke + '22;color:' + ringStroke + ';border-color:' + ringStroke + '44">' + verdictLabel + '</span></div>' +
          (unverified ? '<div style="margin-top:6px;font-size:11px;color:var(--pdx-lo)">Not a verified assessment</div>' : (partial ? '<div style="margin-top:6px;font-size:11px;color:var(--pdx-yellow)">Partial assessment</div>' : '')) +
          (data.scan_id ? '<div class="pdx-risk-scan-id" style="margin-top:6px">Scan ID: ' + escHtml(data.scan_id) + '</div>' : '') +
        '</div>' +
        '<div class="pdx-trust-actions">' +
          '<button class="pdx-btn-ghost pdx-btn-sm pdx-trust-rescan-btn">Re-scan</button>' +
          '<button class="pdx-btn-ghost pdx-btn-sm pdx-trust-new-target-btn">New Target</button>' +
          '<button class="pdx-btn-ghost pdx-btn-sm pdx-export-btn">Export</button>' +
        '</div>' +
      '</div>';

      html += renderCoveragePanel(data, srcStatus);

      /* ── Confidence bar ── */
      if (confidence > 0 || unverified) {
        var confNote = unverified ? ' (incomplete)' : (partial ? ' (partial)' : '');
        html += '<div class="pdx-confidence-bar">' +
          '<span class="pdx-confidence-label">Confidence' + confNote + '</span>' +
          '<div class="pdx-confidence-track"><div class="pdx-confidence-fill" style="width:' + confidence + '%"></div></div>' +
          '<span class="pdx-confidence-pct">' + confidence + '%</span>' +
        '</div>';
      }

      /* ── Risk factors ── */
      if (risk.factors && risk.factors.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Risk Factors (' + risk.factors.length + ')</div><div class="pdx-factors">';
        risk.factors.forEach(function(f) {
          var cls = f.weight === 'critical' ? 'pdx-factor--critical' : f.weight === 'high' ? 'pdx-factor--high' : f.weight === 'medium' ? 'pdx-factor--medium' : 'pdx-factor--low';
          html += '<div class="pdx-factor ' + cls + '">' +
            '<span class="pdx-factor-name">' + escHtml(f.factor) + '</span>' +
            '<span class="pdx-factor-val">' + escHtml(safeStr(f.value)) + '</span>' +
            '<span class="pdx-factor-risk">+' + (f.risk || 0) + '</span>' +
          '</div>';
        });
        html += '</div></div>';
      }

      /* ── Anomalies ── */
      if (anomalies.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">⚠ Anomalies Detected (' + anomalies.length + ')</div>';
        anomalies.forEach(function(a) {
          html += '<div class="pdx-anomaly">' + svgIcon('alert') + '<span>' + escHtml(a.message || safeStr(a)) + '</span></div>';
        });
        html += '</div>';
      }

      /* ── URL forensics (v8) ── */
      var forensics = data.forensics || {};
      var urlFx = src.url_forensics || {};
      var phish = urlFx.phishing || {};
      if (forensics.phishing_score || urlFx.redirect_count || (phish.reasons && phish.reasons.length)) {
        html += '<div class="pdx-section"><div class="pdx-section-title">URL Forensics & Redirect Chain</div><div class="pdx-kv-grid">';
        if (urlFx.redirect_count) html += kvRow('Redirect hops', String(urlFx.redirect_count));
        if (urlFx.final_url) html += kvRow('Final URL', urlFx.final_url);
        if (forensics.phishing_score != null) html += kvRow('Phishing score', forensics.phishing_score + ' (' + (forensics.phishing_verdict || phish.verdict || 'n/a') + ')');
        if (forensics.has_login_form) html += kvRow('Credential form', 'Detected on page');
        html += '</div>';
        if (urlFx.redirect_chain && urlFx.redirect_chain.length) {
          html += '<div class="pdx-timeline pdx-mt-sm">';
          urlFx.redirect_chain.forEach(function(hop, i) {
            html += '<div class="pdx-timeline-item"><span class="pdx-timeline-ts">Hop ' + (i + 1) + '</span> HTTP ' + escHtml(String(hop.code || '?')) + ' → ' + escHtml(hop.url || '') + '</div>';
          });
          html += '</div>';
        }
        var phishReasons = forensics.phishing_reasons || phish.reasons || [];
        if (phishReasons.length) {
          html += '<div class="pdx-factors pdx-mt-sm">';
          phishReasons.forEach(function(r) {
            html += '<div class="pdx-factor pdx-factor--medium"><span class="pdx-factor-name">' + escHtml(r) + '</span></div>';
          });
          html += '</div>';
        }
        html += '</div>';
      }

      /* ── IP Network Registration (IP targets only) ── */
      if (targetType === 'ip' && (ipNetwork.organization || ipNetwork.cidr || ipNetwork.handle || srcStatus.ip_network)) {
        html += '<div class="pdx-evidence-section" id="pdx-trust-ip-network">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'IP Network Registration <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        if (ipNetwork.organization) html += kvRow('Organization', ipNetwork.organization);
        if (ipNetwork.cidr)         html += kvRow('CIDR', ipNetwork.cidr);
        if (ipNetwork.asn)          html += kvRow('ASN', ipNetwork.asn);
        if (ipNetwork.registry)     html += kvRow('Registry', ipNetwork.registry);
        if (ipNetwork.handle)       html += kvRow('Handle', ipNetwork.handle);
        if (ipNetwork.start_address) html += kvRow('Start Address', ipNetwork.start_address);
        if (ipNetwork.end_address)   html += kvRow('End Address', ipNetwork.end_address);
        if (ipNetwork.country)       html += kvRow('Country', ipNetwork.country);
        if (ipNetwork.status)        html += kvRow('Status', Array.isArray(ipNetwork.status) ? ipNetwork.status.join(', ') : ipNetwork.status);
        if (srcStatus.ip_network && srcStatus.ip_network.state === 'error') {
          html += kvRow('Lookup status', formatSourceStatusNote(srcStatus.ip_network) || 'Registration lookup failed');
        }
        html += '</div></div></div>';
      }

      /* ── Reverse DNS (IP targets only — separate from network registration) ── */
      if (targetType === 'ip' && (reverseDns.hostname || reverseDns.no_record || srcStatus.reverse_dns)) {
        html += '<div class="pdx-evidence-section" id="pdx-trust-reverse-dns">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'Reverse DNS (PTR) <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        if (reverseDns.hostname) html += kvRow('PTR Hostname', reverseDns.hostname);
        else if (reverseDns.no_record) html += kvRow('PTR Record', 'No reverse DNS record');
        else html += kvRow('PTR Record', formatSourceStatusNote(srcStatus.reverse_dns) || 'Unavailable');
        if (reverseDns.hostname) html += kvRow('Note', 'PTR hostname reflects DNS naming — not IP network registration ownership.');
        html += '</div></div></div>';
      }

      /* ── RDAP / Domain Registration (non-IP targets) ── */
      if (targetType !== 'ip' && (rdap.registrar || rdap.registered)) {
        html += '<div class="pdx-evidence-section" id="pdx-trust-rdap">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'Registration & WHOIS <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        if (rdap.registrar)   html += kvRow('Registrar',    rdap.registrar);
        if (rdap.registered)  html += kvRow('Registered',   rdap.registered);
        if (rdap.expires)     html += kvRow('Expires',      rdap.expires);
        if (rdap.age_days !== undefined) html += kvRow('Domain Age', rdap.age_days + ' days' + (rdap.age_days < 30 ? ' ⚠ Very new' : rdap.age_days < 180 ? ' ⚠ Recent' : ''));
        if (rdap.registrant)  html += kvRow('Registrant',   rdap.registrant);
        if (rdap.country)     html += kvRow('Country',      rdap.country);
        if (rdap.nameservers && rdap.nameservers.length) html += kvRow('Nameservers', rdap.nameservers.slice(0,4).join('<br>'));
        if (rdap.status)      html += kvRow('Status',       Array.isArray(rdap.status) ? rdap.status.join(', ') : rdap.status);
        html += '</div></div></div>';
      }

      /* ── SSL / TLS (not applicable to raw IPs) ── */
      if (targetType !== 'ip' && (ssl.grade || ssl.issuer || ssl.subject)) {
        var gradeClass = (ssl.grade === 'A+' || ssl.grade === 'A') ? 'pdx-grade--good' : ssl.grade === 'B' ? 'pdx-grade--warn' : 'pdx-grade--bad';
        html += '<div class="pdx-evidence-section" id="pdx-trust-ssl">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'SSL / TLS Certificate <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        if (ssl.grade)   html += '<div class="pdx-kv-row"><span class="pdx-kv-key">Grade</span><span class="pdx-ssl-grade ' + gradeClass + '">' + escHtml(ssl.grade) + '</span></div>';
        if (ssl.status)  html += kvRow('Status',  ssl.status);
        if (ssl.issuer)  html += kvRow('Issuer',  ssl.issuer);
        if (ssl.subject) html += kvRow('Subject', ssl.subject);
        if (ssl.valid_from) html += kvRow('Valid From', ssl.valid_from);
        if (ssl.valid_to)   html += kvRow('Valid To',   ssl.valid_to + (ssl.days_remaining !== undefined ? ' (' + ssl.days_remaining + ' days)' : ''));
        if (ssl.protocol)   html += kvRow('Protocol',  ssl.protocol);
        if (ssl.cipher)     html += kvRow('Cipher',    ssl.cipher);
        if (ssl.endpoints && ssl.endpoints.length) {
          ssl.endpoints.slice(0,3).forEach(function(ep, i) {
            html += kvRow('Endpoint ' + (i+1), (ep.ip || '') + (ep.grade ? ' — Grade ' + ep.grade : ''));
          });
        }
        html += '</div></div></div>';
      }

      /* ── DNS Infrastructure (not applicable to raw IPs) ── */
      if (targetType !== 'ip' && (dns.a || dns.mx || dns.ns || dns.txt)) {
        html += '<div class="pdx-evidence-section" id="pdx-trust-dns">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'DNS Infrastructure <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        if (dns.a  && dns.a.length)  html += kvRow('A Records',  dns.a.slice(0,4).join(', '));
        if (dns.mx && dns.mx.length) html += kvRow('MX Records', dns.mx.slice(0,3).join(', '));
        if (dns.ns && dns.ns.length) html += kvRow('NS Records', dns.ns.slice(0,4).join(', '));
        if (dns.txt && dns.txt.length) html += kvRow('TXT Records', dns.txt.slice(0,2).join(' | '));
        if (dns.spf)   html += kvRow('SPF',   dns.spf);
        if (dns.dmarc) html += kvRow('DMARC', dns.dmarc);
        if (dns.caa)   html += kvRow('CAA',   dns.caa);
        html += '</div></div></div>';
      }

      /* ── Threat Intelligence ── */
      if (threat.checked || srcStatus.threat || threat.malicious !== undefined) {
        html += '<div class="pdx-evidence-section" id="pdx-trust-threat">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'Threat Intelligence <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        if (!threat.checked) {
          html += kvRow('Reputation', formatSourceStatusNote(srcStatus.threat) || 'Threat feeds did not respond — not verified clean');
        } else {
          if (threat.malicious !== undefined) html += kvRow('Malicious', formatThreatBool(threat.malicious, true));
          if (threat.suspicious !== undefined) html += kvRow('Suspicious', formatThreatBool(threat.suspicious, true));
          if (threat.harmless !== undefined)   html += kvRow('Harmless engines', safeStr(threat.harmless));
          if (threat.feeds && threat.feeds.length) html += kvRow('Feed hits', threat.feeds.slice(0,3).join(', '));
          if (threat.categories && threat.categories.length) html += kvRow('Categories', threat.categories.join(', '));
          if (threat.last_seen) html += kvRow('Last seen', threat.last_seen);
        }
        html += '</div></div></div>';
      }

      /* ── Behavioral Signals ── */
      if (behavioral.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Behavioral Signals</div>';
        behavioral.forEach(function(s) {
          var cls = s.type === 'positive' ? 'pdx-signal--pos' : s.type === 'negative' ? 'pdx-signal--neg' : 'pdx-signal--neutral';
          html += '<div class="pdx-signal ' + cls + '">' + escHtml(s.signal || safeStr(s)) + '</div>';
        });
        html += '</div>';
      }

      /* ── Intelligence Sources ── */
      html += '<div class="pdx-section"><div class="pdx-section-title">Intelligence Sources</div>';
      var sourceRows = buildIntelSourceRows(targetType, {
        srcStatus: srcStatus,
        rdap: rdap,
        ssl: ssl,
        dns: dns,
        threat: threat,
        geo: geo,
        ipNetwork: ipNetwork,
        reverseDns: reverseDns
      });
      sourceRows.forEach(function(s) {
        html += '<div class="pdx-source-row">' +
          '<div class="pdx-source-dot" style="background:' + sourceDotColor(s.status) + '"></div>' +
          '<span class="pdx-source-name">' + escHtml(s.name) + '</span>' +
          '<span class="pdx-source-status pdx-source-status--' + s.status + '">' + escHtml(s.note) + '</span>' +
        '</div>';
      });
      html += '</div>';

      /* ── Email-specific: breach data ── */
      if (targetType === 'email' && (hibp.breached !== undefined || hibp.breach_count)) {
        html += '<div class="pdx-section">' +
          '<div class="pdx-section-title' + (hibp.breached ? ' pdx-section-title--warn' : '') + '">' +
            (hibp.breached ? '⚠ Data Breach Exposure' : '✓ No Known Breaches') +
          '</div>';
        if (hibp.breached) {
          html += '<div class="pdx-kv-grid">';
          if (hibp.breach_count) html += kvRow('Breaches Found', hibp.breach_count + ' known breach' + (hibp.breach_count !== 1 ? 'es' : ''));
          if (hibp.breaches && hibp.breaches.length) {
            hibp.breaches.slice(0,5).forEach(function(b) {
              var name = typeof b === 'object' ? (b.Name || b.name || safeStr(b)) : safeStr(b);
              var date = typeof b === 'object' ? (b.BreachDate || b.date || '') : '';
              html += kvRow(name, date || 'Compromised');
            });
          }
          html += '</div>';
        }
        html += '</div>';
      }

      /* ── IP-specific: geolocation ── */
      if (targetType === 'ip' && (geo.country || geo.city || geo.org)) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Geolocation & Network</div><div class="pdx-kv-grid">';
        if (geo.country)  html += kvRow('Country',      geo.country);
        if (geo.city)     html += kvRow('City',         geo.city);
        if (geo.org)      html += kvRow('Organisation', geo.org);
        if (geo.asn)      html += kvRow('ASN',          geo.asn);
        if (geo.isp)      html += kvRow('ISP',          geo.isp);
        if (geo.lat && geo.lon) html += kvRow('Coordinates', geo.lat + ', ' + geo.lon);
        if (geo.hosting !== undefined) html += kvRow('Hosting / VPS', geo.hosting ? 'Yes' : 'No');
        if (geo.proxy !== undefined)   html += kvRow('Proxy / VPN',   geo.proxy   ? '⚠ Yes' : 'No');
        if (geo.tor !== undefined)     html += kvRow('Tor Exit Node', geo.tor     ? '⚠ Yes' : 'No');
        html += '</div></div>';
      }

      /* ── AI Intelligence Summary (always shown) ── */
      var summaryText = data.ai_summary || generateSummary(targetType, displayTarget, data);
      var recs = (data.recommendations && data.recommendations.length)
        ? data.recommendations.map(safeStr)
        : generateRecommendations(targetType, data);

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header">' +
          '<span class="pdx-report-summary-icon">' + svgIcon('report-trust') + '</span>' +
          '<span class="pdx-report-summary-title">Intelligence Summary</span>' +
        '</div>' +
        '<div class="pdx-report-summary-text">' + escHtml(summaryText) + '</div>' +
        (recs.length ? '<div class="pdx-report-recs"><div class="pdx-report-recs-title">Recommendations</div><ul class="pdx-report-recs-list">' +
          recs.map(function(r) { return '<li>' + escHtml(r) + '</li>'; }).join('') +
        '</ul></div>' : '') +
      '</div>';

      /* ── Raw data (collapsed, developer only) ── */
      html += rawSection('Raw Response', data);

      html += '</div>';
      container.innerHTML = html;

      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('trust-' + displayTarget.replace(/[^a-z0-9.-]+/gi, '_'), data); });
      var rescanBtn = container.querySelector('.pdx-trust-rescan-btn');
      if (rescanBtn) {
        rescanBtn.addEventListener('click', function() {
          var input = document.getElementById('pdx-trust-input');
          if (input) input.value = displayTarget || input.value;
          runTrustScan();
        });
      }
      var newTargetBtn = container.querySelector('.pdx-trust-new-target-btn');
      if (newTargetBtn) {
        newTargetBtn.addEventListener('click', function() {
          var input = document.getElementById('pdx-trust-input');
          if (!input) return;
          input.focus();
          input.select();
        });
      }
    }


    /* ══════════════════════════════════════════════════════
       OSINT AGENTS
    ══════════════════════════════════════════════════════ */
    function renderOsint(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      panelInner.innerHTML =
        '<div class="pdx-ph pdx-ph--osint">' +
          '<div class="pdx-ph-hd pdx-ph-hd--osint">' +
            '<div class="pdx-ph-title pdx-ph-title--osint">' + modIcon('osint') + '<span>OSINT Agents</span>' + (mod.badge ? '<span class="pdx-badge">' + mod.badge + '</span>' : '') +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="System online"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Deep intelligence gathering across domain, IP geolocation, VirusTotal, Shodan, email discovery, IOC extraction, and timeline reconstruction from multiple open-source feeds.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag pdx-cap-tag--intel">Live Intel Pipeline</span>' +
              '<span class="pdx-cap-tag">Domain Intel</span>' +
              '<span class="pdx-cap-tag">IP Geolocation</span>' +
              '<span class="pdx-cap-tag">VirusTotal</span>' +
              '<span class="pdx-cap-tag">Shodan</span>' +
              '<span class="pdx-cap-tag">Email Discovery</span>' +
              '<span class="pdx-cap-tag">IOC Extraction</span>' +
            '</div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-input-row">' +
              '<input id="pdx-osint-input" class="pdx-input" type="text" placeholder="domain.com, IP, or email" autocomplete="off" spellcheck="false"/>' +
              '<button id="pdx-osint-btn" class="pdx-btn-primary pdx-btn-primary--osint">Investigate</button>' +
            '</div>' +
            '<div id="pdx-osint-result"></div>' +
          '</div>' +
        '</div>';

      document.getElementById('pdx-osint-btn').addEventListener('click', runOsintScan);
      document.getElementById('pdx-osint-input').addEventListener('keydown', function(e) { if (e.key === 'Enter') runOsintScan(); });
      restoreOsintPanel();
    }

    function restoreOsintPanel() {
      var saved = loadPanelState('osint');
      if (!saved || saved.view !== 'result' || !saved.data) return;
      var input = document.getElementById('pdx-osint-input');
      var result = document.getElementById('pdx-osint-result');
      if (input && saved.target) input.value = saved.target;
      if (!result) return;
      state._skipPanelScrollReset = true;
      renderOsintResult(result, saved.data, saved.target);
      prependRestoreBanner(result, 'osint', saved.target, function () {
        var inp = document.getElementById('pdx-osint-input');
        if (inp && saved.target) inp.value = saved.target;
        runOsintScan();
      });
    }

    function runOsintScan() {
      var input = document.getElementById('pdx-osint-input');
      var result = document.getElementById('pdx-osint-result');
      var btn    = document.getElementById('pdx-osint-btn');
      if (!input || !result) return;
      var norm = normalizePdxTarget(input.value);
      if (!norm.host) { showNotif('Enter a valid target', 'warn'); return; }
      var target = applyNormalizedInput(input, norm);
      clearPanelState('osint');

      var osintStages = [
        { label: 'Initializing OSINT agent network',      detail: 'Spinning up distributed intelligence collectors',          duration: 540 },
        { label: 'RDAP / WHOIS registry lookup',          detail: 'Querying domain registration and ownership data',          duration: 760 },
        { label: 'SSL certificate analysis',              detail: 'Inspecting certificate transparency logs',                 duration: 680 },
        { label: 'IP geolocation & ASN mapping',          detail: 'Resolving geographic and network ownership data',          duration: 720 },
        { label: 'VirusTotal multi-engine scan',          detail: 'Cross-referencing 70+ antivirus and reputation engines',   duration: 1100 },
        { label: 'Shodan infrastructure query',           detail: 'Scanning exposed services, ports, and banners',            duration: 940 },
        { label: 'Email & contact discovery',             detail: 'Enumerating associated email addresses and contacts',      duration: 820 },
        { label: 'IOC extraction & correlation',          detail: 'Identifying indicators of compromise across sources',      duration: 760 },
        { label: 'Timeline reconstruction',               detail: 'Building chronological activity and event timeline',       duration: 640 },
        { label: 'Compiling intelligence report',         detail: 'Aggregating findings into structured report',              duration: 480 },
      ];

      var osintLogLines = [
        'OSINT agent network initialized for: ' + target,
        'Querying RDAP bootstrap registry…',
        'Fetching SSL certificate transparency logs…',
        'Resolving IP geolocation via MaxMind GeoIP2…',
        'Submitting to VirusTotal multi-engine analysis…',
        'Querying Shodan internet-wide scan database…',
        'Running Hunter.io email discovery…',
        'Extracting and correlating IOC indicators…',
        'Reconstructing activity timeline…',
        'Compiling full intelligence report…',
      ];

      runIntelPipeline({
        btn: btn,
        result: result,
        module: 'osint',
        id: 'pdx-osint-pipeline',
        stages: osintStages,
        title: 'OSINT Investigation — ' + target,
        busyLabel: 'Scanning…',
        logLines: osintLogLines,
        api: function () { return apiFetch('POST', '/osint/scan', { target: target }); },
        onSuccess: function (el, data) { renderOsintResult(el, data, target); },
        onPayment: function (el, data) { el.innerHTML = ''; showPaymentRequiredResult(el, 'osint', 'OSINT', data); },
        errorMsg: 'Scan failed.',
      });
    }

    function renderOsintResult(container, data, target) {
      var displayTarget = (data && data.target) ? data.target : target;
      var targetType = (data && data.target_type) || detectTargetTypeFromString(displayTarget);
      var risk    = data.risk    || {};
      var sources = data.sources || {};
      var paywall = data.paywall;
      var iocs    = data.iocs    || [];
      var emails  = data.emails  || [];
      var timeline= data.timeline|| [];
      var anomalies = data.anomalies || [];
      var confidence = data.confidence || 0;
      var srcStatus = data.source_status || {};
      var threat = sources.threat || {};
      if (!threat.checked && sources.virustotal && sources.virustotal.malicious !== undefined) {
        threat = {
          malicious: sources.virustotal.malicious,
          suspicious: sources.virustotal.suspicious,
          harmless: sources.virustotal.harmless,
          feeds: ['VirusTotal'],
          categories: sources.virustotal.categories || [],
          checked: true
        };
      }
      var banner = scanBannerMeta(data, displayTarget, 'OSINT investigation complete');
      var unverified = isReportUnverified(data);
      var partial = isReportPartial(data);
      var displayScore = unverified ? (risk.indicative_score != null ? risk.indicative_score : 0) : (risk.score != null ? risk.score : 0);
      var scoreLabel = unverified ? (risk.indicative_score != null ? 'Indic.' : 'N/A') : 'Risk';

      var scoreColor = risk.verdict === 'clean' ? 'var(--pdx-green)' : risk.verdict === 'low' ? '#7e7e7e' : risk.verdict === 'medium' ? 'var(--pdx-yellow)' : risk.verdict === 'insufficient_data' ? 'var(--pdx-lo)' : 'var(--pdx-red)';
      var html = '<div class="pdx-result">';

      /* ── Scan complete banner ── */
      html += '<div class="pdx-scan-complete' + (banner.warn ? ' pdx-scan-complete--warn' : '') + '">' +
        '<div class="pdx-scan-complete-dot"></div>' +
        '<span>' + escHtml(banner.message) + '</span>' +
        (data.scan_id ? '<span class="pdx-scan-complete-time">' + escHtml(data.scan_id) + '</span>' : '') +
      '</div>';

      /* ── Risk score ── */
      if (risk.score !== undefined || risk.indicative_score != null || unverified) {
        var circumference = 2 * Math.PI * 26;
        var dashOffset = unverified && risk.indicative_score == null
          ? circumference
          : circumference - (displayScore / 100) * circumference;
        var ringStroke = unverified ? '#888888' : (risk.verdict === 'clean' ? '#ffffff' : risk.verdict === 'low' ? '#7e7e7e' : risk.verdict === 'medium' ? '#7e7e7e' : risk.verdict === 'insufficient_data' ? '#888888' : '#888888');
        html += '<div class="pdx-risk-header' + (unverified ? ' pdx-risk-header--unverified' : '') + '">' +
          '<div class="pdx-risk-ring">' +
            '<svg viewBox="0 0 64 64"><circle class="pdx-risk-ring-track" cx="32" cy="32" r="26"/>' +
            '<circle class="pdx-risk-ring-fill" cx="32" cy="32" r="26" stroke="' + ringStroke + '" stroke-dasharray="' + circumference.toFixed(1) + '" stroke-dashoffset="' + dashOffset.toFixed(1) + '"/></svg>' +
            '<div class="pdx-risk-ring-label"><div class="pdx-risk-ring-num">' + (unverified && risk.indicative_score == null ? '—' : displayScore) + '</div><div class="pdx-risk-ring-text">' + scoreLabel + '</div></div>' +
          '</div>' +
          '<div class="pdx-risk-meta">' +
            '<div class="pdx-risk-domain">' + escHtml(displayTarget) + '</div>' +
            '<div style="margin-top:4px"><span class="pdx-tag" style="background:' + ringStroke + '22;color:' + ringStroke + '">' + escHtml(risk.label || risk.verdict || 'Unknown') + '</span></div>' +
            (unverified ? '<div style="margin-top:6px;font-size:11px;color:var(--pdx-lo)">Not a verified assessment</div>' : (partial ? '<div style="margin-top:6px;font-size:11px;color:var(--pdx-yellow)">Partial assessment</div>' : '')) +
          '</div>' +
          (data.paid ? '<button class="pdx-btn-ghost pdx-btn-sm pdx-export-btn">Export</button>' : '') +
        '</div>';
      }

      html += renderCoveragePanel(data, srcStatus);

      /* ── Confidence ── */
      if (confidence || unverified) {
        var confNote = unverified ? ' (incomplete)' : (partial ? ' (partial)' : '');
        html += '<div class="pdx-confidence-bar"><span class="pdx-confidence-label">Confidence' + confNote + '</span>' +
          '<div class="pdx-confidence-track"><div class="pdx-confidence-fill" style="width:' + confidence + '%"></div></div>' +
          '<span class="pdx-confidence-pct">' + confidence + '%</span></div>';
      }

      /* ── Anomalies ── */
      if (anomalies.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">⚠ Anomalies (' + anomalies.length + ')</div>';
        anomalies.forEach(function(a) { html += '<div class="pdx-anomaly">' + svgIcon('alert') + '<span>' + escHtml(a.message || safeStr(a)) + '</span></div>'; });
        html += '</div>';
      }

      /* ── Intelligence sources — structured cards ── */
      var srcKeys = Object.keys(sources);
      /* Friendly labels and field renderers per source type */
      var srcMeta = {
        ip_network: { label: 'IP Network Registration',          icon: 'rdap' },
        reverse_dns:{ label: 'Reverse DNS (PTR)',                icon: 'dns-records' },
        rdap:       { label: 'Domain Registration (RDAP/WHOIS)', icon: 'rdap' },
        whois:      { label: 'WHOIS Record',                     icon: 'whois' },
        ssl:        { label: 'SSL / TLS Certificate',            icon: 'ssl-cert' },
        dns:        { label: 'DNS Infrastructure',               icon: 'dns-records' },
        geo:        { label: 'Geolocation & Network',            icon: 'geo-pin' },
        geolocation:{ label: 'Geolocation & Network',            icon: 'geo-pin' },
        vt:         { label: 'VirusTotal Analysis',              icon: 'virus-scan' },
        virustotal: { label: 'VirusTotal Analysis',              icon: 'virus-scan' },
        shodan:     { label: 'Shodan Infrastructure',            icon: 'shodan-radar' },
        hibp:       { label: 'Data Breach Check (HIBP)',         icon: 'breach-check' },
        hunter:     { label: 'Email Discovery (Hunter.io)',      icon: 'email-hunter' },
        abuseipdb:  { label: 'AbuseIPDB IP Reputation',          icon: 'abuse-ch' },
        abuse:      { label: 'Abuse.ch Intelligence',            icon: 'abuse-ch' },
        threat:     { label: 'Threat Intelligence Feeds',        icon: 'threat-feed' },
        url_forensics: { label: 'URL Forensics',                 icon: 'threat-feed' },
      };
      var srcOrder = targetType === 'ip'
        ? ['ip_network', 'reverse_dns', 'geo', 'geolocation', 'threat', 'abuseipdb', 'virustotal', 'shodan']
        : targetType === 'hash'
        ? ['threat', 'virustotal']
        : ['rdap', 'dns', 'ssl', 'geo', 'geolocation', 'threat', 'virustotal', 'shodan', 'hunter', 'hibp', 'url_forensics'];
      var seenKeys = {};
      srcOrder.concat(srcKeys).forEach(function(key) {
        if (seenKeys[key]) return;
        seenKeys[key] = true;
        var src = sources[key];
        if (!src) return;
        var meta  = srcMeta[key] || {};
        var label = src.label || meta.label || key.replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
        /* Build human-readable rows — skip internal/meta fields */
        var skipFields = { label:1, free:1, raw:1, _raw:1 };
        var rows = [];
        Object.keys(src).forEach(function(k) {
          if (skipFields[k]) return;
          var v = src[k];
          if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) return;
          if (k === 'error') return; /* handled separately */
          /* Format key nicely */
          var keyLabel = k.replace(/_/g,' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
          /* Format value nicely */
          var valStr = safeStr(v);
          /* Special formatting */
          if (k === 'malicious' || k === 'suspicious') {
            if (key === 'threat' && !src.checked) valStr = 'Not verified';
            else valStr = formatThreatBool(!!v, key !== 'threat' || src.checked);
          }
          if (k === 'breached')  valStr = v ? '⚠ Breached' : '✓ Not found';
          if (k === 'proxy' || k === 'tor' || k === 'hosting') valStr = v ? '⚠ Yes' : 'No';
          if (k === 'age_days' && typeof v === 'number') valStr = v + ' days' + (v < 30 ? ' ⚠ Very new' : v < 180 ? ' ⚠ Recent' : '');
          rows.push(kvRow(keyLabel, valStr));
        });
        if (!rows.length && !src.error) return;
        html += '<div class="pdx-evidence-section">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            (meta.icon ? '<span class="pdx-evidence-icon">' + svgIcon(meta.icon) + '</span>' : '') +
            escHtml(label) +
            (src.error ? ' <span class="pdx-badge pdx-badge--err">Error</span>' : '') +
            ' <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body">' +
            (src.error ? '<div class="pdx-error-msg">' + escHtml(safeStr(src.error)) + '</div>' : '') +
            (rows.length ? '<div class="pdx-kv-grid">' + rows.join('') + '</div>' : '') +
          '</div>' +
        '</div>';
      });

      /* ── IOC Indicators ── */
      if (iocs.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">IOC Indicators (' + iocs.length + ')</div><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">';
        iocs.slice(0,20).forEach(function(ioc) {
          var val = typeof ioc === 'object' ? (ioc.value || ioc.indicator || safeStr(ioc)) : safeStr(ioc);
          var type = typeof ioc === 'object' ? (ioc.type || 'ioc') : 'ioc';
          html += '<span class="pdx-ioc-chip-v5" title="' + escHtml(type) + '">' + escHtml(val) + '</span>';
        });
        if (iocs.length > 20) html += '<span class="pdx-tag">+' + (iocs.length - 20) + ' more</span>';
        html += '</div></div>';
      }

      /* ── Email / Contact Discovery ── */
      if (emails.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Email & Contact Discovery (' + emails.length + ')</div><div class="pdx-kv-grid">';
        emails.slice(0,8).forEach(function(e) {
          var addr = typeof e === 'object' ? (e.email || e.address || safeStr(e)) : safeStr(e);
          var src2 = typeof e === 'object' ? (e.source || '') : '';
          html += kvRow(addr, src2 || 'Discovered');
        });
        html += '</div></div>';
      }

      /* ── Timeline ── */
      if (timeline.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Activity Timeline</div>' +
          '<div class="pdx-timeline-v5">';
        timeline.slice(0,8).forEach(function(ev) {
          html += '<div class="pdx-tl-event-v5">' +
            '<div class="pdx-tl-dot-v5"></div>' +
            '<div class="pdx-tl-body-v5">' +
              '<div class="pdx-tl-date-v5">' + escHtml(ev.date || ev.timestamp || '') + '</div>' +
              '<div class="pdx-tl-desc-v5">' + escHtml(ev.description || ev.event || safeStr(ev)) + '</div>' +
              (ev.source ? '<div class="pdx-tl-source-v5">' + escHtml(ev.source) + '</div>' : '') +
            '</div>' +
          '</div>';
        });
        html += '</div></div>';
      }

      /* ── Paywall ── */
      if (paywall) {
        var pwPrice    = (paywall.price    != null) ? parseFloat(paywall.price).toFixed(2) : '0.00';
        var pwCurrency = paywall.currency  || 'USD';
        html += '<div class="pdx-paywall">' +
          '<div class="pdx-paywall-icon">' + svgIcon('lock-paywall') + '</div>' +
          '<div class="pdx-paywall-title">Full Intelligence Report</div>' +
          '<div class="pdx-paywall-desc">' + escHtml(safeStr(paywall.message)) + '</div>' +
          '<div class="pdx-paywall-locked"><strong>Locked sources:</strong> ' + escHtml((paywall.locked_sources || []).join(', ')) + '</div>' +
          '<div class="pdx-paywall-price">' +
            '<span class="pdx-paywall-currency">' + escHtml(pwCurrency) + '</span>' +
            '<span class="pdx-paywall-amount">' + pwPrice + '</span>' +
          '</div>' +
          '<button class="pdx-btn-primary pdx-btn-full pdx-unlock-btn"' +
            ' data-module="osint"' +
            ' data-price="' + escHtml(String(paywall.price || 0)) + '"' +
            ' data-currency="' + escHtml(pwCurrency) + '">' +
            'Unlock Full Report' +
          '</button>' +
        '</div>';
      }

      /* ── AI Intelligence Summary ── */
      var osintType = (data && data.target_type) || detectTargetTypeFromString(target);
      var summaryText = data.ai_summary || generateSummary(osintType, target, data);
      var recs = (data.recommendations && data.recommendations.length)
        ? data.recommendations.map(safeStr)
        : generateRecommendations(osintType, data);

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header">' +
          '<span class="pdx-report-summary-icon">' + svgIcon('report-osint') + '</span>' +
          '<span class="pdx-report-summary-title">Intelligence Summary</span>' +
        '</div>' +
        '<div class="pdx-report-summary-text">' + escHtml(summaryText) + '</div>' +
        (recs.length ? '<div class="pdx-report-recs"><div class="pdx-report-recs-title">Recommendations</div><ul class="pdx-report-recs-list">' +
          recs.map(function(r) { return '<li>' + escHtml(r) + '</li>'; }).join('') +
        '</ul></div>' : '') +
      '</div>';

      /* ── Raw data (collapsed) ── */
      html += rawSection('Raw Response', data);

      html += '</div>';
      savePanelState('osint', {
        view: 'result',
        target: displayTarget,
        data: data,
        paymentRequired: data.error === 'payment_required',
      });
      container.innerHTML = html;

      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('osint-' + displayTarget.replace(/[^a-z0-9.-]+/gi, '_'), data); });
      var unlockBtn = container.querySelector('.pdx-unlock-btn');
      if (unlockBtn) unlockBtn.addEventListener('click', function() { initiatePayment('osint', parseFloat(unlockBtn.dataset.price), unlockBtn.dataset.currency); });
    }


    /* ══════════════════════════════════════════════════════
       THREAT INTEL
    ══════════════════════════════════════════════════════ */
    function renderThreat(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      panelInner.innerHTML =
        '<div class="pdx-ph pdx-ph--threat">' +
          '<div class="pdx-ph-hd pdx-ph-hd--threat">' +
            '<div class="pdx-ph-title pdx-ph-title--threat">' + modIcon('threat') + '<span>Threat Intel</span><span class="pdx-badge pdx-badge--new">New</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Feeds active"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Correlate infrastructure indicators, intelligence feeds, and behavioral signals to identify suspicious or malicious patterns. CVE lookup, live threat feeds, and attack surface mapping.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">CVE / NVD</span>' +
              '<span class="pdx-cap-tag">AlienVault OTX</span>' +
              '<span class="pdx-cap-tag">Abuse.ch</span>' +
              '<span class="pdx-cap-tag">CISA KEV</span>' +
              '<span class="pdx-cap-tag">Attack Surface</span>' +
              '<span class="pdx-cap-tag">STIX Export</span>' +
            '</div>' +
          '</div>' +
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
        '<div class="pdx-input-row"><input id="pdx-cve-input" class="pdx-input" placeholder="CVE-2024-XXXX or software name" /><button id="pdx-cve-btn" class="pdx-btn-primary">Analyze</button></div>' +
        '<div id="pdx-cve-result"></div>' +
        '<div class="pdx-info-box">Search the NVD database for CVEs by ID or affected software. Results include CVSS scores, affected versions, exploit availability, and remediation guidance.</div>' +
      '</div>';
    }

    function renderThreatFeedsTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-input-row">' +
          '<button type="button" id="pdx-feeds-sync-btn" class="pdx-btn-primary">Sync Live Feeds</button>' +
        '</div>' +
        '<div id="pdx-feeds-result">' + renderThreatFeedsList() + '</div>' +
      '</div>';
    }

    function renderThreatSurfaceTab() {
      return '<div class="pdx-tab-pane">' +
        '<div class="pdx-input-row"><input id="pdx-surface-input" class="pdx-input" placeholder="domain.com or IP range" /><button id="pdx-surface-btn" class="pdx-btn-primary">Map Surface</button></div>' +
        '<div id="pdx-surface-result"></div>' +
        '<div class="pdx-info-box">Maps exposed services, open ports, subdomains, and technology fingerprints using Shodan (host + DNS APIs) and independent DNS/CT enumeration. Shodan API key required for port and service data; DNS and subdomain discovery run without Shodan.</div>' +
      '</div>';
    }

    function feedItem(name, desc, status, meta) {
      return '<div class="pdx-feed-item">' +
        '<div class="pdx-feed-dot pdx-feed-dot--' + status + '"></div>' +
        '<div class="pdx-feed-info">' +
          '<div class="pdx-feed-name">' + escHtml(name) + (meta ? '<span class="pdx-feed-meta">' + escHtml(meta) + '</span>' : '') + '</div>' +
          '<div class="pdx-feed-desc">' + escHtml(desc) + '</div>' +
        '</div>' +
      '</div>';
    }

    function feedStatusClass(status) {
      if (status === 'online') return 'active';
      if (status === 'degraded') return 'degraded';
      return 'inactive';
    }

    function renderThreatFeedsList(data) {
      if (!data || !data.feeds || !data.feeds.length) {
        return (
          '<div class="pdx-section-title">Threat Intelligence Feeds</div>' +
          '<div class="pdx-info-box">Click <strong>Sync Live Feeds</strong> to probe AlienVault OTX, URLhaus, NVD/CIRCL, DNS, and RDAP from this server. Configure API keys in Admin → API for full coverage.</div>'
        );
      }

      var feeds = data.feeds;
      var summary = data.summary || {};
      var syncState = (data.status && data.status.state) ? data.status.state : 'ok';
      var syncedAt = data.synced_at ? new Date(data.synced_at).toLocaleString() : '';
      var online = summary.online != null ? summary.online : feeds.filter(function (f) { return f.status === 'online'; }).length;
      var total = summary.total != null ? summary.total : feeds.length;
      var html = '<div class="pdx-section-title">Threat Intelligence Feeds</div><div class="pdx-feed-list">';

      feeds.forEach(function (f) {
        var st = feedStatusClass(f.status || 'offline');
        var desc = f.message || f.url || '';
        var meta = f.indicators ? String(f.indicators) + ' hit(s)' : (f.status === 'online' ? 'Online' : (f.status === 'degraded' ? 'Degraded' : 'Offline'));
        html += feedItem(f.name || f.id || 'Feed', desc, st, meta);
      });

      html += '</div>';
      html += '<div class="pdx-scan-complete' + (syncState === 'error' ? ' pdx-scan-complete--warn' : '') + '">' +
        '<div class="pdx-scan-complete-dot"></div><span>' +
        escHtml(online + '/' + total + ' feeds reachable' + (syncedAt ? ' · synced ' + syncedAt : '')) +
        '</span></div>';

      if (data.status && data.status.message) {
        html += '<div class="pdx-field-hint">' + escHtml(data.status.message) + '</div>';
      }

      html += '<div class="pdx-info-box">Configure API keys in Admin → API Keys to enable authenticated feeds (URLhaus, Shodan) and higher rate limits.</div>';
      return html;
    }

    /* ══════════════════════════════════════════════════════
       AI PERSONAS
    ══════════════════════════════════════════════════════ */
    function renderPersonas(mod, access, locked) {
      // If fully locked (paid tier, no access), show paywall immediately.
      if (locked && mod.tier === 'paid') { renderPaywall(mod, access); return; }

      panelInner.innerHTML =
        '<div class="pdx-ph pdx-ph--chat">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('personas') + '<span>AI Personas</span>' + (mod.badge ? '<span class="pdx-badge">' + mod.badge + '</span>' : '') +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="AI online"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Interact with specialized AI agents trained for automation, intelligence analysis, workflow assistance, and technical operations. Persistent memory and conversation history included.</div>' +
            '<div class="pdx-persona-select">' +
              '<button class="pdx-persona-btn is-active" data-persona="assistant" title="General-purpose AI assistant">Assistant</button>' +
              '<button class="pdx-persona-btn" data-persona="analyst" title="Cyber intelligence and threat analysis">Analyst</button>' +
              '<button class="pdx-persona-btn" data-persona="developer" title="Code, architecture, and technical guidance">Developer</button>' +
              '<button class="pdx-persona-btn" data-persona="strategist" title="Business strategy and decision support">Strategist</button>' +
            '</div>' +
          '</div>' +
          '<div id="pdx-chat-messages" class="pdx-chat-messages"></div>' +
          '<div class="pdx-chat-footer">' +
            '<div class="pdx-chat-input-row">' +
              '<textarea id="pdx-chat-input" class="pdx-chat-input" placeholder="Ask anything..." rows="2"></textarea>' +
              '<div class="pdx-chat-actions">' +
                '<button id="pdx-chat-send" class="pdx-btn-primary">Send</button>' +
                '<button id="pdx-chat-clear" class="pdx-btn-ghost" title="Clear">Clear</button>' +
                '<button id="pdx-chat-export" class="pdx-btn-ghost" title="Export">Export</button>' +
              '</div>' +
            '</div>' +
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
      panelInner.querySelectorAll('.pdx-persona-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          panelInner.querySelectorAll('.pdx-persona-btn').forEach(function(b) { b.classList.remove('is-active'); });
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
      function sendChat() {
        var input = document.getElementById('pdx-chat-input');
        if (!input) return;
        var msg = input.value.trim();
        if (!msg) return;
        input.value = '';
        appendChatMsg(msgContainer, 'user', msg);
        state.chatHistory.push({ role: 'user', content: msg });

        var thinking = appendChatMsg(msgContainer, 'assistant', '...', true);

        apiFetch('POST', '/ai/chat', {
          module_id: 'personas',
          message: msg,
          persona: currentPersona,
          thread_id: state.chatThreadId || '',
          history: state.chatHistory.slice(-20),
          stream: true
        }).then(function(data) {
          thinking.remove();
          if (!data) { appendChatMsg(msgContainer, 'error', 'Request failed.'); return; }
          if (data.error === 'payment_required') {
            appendChatMsg(msgContainer, 'system', 'Preview limit reached.');
            var footer = panelInner.querySelector('.pdx-chat-footer');
            if (footer) {
              footer.innerHTML = renderPaywallInline(mod, { price: data.price, currency: data.currency });
              var unlockBtn = footer.querySelector('.pdx-unlock-btn');
              if (unlockBtn) {
                unlockBtn.addEventListener('click', function(e) {
                  var b = e.currentTarget;
                  initiatePayment(b.dataset.module, parseFloat(b.dataset.price), b.dataset.currency);
                });
              }
            }
            return;
          }
          if (data.error) { appendChatMsg(msgContainer, 'error', data.error); return; }
          if (data.thread_id) state.chatThreadId = data.thread_id;
          var reply = data.reply || '';
          if (typeof window.pdxStreamReply === 'function') {
            window.pdxStreamReply(msgContainer, 'assistant', reply, function() {
              state.chatHistory.push({ role: 'assistant', content: reply });
            });
          } else {
            appendChatMsg(msgContainer, 'assistant', reply);
            state.chatHistory.push({ role: 'assistant', content: reply });
          }
        });
      }

      if (exportBtn) exportBtn.addEventListener('click', function() {
        if (state.chatThreadId) {
          apiFetch('POST', '/ai/export', { thread_id: state.chatThreadId }).then(function(data) {
            exportJSON('chat-' + currentPersona, data || { persona: currentPersona, messages: state.chatHistory });
          });
        } else {
          exportJSON('chat-history', { persona: currentPersona, messages: state.chatHistory });
        }
      });

      apiFetch('GET', '/ai/conversations?persona=' + encodeURIComponent(currentPersona)).then(function(data) {
        if (!data || !data.threads || !data.threads.length || state.chatHistory.length) return;
        state.chatThreadId = data.threads[0].thread_id;
        apiFetch('GET', '/ai/conversations/' + state.chatThreadId).then(function(exp) {
          if (!exp || !exp.thread || !exp.thread.messages) return;
          state.chatHistory = exp.thread.messages;
          msgContainer.innerHTML = '';
          state.chatHistory.forEach(function(m) { appendChatMsg(msgContainer, m.role, m.content); });
        });
      });

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
      if (locked) { renderPaywall(mod, access); return; }
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('builder') + '<span>AI Builder</span>' + (mod.badge ? '<span class="pdx-badge">' + mod.badge + '</span>' : '') +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Engine ready"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Visual AI workflow builder — chain LLM steps, transformations, and logic into automated pipelines. Run flows, save templates, and deploy reusable intelligence sequences.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">LLM Chaining</span>' +
              '<span class="pdx-cap-tag">Transformations</span>' +
              '<span class="pdx-cap-tag">Templates</span>' +
              '<span class="pdx-cap-tag">Flow Export</span>' +
            '</div>' +
          '</div>' +
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
        '<button id="pdx-builder-save" type="button" class="pdx-btn-ghost pdx-btn-full pdx-mt-sm">Save Flow</button>' +
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
      var saveBtn = document.getElementById('pdx-builder-save');
      if (!stepsContainer || !addBtn || !runBtn) return;

      if (saveBtn) saveBtn.addEventListener('click', function() {
        var name  = (document.getElementById('pdx-builder-name') || {}).value || 'My Flow';
        var input = (document.getElementById('pdx-builder-input') || {}).value || '';
        var steps = [];
        stepsContainer.querySelectorAll('.pdx-step').forEach(function(s) {
          steps.push({ type: s.querySelector('.pdx-step-type').value, prompt: s.querySelector('.pdx-step-prompt').value });
        });
        apiFetch('POST', '/builder/flows', { name: name, steps: steps, input: input }).then(function(data) {
          if (data && data.flow_id) showNotif('Flow saved', 'success');
        });
      });

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
        if (isBtnBusy(runBtn)) return;
        var name  = (document.getElementById('pdx-builder-name') || {}).value || 'My Flow';
        var input = (document.getElementById('pdx-builder-input') || {}).value || '';
        var steps = [];
        stepsContainer.querySelectorAll('.pdx-step').forEach(function(s) {
          steps.push({ type: s.querySelector('.pdx-step-type').value, prompt: s.querySelector('.pdx-step-prompt').value });
        });
        var result = document.getElementById('pdx-builder-result');
        var builderStages = steps.map(function(s, i) {
          return { label: 'Executing step ' + (i+1) + ' — ' + (s.type || 'LLM'), detail: s.prompt ? s.prompt.slice(0, 60) : 'Processing…', duration: 700 + Math.random() * 600 };
        });
        builderStages.unshift({ label: 'Initializing AI flow engine', detail: 'Loading LLM configuration and context', duration: 480 });
        builderStages.push({ label: 'Finalizing output', detail: 'Compiling step results into final response', duration: 420 });

        setBtnBusy(runBtn, true, 'Running…');
        result.innerHTML = buildDeepPipeline('pdx-builder-pipeline', builderStages, {
          title: 'AI Flow — ' + name, showLog: true,
        });

        var builderLogLines = ['AI Builder flow initialized: ' + name].concat(
          steps.map(function(s, i) { return 'Step ' + (i+1) + ' [' + s.type + ']: ' + (s.prompt || '').slice(0, 50) + '…'; }),
          ['Compiling final output…']
        );

        var apiDone = false, pipelineDone = false, apiData = null;
        function finishBuilder() {
          setBtnBusy(runBtn, false);
          if (!apiData) { result.innerHTML = '<div class="pdx-error">Flow failed.</div>'; return; }
          if (apiData.error === 'payment_required') { result.innerHTML = ''; showPaymentRequiredResult(result, 'builder', 'AI Builder', apiData); return; }
          renderBuilderResult(result, apiData);
          showNotif('Flow "' + name + '" completed', 'success');
        }
        runDeepPipeline('pdx-builder-pipeline', builderStages, { logLines: builderLogLines }).then(function() {
          pipelineDone = true;
          if (apiDone) finishBuilder();
        });
        apiFetch('POST', '/builder/run', { flow_name: name, steps: steps, input: input }).then(function(data) {
          apiData = data; apiDone = true;
          if (pipelineDone) finishBuilder();
        });
      });
    }

    function renderBuilderResult(container, data) {
      var r = data.result || {};
      var outputs = r.outputs || [];
      var html = '<div class="pdx-result">';

      html += '<div class="pdx-scan-complete"><div class="pdx-scan-complete-dot"></div>' +
        '<span>Flow complete — ' + escHtml(data.flow_name || 'Flow') + '</span>' +
        '<span class="pdx-scan-complete-time">' + (r.steps_executed || outputs.length) + ' steps</span>' +
      '</div>';

      /* Execution metrics */
      html += '<div class="pdx-metric-grid">' +
        '<div class="pdx-metric-card pdx-metric-card--green"><div class="pdx-metric-value">' + (r.steps_executed || outputs.length) + '</div><div class="pdx-metric-label">Steps Run</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + (r.tokens_used || '—') + '</div><div class="pdx-metric-label">Tokens Used</div></div>' +
      '</div>';

      /* Step outputs */
      if (outputs.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Execution Trace</div>';
        outputs.forEach(function(o) {
          var output = safeStr(o.output || o.result || o.content || '');
          html += '<div class="pdx-evidence-section">' +
            '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
              'Step ' + (o.step || '') + ' <span class="pdx-tag">' + escHtml(safeStr(o.type || 'llm')) + '</span>' +
              (o.duration_ms ? '<span class="pdx-tag" style="margin-left:4px">' + o.duration_ms + 'ms</span>' : '') +
              '<span class="pdx-evidence-toggle-arrow" style="margin-left:auto">▼</span>' +
            '</button>' +
            '<div class="pdx-evidence-body">' +
              (o.prompt ? '<div class="pdx-section-title" style="margin-bottom:4px">Prompt</div><div class="pdx-code" style="margin-bottom:8px">' + escHtml(safeStr(o.prompt).slice(0,300)) + '</div>' : '') +
              '<div class="pdx-section-title" style="margin-bottom:4px">Output</div>' +
              '<div class="pdx-prose">' + escHtml(output.slice(0, 600)).replace(/\n/g,'<br>') + (output.length > 600 ? '<span style="color:var(--pdx-lo)">… (truncated)</span>' : '') + '</div>' +
            '</div>' +
          '</div>';
        });
        html += '</div>';
      }

      /* Final output */
      if (r.final_output) {
        var finalOut = safeStr(r.final_output);
        html += '<div class="pdx-final-output">' +
          '<div class="pdx-section-title">Final Output</div>' +
          '<div class="pdx-output-body">' + escHtml(finalOut).replace(/\n/g,'<br>') + '</div>' +
        '</div>';
      }

      /* ── Summary ── */
      var stepsRun = r.steps_executed || outputs.length;
      var builderSummary = data.ai_summary ||
        'AI flow "' + (data.flow_name || 'Flow') + '" completed successfully. ' +
        stepsRun + ' step' + (stepsRun !== 1 ? 's' : '') + ' executed' +
        (r.tokens_used ? ', consuming ' + r.tokens_used + ' tokens' : '') + '. ' +
        (r.final_output ? 'Final output generated.' : 'See execution trace for step-level outputs.');

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-builder') + '</span>' +
        '<span class="pdx-report-summary-title">Execution Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(builderSummary) + '</div>' +
      '</div>';

      html += rawSection('Raw Response', data);
      html += '<button class="pdx-btn-ghost pdx-btn-sm pdx-export-btn" style="margin-top:8px">Export JSON</button>';
      html += '</div>';
      container.innerHTML = html;
      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('builder-' + (data.flow_name || 'flow'), data); });
    }


    /* ══════════════════════════════════════════════════════
       AGENT PIPELINE
    ══════════════════════════════════════════════════════ */
    function renderPipeline(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('pipeline') + '<span>Agent Pipeline</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Orchestrator ready"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Orchestrate multi-agent task chains with role-based agents, intelligent handoffs, and full execution traces. Each agent specializes in a distinct function within the pipeline.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">Multi-Agent</span>' +
              '<span class="pdx-cap-tag">Role Assignment</span>' +
              '<span class="pdx-cap-tag">Handoff Trace</span>' +
              '<span class="pdx-cap-tag">Templates</span>' +
              '<span class="pdx-cap-tag">Export Trace</span>' +
            '</div>' +
          '</div>' +
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
        html += '<div class="pdx-trace-item"><div class="pdx-trace-agent">' + svgIcon('agent-trace') + escHtml(t.name || t.agent) + '</div><div class="pdx-trace-output">' + escHtml(t.output || '').replace(/\n/g,'<br>') + '</div></div>';
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
        if (isBtnBusy(runBtn)) return;
        var name      = (document.getElementById('pdx-pipeline-name') || {}).value || 'Pipeline';
        var objective = (document.getElementById('pdx-pipeline-objective') || {}).value || '';
        if (!objective) { showNotif('Objective required', 'warn'); return; }
        var agents = [];
        agentsContainer.querySelectorAll('.pdx-agent-row').forEach(function(row) {
          agents.push({ role: row.querySelector('.pdx-agent-role').value, name: row.querySelector('.pdx-agent-name').value });
        });
        var result = document.getElementById('pdx-pipeline-result');
        var pipelineStages = [
          { label: 'Initializing orchestration engine', detail: 'Configuring agent roles and communication channels', duration: 520 },
        ].concat(agents.map(function(a) {
          return { label: 'Agent: ' + a.name + ' [' + a.role + ']', detail: 'Executing role-specific analysis and generating output', duration: 900 + Math.random() * 700 };
        })).concat([
          { label: 'Processing agent handoffs', detail: 'Routing outputs between agents in the chain', duration: 580 },
          { label: 'Synthesizing final output', detail: 'Aggregating all agent contributions', duration: 460 },
        ]);

        setBtnBusy(runBtn, true, 'Processing…');
        result.innerHTML = buildDeepPipeline('pdx-pipeline-dp', pipelineStages, {
          title: 'Agent Pipeline — ' + name, showLog: true,
        });

        var pipelineLogLines = ['Pipeline orchestrator initialized: ' + name, 'Objective: ' + objective.slice(0, 80)].concat(
          agents.map(function(a) { return 'Spawning agent: ' + a.name + ' (role: ' + a.role + ')'; }),
          ['Processing inter-agent handoffs…', 'Synthesizing final pipeline output…']
        );

        var apiDone = false, pipelineDone = false, apiData = null;
        function finishPipeline() {
          setBtnBusy(runBtn, false);
          if (!apiData) { result.innerHTML = '<div class="pdx-error">Pipeline failed.</div>'; return; }
          if (apiData.error === 'payment_required') { result.innerHTML = ''; showPaymentRequiredResult(result, 'pipeline', 'Agent Pipeline', apiData); return; }
          state.pipelineTrace = (apiData.result && apiData.result.trace) || [];
          renderPipelineResult(result, apiData);
          showNotif('Pipeline "' + name + '" completed — ' + agents.length + ' agents', 'success');
        }
        runDeepPipeline('pdx-pipeline-dp', pipelineStages, { logLines: pipelineLogLines }).then(function() {
          pipelineDone = true;
          if (apiDone) finishPipeline();
        });
        apiFetch('POST', '/pipeline/run', { pipeline_name: name, agents: agents, objective: objective }).then(function(data) {
          apiData = data; apiDone = true;
          if (pipelineDone) finishPipeline();
        });
      });
    }

    function renderPipelineResult(container, data) {
      var r = data.result || {};
      var trace = r.trace || [];
      var html = '<div class="pdx-result">';

      html += '<div class="pdx-scan-complete"><div class="pdx-scan-complete-dot"></div>' +
        '<span>Pipeline complete — ' + escHtml(data.pipeline_name || 'Pipeline') + '</span>' +
        '<span class="pdx-scan-complete-time">' + (r.agents_run || trace.length) + ' agents</span>' +
      '</div>';

      /* Metrics */
      html += '<div class="pdx-metric-grid">' +
        '<div class="pdx-metric-card pdx-metric-card--green"><div class="pdx-metric-value">' + (r.agents_run || trace.length) + '</div><div class="pdx-metric-label">Agents Run</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + (r.handoffs || Math.max(0, trace.length - 1)) + '</div><div class="pdx-metric-label">Handoffs</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + (r.tokens_used || '—') + '</div><div class="pdx-metric-label">Tokens</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + (r.duration_ms ? (r.duration_ms/1000).toFixed(1)+'s' : '—') + '</div><div class="pdx-metric-label">Duration</div></div>' +
      '</div>';

      /* Agent trace */
      if (trace.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Agent Execution Trace</div>';
        trace.forEach(function(t, idx) {
          var agentName = safeStr(t.name || t.agent || ('Agent ' + (idx+1)));
          var role      = safeStr(t.role || '');
          var output    = safeStr(t.output || t.result || t.content || '');
          var tokens    = t.tokens_used || t.tokens || '';
          html += '<div class="pdx-evidence-section">' +
            '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
              svgIcon('agent-trace') +
              '<span style="margin-left:6px">' + escHtml(agentName) + '</span>' +
              (role ? '<span class="pdx-tag" style="margin-left:6px">' + escHtml(role) + '</span>' : '') +
              (tokens ? '<span class="pdx-tag" style="margin-left:4px">' + tokens + ' tokens</span>' : '') +
              '<span class="pdx-evidence-toggle-arrow" style="margin-left:auto">▼</span>' +
            '</button>' +
            '<div class="pdx-evidence-body">' +
              (t.task ? '<div class="pdx-section-title" style="margin-bottom:4px">Task</div><div class="pdx-prose" style="margin-bottom:8px">' + escHtml(safeStr(t.task).slice(0,200)) + '</div>' : '') +
              '<div class="pdx-section-title" style="margin-bottom:4px">Output</div>' +
              '<div class="pdx-prose">' + escHtml(output.slice(0,500)).replace(/\n/g,'<br>') + (output.length > 500 ? '<span style="color:var(--pdx-lo)">…</span>' : '') + '</div>' +
              (t.handoff_to ? '<div style="margin-top:8px;font:11px/1 var(--pdx-mono);color:var(--pdx-indigo)">→ Handoff to: ' + escHtml(safeStr(t.handoff_to)) + '</div>' : '') +
            '</div>' +
          '</div>';
        });
        html += '</div>';
      }

      /* Final output */
      if (r.final_output) {
        html += '<div class="pdx-final-output"><div class="pdx-section-title">Final Output</div>' +
          '<div class="pdx-output-body">' + escHtml(safeStr(r.final_output)).replace(/\n/g,'<br>') + '</div></div>';
      }

      /* ── Summary ── */
      var agentsRun = r.agents_run || trace.length;
      var pipelineSummary = data.ai_summary ||
        'Agent pipeline "' + (data.pipeline_name || 'Pipeline') + '" completed. ' +
        agentsRun + ' agent' + (agentsRun !== 1 ? 's' : '') + ' executed with ' +
        (r.handoffs || Math.max(0, agentsRun - 1)) + ' handoff' + ((r.handoffs || agentsRun - 1) !== 1 ? 's' : '') + '. ' +
        (r.tokens_used ? r.tokens_used + ' tokens consumed. ' : '') +
        (r.final_output ? 'Final synthesized output available below.' : 'See agent trace for individual outputs.');

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-pipeline') + '</span>' +
        '<span class="pdx-report-summary-title">Orchestration Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(pipelineSummary) + '</div>' +
      '</div>';

      html += rawSection('Raw Response', data);
      html += '<button class="pdx-btn-ghost pdx-btn-sm pdx-export-btn" style="margin-top:8px">Export Trace</button>';
      html += '</div>';
      container.innerHTML = html;
      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('pipeline-' + (data.pipeline_name || 'run'), data); });
    }


    /* ══════════════════════════════════════════════════════
       BROWSER AUTOMATION
    ══════════════════════════════════════════════════════ */
    function renderAutomation(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('automation') + '<span>Browser Automation</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Engine ready"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Automate browser-based workflows, extraction tasks, validation steps, and operational sequences using AI-assisted execution pipelines. Submit a URL and task to receive a structured execution plan.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">Task Analysis</span>' +
              '<span class="pdx-cap-tag">Step Breakdown</span>' +
              '<span class="pdx-cap-tag">Data Extraction</span>' +
              '<span class="pdx-cap-tag">Job Queue</span>' +
              '<span class="pdx-cap-tag">Export</span>' +
            '</div>' +
          '</div>' +
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
        var urlInput = document.getElementById('pdx-auto-url');
        var rawUrl   = (urlInput || {}).value || '';
        var norm = normalizePdxTarget(rawUrl);
        if (!norm.normalized && !norm.host) { showNotif('Enter a valid URL or domain', 'warn'); return; }
        if (norm.type === 'email' || norm.type === 'hash') {
          showNotif('Automation requires a URL or domain', 'warn');
          return;
        }
        var url = /^[a-z][a-z0-9+.-]*:\/\//i.test(rawUrl)
          ? rawUrl.replace(/[?#].*$/, '')
          : 'https://' + (norm.host || norm.normalized);
        if (urlInput) urlInput.value = url;
        var task   = (document.getElementById('pdx-auto-task') || {}).value || '';
        var format = (document.getElementById('pdx-auto-format') || {}).value || 'json';
        var result = document.getElementById('pdx-auto-result');
        if (!url || !task) { showNotif('URL and task required', 'warn'); return; }

        var autoStages = [
          { label: 'Initializing automation analysis engine',  detail: 'Loading browser task intelligence modules',              duration: 480 },
          { label: 'Parsing and validating target URL',        detail: 'Resolving domain, checking accessibility',               duration: 620 },
          { label: 'Analyzing task requirements',             detail: 'Decomposing task into executable sub-operations',        duration: 780 },
          { label: 'Identifying DOM selectors',               detail: 'Mapping page structure and interaction points',           duration: 860 },
          { label: 'Building execution plan',                 detail: 'Sequencing steps for optimal automation flow',           duration: 720 },
          { label: 'Estimating complexity & obstacles',       detail: 'Detecting anti-bot measures, dynamic content, auth',     duration: 640 },
          { label: 'Generating structured output',            detail: 'Compiling execution plan and data extraction schema',    duration: 420 },
        ];
        var autoLogLines = [
          'Browser automation engine initialized.',
          'Parsing target URL: ' + url,
          'Decomposing task: ' + task.slice(0, 60) + '…',
          'Mapping DOM structure and interaction points…',
          'Building step-by-step execution sequence…',
          'Analyzing complexity and potential obstacles…',
          'Generating structured execution plan…',
        ];

        result.innerHTML = buildDeepPipeline('pdx-auto-pipeline', autoStages, {
          title: 'Automation Analysis', showLog: true,
        });

        var apiDone = false, pipelineDone = false, apiData = null;
        runDeepPipeline('pdx-auto-pipeline', autoStages, { logLines: autoLogLines }).then(function() {
          pipelineDone = true;
          if (apiDone) {
            if (!apiData) { result.innerHTML = '<div class="pdx-error">Analysis failed.</div>'; return; }
            if (apiData.error === 'payment_required') { result.innerHTML = ''; showPaymentRequiredResult(result, 'automation', 'Browser Automation', apiData); return; }
            renderAutomationResult(result, apiData); loadJobHistory('automation', 'pdx-auto-jobs'); showNotif('Task analyzed — Job ' + (apiData.job_id || ''), 'success');
          }
        });
        apiFetch('POST', '/automation/submit', { url: url, task: task, format: format }).then(function(data) {
          apiData = data; apiDone = true;
          if (pipelineDone) {
            if (!data) { result.innerHTML = '<div class="pdx-error">Analysis failed.</div>'; return; }
            if (data.error === 'payment_required') { result.innerHTML = ''; showPaymentRequiredResult(result, 'automation', 'Browser Automation', data); return; }
            renderAutomationResult(result, data); loadJobHistory('automation', 'pdx-auto-jobs'); showNotif('Task analyzed — Job ' + (data.job_id || ''), 'success');
          }
        });
      });
    }

    function renderAutomationResult(container, data) {
      var r = data.result || {};
      var plan = r.execution_plan || r;
      var page = r.page_extraction || {};
      var report = r.extraction_report || {};
      var steps     = plan.steps     || r.steps     || [];
      var dataPoints= plan.data_points || r.data_points || r.selectors || [];
      var obstacles = plan.obstacles || r.obstacles || r.challenges || [];
      var html = '<div class="pdx-result">';

      html += '<div class="pdx-scan-complete"><div class="pdx-scan-complete-dot"></div>' +
        '<span>Task analyzed</span>' +
        (data.job_id ? '<span class="pdx-scan-complete-time">Job: ' + escHtml(data.job_id) + '</span>' : '') +
      '</div>';

      if (r.sandbox && r.sandbox.note) {
        html += '<div class="pdx-info-box">' + escHtml(r.sandbox.note) + '</div>';
      }
      if (page.title) {
        html += '<div class="pdx-kv-grid pdx-mt-sm">' + kvRow('Page title', page.title) + kvRow('HTTP', String(page.http_code || '')) + '</div>';
      }
      if (report.summary) {
        html += '<div class="pdx-ai-summary-v5"><div class="pdx-ai-label-v5">Extraction Report</div><div class="pdx-ai-text">' + escHtml(report.summary) + '</div></div>';
      }

      /* Complexity metrics */
      var complexity = plan.estimated_seconds || r.complexity || r.complexity_score || '';
      var estTime    = plan.estimated_seconds || r.estimated_seconds || r.estimated_time || r.duration_ms ? Math.round(r.duration_ms / 1000) : '';
      html += '<div class="pdx-metric-grid">' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + steps.length + '</div><div class="pdx-metric-label">Steps</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + dataPoints.length + '</div><div class="pdx-metric-label">Data Points</div></div>' +
        '<div class="pdx-metric-card' + (obstacles.length ? ' pdx-metric-card--amber' : '') + '"><div class="pdx-metric-value">' + obstacles.length + '</div><div class="pdx-metric-label">Obstacles</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + (estTime ? estTime + 's' : complexity || '—') + '</div><div class="pdx-metric-label">' + (estTime ? 'Est. Time' : 'Complexity') + '</div></div>' +
      '</div>';

      /* AI Analysis */
      if (r.analysis || r.approach || r.summary) {
        html += '<div class="pdx-ai-summary-v5"><div class="pdx-ai-label-v5">AI Task Analysis</div>' +
          '<div class="pdx-ai-text">' + escHtml(safeStr(r.analysis || r.approach || r.summary)).replace(/\n/g,'<br>') + '</div></div>';
      }

      /* Execution steps */
      if (steps.length) {
        html += '<div class="pdx-evidence-section"><button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">Execution Plan (' + steps.length + ' steps) <span class="pdx-evidence-toggle-arrow">▼</span></button>' +
          '<div class="pdx-evidence-body"><ol class="pdx-steps-list">';
        steps.forEach(function(s) { html += '<li>' + escHtml(safeStr(s)) + '</li>'; });
        html += '</ol></div></div>';
      }

      /* Data extraction points */
      if (dataPoints.length) {
        html += '<div class="pdx-evidence-section"><button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">Data Extraction Points (' + dataPoints.length + ') <span class="pdx-evidence-toggle-arrow">▼</span></button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        dataPoints.slice(0,12).forEach(function(d) {
          var label    = typeof d === 'object' ? (d.name || d.label || d.field || safeStr(d)) : safeStr(d);
          var selector = typeof d === 'object' ? (d.selector || d.xpath || d.css || '') : '';
          html += kvRow(label, selector || 'Identified');
        });
        html += '</div></div></div>';
      }

      /* Obstacles */
      if (obstacles.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">⚠ Potential Obstacles</div><div class="pdx-factors">';
        obstacles.forEach(function(o) {
          var name = typeof o === 'object' ? (o.name || o.type || safeStr(o)) : safeStr(o);
          var desc = typeof o === 'object' ? (o.description || o.detail || '') : '';
          var sev  = typeof o === 'object' ? (o.severity || 'medium') : 'medium';
          var cls  = sev === 'high' ? 'pdx-factor--high' : 'pdx-factor--medium';
          html += '<div class="pdx-factor ' + cls + '"><span class="pdx-factor-name">' + escHtml(name) + '</span><span class="pdx-factor-val">' + escHtml(desc.slice(0,80)) + '</span></div>';
        });
        html += '</div></div>';
      }

      /* ── Summary ── */
      var autoSummary = data.ai_summary || r.analysis || r.approach ||
        'Browser automation task analyzed. ' +
        steps.length + ' execution step' + (steps.length !== 1 ? 's' : '') + ' identified' +
        (dataPoints.length ? ', ' + dataPoints.length + ' data extraction point' + (dataPoints.length !== 1 ? 's' : '') + ' mapped' : '') +
        (obstacles.length ? '. ' + obstacles.length + ' potential obstacle' + (obstacles.length !== 1 ? 's' : '') + ' detected — review before execution' : '') + '.';

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-automation') + '</span>' +
        '<span class="pdx-report-summary-title">Task Analysis Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(autoSummary) + '</div>' +
      '</div>';

      html += rawSection('Raw Response', data);
      html += '<button class="pdx-btn-ghost pdx-btn-sm pdx-export-btn" style="margin-top:8px">Export Plan</button>';
      html += '</div>';
      container.innerHTML = html;
      var expBtn = container.querySelector('.pdx-export-btn');
      if (expBtn) expBtn.addEventListener('click', function() { exportJSON('automation-task', data); });
    }

    /* ══════════════════════════════════════════════════════
       CONNECTORS
    ══════════════════════════════════════════════════════ */
    function renderConnectors(mod, access, locked) {
      if (locked) { renderPaywall(mod, access); return; }
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('connectors') + '<span>Connectors</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Ready"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Live API integration testing — REST, Slack, Airtable, Notion, GitHub, Zapier. Test connections, inspect responses, measure latency, and configure webhooks.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">REST API</span>' +
              '<span class="pdx-cap-tag">Webhooks</span>' +
              '<span class="pdx-cap-tag">Response Inspect</span>' +
              '<span class="pdx-cap-tag">Latency Check</span>' +
              '<span class="pdx-cap-tag">Auth Testing</span>' +
            '</div>' +
          '</div>' +
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
        var connStages = [
          { label: 'Resolving endpoint',          detail: 'DNS resolution and network path validation',    duration: 380 },
          { label: 'Establishing connection',     detail: 'Opening TCP/TLS connection to target host',     duration: 520 },
          { label: 'Authenticating',              detail: 'Validating credentials and authorization',      duration: 460 },
          { label: 'Sending test request',        detail: 'Dispatching probe request to endpoint',         duration: 400 },
          { label: 'Inspecting response',         detail: 'Parsing headers, status code, and body',        duration: 340 },
          { label: 'Measuring latency',           detail: 'Calculating round-trip time and throughput',    duration: 280 },
        ];
        var connLogLines = [
          'Connector test initialized for: ' + endpoint,
          'Resolving DNS for endpoint host…',
          'Opening ' + (endpoint.startsWith('https') ? 'TLS' : 'TCP') + ' connection…',
          'Validating auth token / credentials…',
          'Dispatching test request [' + type + ']…',
          'Parsing response headers and body…',
          'Measuring round-trip latency…',
        ];

        result.innerHTML = buildDeepPipeline('pdx-conn-pipeline', connStages, {
          title: 'Connection Test — ' + type, showLog: true,
        });

        var apiDone = false, pipelineDone = false, apiData = null;
        runDeepPipeline('pdx-conn-pipeline', connStages, { logLines: connLogLines }).then(function() {
          pipelineDone = true;
          if (apiDone) {
            if (!apiData) { result.innerHTML = '<div class="pdx-error">Test failed.</div>'; return; }
            if (apiData.error === 'payment_required') { result.innerHTML = ''; showPaymentRequiredResult(result, 'connectors', 'Connectors', apiData); return; }
            renderConnectorResult(result, apiData);
          }
        });
        apiFetch('POST', '/connectors/test', { type: type, endpoint: endpoint, auth_token: auth }).then(function(data) {
          apiData = data; apiDone = true;
          if (pipelineDone) {
            if (!data) { result.innerHTML = '<div class="pdx-error">Test failed.</div>'; return; }
            if (data.error === 'payment_required') { result.innerHTML = ''; showPaymentRequiredResult(result, 'connectors', 'Connectors', data); return; }
            renderConnectorResult(result, data);
          }
        });
      });
    }

    function renderConnectorResult(container, data) {
      var ok         = data.ok;
      var statusCode = data.status_code || data.status || 0;
      var latency    = data.latency_ms  || data.latency || 0;
      var headers    = data.headers     || {};
      var html = '<div class="pdx-result">';

      /* ── Status banner ── */
      html += '<div class="pdx-conn-status ' + (ok ? 'pdx-conn-status--ok' : 'pdx-conn-status--fail') + '">' +
        (ok ? svgIcon('check') : svgIcon('alert')) +
        '<span>' + (ok ? 'Connection successful' : 'Connection failed') + '</span>' +
        '<span class="pdx-tag" style="margin-left:auto">HTTP ' + statusCode + '</span>' +
        '<span class="pdx-tag">' + latency + 'ms</span>' +
      '</div>';

      /* ── Error detail ── */
      if (data.error) {
        html += '<div class="pdx-error"><strong>Error:</strong> ' + escHtml(safeStr(data.error)) + '</div>';
      }

      /* ── Metrics grid ── */
      html += '<div class="pdx-metric-grid">' +
        '<div class="pdx-metric-card' + (ok ? ' pdx-metric-card--green' : ' pdx-metric-card--red') + '"><div class="pdx-metric-value">' + statusCode + '</div><div class="pdx-metric-label">HTTP Status</div></div>' +
        '<div class="pdx-metric-card' + (latency > 1000 ? ' pdx-metric-card--amber' : latency > 500 ? '' : ' pdx-metric-card--green') + '"><div class="pdx-metric-value">' + latency + '</div><div class="pdx-metric-label">Latency (ms)</div></div>' +
        (data.content_length !== undefined ? '<div class="pdx-metric-card"><div class="pdx-metric-value">' + (data.content_length > 1024 ? (data.content_length/1024).toFixed(1)+'KB' : data.content_length+'B') + '</div><div class="pdx-metric-label">Response Size</div></div>' : '') +
        (data.redirect_count !== undefined ? '<div class="pdx-metric-card"><div class="pdx-metric-value">' + data.redirect_count + '</div><div class="pdx-metric-label">Redirects</div></div>' : '') +
      '</div>';

      /* ── Response body ── */
      if (data.response !== undefined && data.response !== null) {
        var resp = typeof data.response === 'object'
          ? JSON.stringify(data.response, null, 2)
          : safeStr(data.response);
        html += '<div class="pdx-evidence-section">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'Response Body <span class="pdx-tag" style="margin-left:6px">' + (data.content_type || 'text') + '</span>' +
            '<span class="pdx-evidence-toggle-arrow" style="margin-left:auto">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><pre class="pdx-code">' + escHtml(resp.slice(0, 2000)) + (resp.length > 2000 ? '\n… (truncated)' : '') + '</pre></div>' +
        '</div>';
      }

      /* ── Response headers ── */
      var headerKeys = Object.keys(headers);
      if (headerKeys.length) {
        html += '<div class="pdx-evidence-section">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            'Response Headers (' + headerKeys.length + ') <span class="pdx-evidence-toggle-arrow">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        headerKeys.forEach(function(k) { html += kvRow(k, safeStr(headers[k])); });
        html += '</div></div></div>';
      }

      /* ── Security headers audit ── */
      var secHeaders = ['strict-transport-security','content-security-policy','x-frame-options','x-content-type-options','referrer-policy','permissions-policy'];
      var presentSec = secHeaders.filter(function(h) { return headers[h] || headers[h.toLowerCase()]; });
      var missingSec = secHeaders.filter(function(h) { return !headers[h] && !headers[h.toLowerCase()]; });
      if (ok) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Security Header Audit</div><div class="pdx-kv-grid">';
        presentSec.forEach(function(h) { html += '<div class="pdx-kv-row"><span class="pdx-kv-key">' + escHtml(h) + '</span><span class="pdx-kv-val" style="color:var(--pdx-green)">✓ Present</span></div>'; });
        missingSec.forEach(function(h) { html += '<div class="pdx-kv-row"><span class="pdx-kv-key">' + escHtml(h) + '</span><span class="pdx-kv-val" style="color:var(--pdx-yellow)">⚠ Missing</span></div>'; });
        html += '</div></div>';
      }

      /* ── Connection summary ── */
      var connSummary = ok
        ? 'Connection to endpoint succeeded. HTTP ' + statusCode + ' response received in ' + latency + 'ms.' +
          (missingSec.length ? ' ' + missingSec.length + ' security header' + (missingSec.length !== 1 ? 's are' : ' is') + ' missing — consider adding them to improve security posture.' : ' All checked security headers are present.')
        : 'Connection failed' + (data.error ? ': ' + safeStr(data.error) : '.') + ' Verify the endpoint URL, authentication credentials, and network accessibility.';

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-connectors') + '</span>' +
        '<span class="pdx-report-summary-title">Connection Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(connSummary) + '</div>' +
      '</div>';

      html += rawSection('Raw Response', data);
      html += '</div>';
      container.innerHTML = html;
    }


    /* ══════════════════════════════════════════════════════
       DEVELOPMENT SERVICES (Create)
    ══════════════════════════════════════════════════════ */
    function renderCreate(mod) {
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('create') + '<span>Development Services</span></div>' +
            '<div class="pdx-ph-desc">Custom digital product development — submit a project brief and receive a scoped proposal, timeline estimate, and cost breakdown within 24 hours.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">Web Apps</span>' +
              '<span class="pdx-cap-tag">AI Integration</span>' +
              '<span class="pdx-cap-tag">API / Backend</span>' +
              '<span class="pdx-cap-tag">Automation</span>' +
              '<span class="pdx-cap-tag">Security Audit</span>' +
            '</div>' +
          '</div>' +
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
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('workspace') + '<span>Workspaces</span></div>' +
            '<div class="pdx-ph-desc">Persistent saved projects, investigation boards, scan history, and AI memory across all modules. Search, pin, archive, and export your intelligence work.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">Saved Projects</span>' +
              '<span class="pdx-cap-tag">Scan History</span>' +
              '<span class="pdx-cap-tag">AI Memory</span>' +
              '<span class="pdx-cap-tag">Search</span>' +
              '<span class="pdx-cap-tag">Export</span>' +
            '</div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-input-row"><input id="pdx-ws-search" class="pdx-input" placeholder="Search workspaces..." /><button id="pdx-ws-search-btn" class="pdx-btn-ghost">Search</button></div>' +
            '<div class="pdx-tabs" id="pdx-ws-tabs">' +
              '<button class="pdx-tab is-active" data-tab="all">All</button>' +
              '<button class="pdx-tab" data-tab="security">Security</button>' +
              '<button class="pdx-tab" data-tab="ai">AI</button>' +
              '<button class="pdx-tab" data-tab="pinned">Pinned</button>' +
            '</div>' +
            '<div id="pdx-ws-list"><div class="pdx-loading">Loading workspaces...</div></div>' +
            '<div class="pdx-section-sm"><div class="pdx-section-label">Live Activity</div><div id="pdx-activity-feed" class="pdx-activity-feed"><div class="pdx-empty">Waiting for events...</div></div></div>' +
          '</div>' +
        '</div>';

      loadWorkspaces('');
      refreshActivityFeed();

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

    /* Per-module benefit copy shown on the paywall screen. */
    var PDX_MODULE_FEATURES = {
      threat: [
        'CVE lookup across NVD & CIRCL databases',
        'Real-time threat feed aggregation',
        'Attack surface mapping with risk scoring',
        'Infrastructure graph visualisation',
        'IOC search & STIX export'
      ],
      osint: [
        'Deep domain & IP intelligence',
        'VirusTotal & Shodan integration',
        'Email discovery via Hunter.io',
        'IOC extraction & timeline reconstruction',
        'Full investigation report with AI summary'
      ],
      personas: [
        'Specialist AI personas (Analyst, Developer, Strategist)',
        'Persistent memory across sessions',
        'Full conversation history & export',
        'Context-aware responses per role',
        'Workspace save & recall'
      ],
      builder: [
        'Visual AI workflow builder',
        'Chain LLM steps, transforms & logic',
        'Reusable template library',
        'One-click flow execution',
        'Export & deploy pipelines'
      ],
      pipeline: [
        'Multi-agent task orchestration',
        'Role-based agent assignment',
        'Handoff tracing & execution logs',
        'Parallel & sequential agent chains',
        'Full trace export'
      ],
      automation: [
        'AI-assisted browser task analysis',
        'Structured step-by-step execution plans',
        'Data extraction & scraping reports',
        'Job queue with async processing',
        'Result export & workspace save'
      ],
      connectors: [
        'Live REST, Slack, Airtable & Notion testing',
        'GitHub & Zapier integration',
        'Response inspection & latency checks',
        'Webhook configuration',
        'Connector library with saved configs'
      ],
      investigation: [
        'Unified multi-source investigation board',
        'Cross-module evidence correlation',
        'Timeline & event reconstruction',
        'Collaborative case notes',
        'Export full investigation report'
      ],
      graph: [
        'Interactive infrastructure graph',
        'Node relationship mapping',
        'Live data from Shodan & DNS',
        'Exportable graph snapshots',
        'Drill-down host details'
      ],
      team: [
        'Shared team workspaces',
        'Role-based access control',
        'Collaborative case management',
        'Audit trail per member',
        'Invite & manage team members'
      ]
    };

    function paywallFeaturesHtml(modId) {
      var features = PDX_MODULE_FEATURES[modId];
      if (!features || !features.length) return '';
      var items = features.map(function(f) {
        return '<li class="pdx-pwf-item">' +
          '<svg class="pdx-pwf-check" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2,8 6,12 14,4"/></svg>' +
          '<span>' + escHtml(f) + '</span>' +
          '</li>';
      }).join('');
      return '<ul class="pdx-paywall-features">' + items + '</ul>';
    }

    function renderPaywall(mod, access) {
      // Use live access data (from /pay/status) for price/currency/description — never stale config.
      var price    = (access && access.price  != null) ? access.price  : (mod.price || mod.default_price || 0);
      var currency = (access && access.currency)       ? access.currency : (mod.currency || 'USD');
      var desc     = (access && access.description)    ? access.description : (mod.description || '');
      var priceFormatted = parseFloat(price).toFixed(2);
      var modId    = mod.id || '';

      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon(modId) + '<span>' + escHtml(mod.label || 'Module') + '</span></div>' +
          '</div>' +
          '<div class="pdx-ph-body">' +
            '<div class="pdx-paywall">' +
              '<div class="pdx-paywall-icon">' + svgIcon('lock-paywall') + '</div>' +
              '<div class="pdx-paywall-title">Premium Access</div>' +
              '<div class="pdx-paywall-desc">' + escHtml(desc) + '</div>' +
              '<div class="pdx-paywall-price">' +
                '<span class="pdx-paywall-currency">' + escHtml(currency) + '</span>' +
                '<span class="pdx-paywall-amount">' + priceFormatted + '</span>' +
                '<span class="pdx-paywall-period">one-time</span>' +
              '</div>' +
              '<button class="pdx-btn-primary pdx-btn-full pdx-unlock-btn"' +
                ' data-module="' + escHtml(modId) + '"' +
                ' data-price="' + escHtml(String(price)) + '"' +
                ' data-currency="' + escHtml(currency) + '">' +
                'Unlock Access' +
              '</button>' +
              paywallFeaturesHtml(modId) +
            '</div>' +
          '</div>' +
        '</div>';

      var unlockBtn = panelInner.querySelector('.pdx-unlock-btn');
      if (unlockBtn) {
        unlockBtn.addEventListener('click', function(e) {
          var b = e.currentTarget;
          initiatePayment(b.dataset.module, parseFloat(b.dataset.price), b.dataset.currency);
        });
      }
    }

    function renderPaywallInline(mod, access) {
      var price    = (access && access.price  != null) ? access.price  : (mod.price || 0);
      var currency = (access && access.currency)       ? access.currency : (mod.currency || 'USD');
      var priceFormatted = parseFloat(price).toFixed(2);
      return '<div class="pdx-paywall-inline">' +
        '<div class="pdx-paywall-inline-title">Preview limit reached</div>' +
        '<div class="pdx-paywall-inline-desc">Unlock full access to continue.</div>' +
        '<button class="pdx-btn-primary pdx-unlock-btn"' +
          ' data-module="' + escHtml(mod.id || '') + '"' +
          ' data-price="' + escHtml(String(price)) + '"' +
          ' data-currency="' + escHtml(currency) + '">' +
          'Unlock — ' + escHtml(currency) + ' ' + priceFormatted +
        '</button>' +
      '</div>';
    }

    function initiatePayment(moduleId, price, currency) {
      // Disable all unlock buttons in the panel to prevent double-clicks.
      panelInner.querySelectorAll('.pdx-unlock-btn').forEach(function(b) {
        b.disabled = true;
        b.textContent = 'Creating order…';
      });

      apiFetch('POST', '/pay/create', { module_id: moduleId }).then(function(data) {
        if (!data || data.error) {
          showNotif(data && data.error ? data.error : 'Payment unavailable', 'error');
          panelInner.querySelectorAll('.pdx-unlock-btn').forEach(function(b) {
            b.disabled = false;
            b.textContent = 'Unlock Access';
          });
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
                        // Refresh access state then re-open so panel reflects new tier.
                        apiFetch('GET', '/pay/status').then(function(s) {
                          if (s) state.accessStatus = s;
                          openPanel(moduleId);
                        });
                      } else {
                        showNotif('Payment capture failed. Please contact support.', 'error');
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

    /* ══════════════════════════════════════════════════════
       INTERACTION + PIPELINE ENGINE  v7.0
    ══════════════════════════════════════════════════════ */

    var PDX_PIPELINE_SPEED = 0.45;
    var PDX_INTEL_MODULES = { trust: 1, osint: 1, threat: 1, investigation: 1, graph: 1 };
    var PDX_INTEL_MIN_DISPLAY_MS = 600;

    function isIntelModule(mod) {
      return !!(mod && PDX_INTEL_MODULES[mod]);
    }

    function pipelineSpeedForModule(mod, cfgSpeed) {
      if (typeof cfgSpeed === 'number') return cfgSpeed;
      return isIntelModule(mod) ? 1.05 : PDX_PIPELINE_SPEED;
    }

    function minDisplayForPipeline(cfg) {
      if (typeof cfg.minDisplayMs === 'number') return cfg.minDisplayMs;
      return isIntelModule(cfg && cfg.module) ? PDX_INTEL_MIN_DISPLAY_MS : 0;
    }

    function whenMinDisplayElapsed(startedAt, minMs) {
      if (!minMs || minMs <= 0) return Promise.resolve();
      var remain = minMs - (Date.now() - startedAt);
      if (remain <= 0) return Promise.resolve();
      return new Promise(function (resolve) { setTimeout(resolve, remain); });
    }

    function setBtnBusy(btn, busy, busyLabel) {
      if (!btn) return;
      if (busy) {
        if (!btn.dataset.pdxLabel) btn.dataset.pdxLabel = btn.textContent;
        btn.classList.add('pdx-btn--busy');
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        if (busyLabel) btn.textContent = busyLabel;
      } else {
        btn.classList.remove('pdx-btn--busy');
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        if (btn.dataset.pdxLabel) btn.textContent = btn.dataset.pdxLabel;
      }
    }

    function isBtnBusy(btn) {
      return !!(btn && btn.classList.contains('pdx-btn--busy'));
    }

    /**
     * Parallel staged pipeline + API with instant UI and button guard.
     */
    function runIntelPipeline(cfg) {
      var btn = cfg.btn;
      var resultEl = cfg.result;
      if (!resultEl) return;
      if (isBtnBusy(btn)) return;

      var mod = cfg.module || 'osint';
      var startedAt = Date.now();
      var minMs = minDisplayForPipeline(cfg);

      setBtnBusy(btn, true, cfg.busyLabel || 'Running…');
      resultEl.innerHTML = buildDeepPipeline(cfg.id, cfg.stages, {
        title: cfg.title,
        showLog: cfg.showLog !== false,
        module: mod,
      });

      var apiDone = false;
      var pipelineDone = false;
      var minDone = false;
      var apiData = null;
      var finished = false;

      function finish() {
        if (finished) return;
        finished = true;
        if (typeof win.pdxStopAiStageRotator === 'function') {
          win.pdxStopAiStageRotator('#' + cfg.id);
        }
        setBtnBusy(btn, false);
        if (!apiData || apiData.error || apiData._ok === false) {
          if (cfg.onError) cfg.onError(resultEl, apiData);
          else {
            var errDetail = (apiData && (apiData.error || apiData.message)) ? ': ' + (apiData.error || apiData.message) : '';
            resultEl.innerHTML =
              '<div class="pdx-error">' +
              escHtml(cfg.errorMsg || 'Operation failed. Please try again.') +
              escHtml(errDetail) +
              '</div>';
          }
          return;
        }
        if (apiData.error === 'payment_required' && cfg.onPayment) {
          cfg.onPayment(resultEl, apiData);
          return;
        }
        if (cfg.onSuccess) cfg.onSuccess(resultEl, apiData);
      }

      function tryFinish() {
        if (!apiDone) return;
        // Show results as soon as the API responds — staged animation is non-blocking.
        if (apiData !== null) {
          finish();
          return;
        }
        if (!pipelineDone || !minDone) return;
        finish();
      }

      runDeepPipeline(cfg.id, cfg.stages, {
        logLines: cfg.logLines,
        speed: pipelineSpeedForModule(mod, cfg.speed),
        findings: cfg.findings,
        onStage: cfg.onStage,
        module: mod,
      }).then(function () {
        pipelineDone = true;
        tryFinish();
      });

      whenMinDisplayElapsed(startedAt, minMs).then(function () {
        minDone = true;
        tryFinish();
      });

      Promise.resolve(cfg.api()).then(function (data) {
        apiData = data;
        apiDone = true;
        tryFinish();
      }).catch(function () {
        apiData = null;
        apiDone = true;
        tryFinish();
      });
    }

    /**
     * Build a deep pipeline UI.
     * @param {string} pipelineId  - unique id for the pipeline container
     * @param {Array}  stages      - [{label, detail?, duration?}]
     * @param {object} opts        - { title, subtitle, showLog, showTimer }
     */
    function buildDeepPipeline(pipelineId, stages, opts) {
      opts = opts || {};
      var stageRows = stages.map(function(s, i) {
        return '<div class="pdx-dp-stage" data-idx="' + i + '">' +
          '<div class="pdx-dp-stage-left">' +
            '<div class="pdx-dp-stage-icon">' +
              '<span class="pdx-dp-stage-spinner"></span>' +
              '<span class="pdx-dp-stage-check">' + svgIcon('check') + '</span>' +
            '</div>' +
            '<div class="pdx-dp-stage-line"></div>' +
          '</div>' +
          '<div class="pdx-dp-stage-right">' +
            '<div class="pdx-dp-stage-label">' + escHtml(s.label) + '</div>' +
            (s.detail ? '<div class="pdx-dp-stage-detail">' + escHtml(s.detail) + '</div>' : '') +
            '<div class="pdx-dp-stage-timing"></div>' +
          '</div>' +
        '</div>';
      }).join('');

      var mod = opts.module || 'osint';
      var activityHtml = (typeof win.pdxBuildIntelActivity === 'function')
        ? '<div class="pdx-dp-intel-slot">' + win.pdxBuildIntelActivity(mod, opts.title || 'Intelligence pipeline active') + '</div>'
        : '';

      return '<div class="pdx-deep-pipeline" id="' + pipelineId + '">' +
        '<div class="pdx-dp-header">' +
          '<div class="pdx-dp-header-left">' +
            '<div class="pdx-dp-pulse-ring"></div>' +
            '<div class="pdx-dp-title">' + escHtml(opts.title || 'Intelligence Pipeline') + '</div>' +
          '</div>' +
          '<div class="pdx-dp-timer" id="' + pipelineId + '-timer">0.0s</div>' +
        '</div>' +
        activityHtml +
        '<div class="pdx-dp-stages">' + stageRows + '</div>' +
        (opts.showLog !== false ? '<div class="pdx-dp-log" id="' + pipelineId + '-log"></div>' : '') +
        '<div class="pdx-dp-findings" id="' + pipelineId + '-findings"></div>' +
      '</div>';
    }

    /**
     * Animate a deep pipeline with realistic timing.
     * Returns a promise that resolves when all stages complete.
     * @param {string} pipelineId
     * @param {Array}  stages      - same array passed to buildDeepPipeline
     * @param {object} opts        - { logLines, findings, onStage }
     */
    function runDeepPipeline(pipelineId, stages, opts) {
      opts = opts || {};
      var container = document.getElementById(pipelineId);
      if (!container) return Promise.resolve();

      if (isIntelModule(opts.module) && typeof win.pdxWireAiStageRotatorInPipeline === 'function') {
        requestAnimationFrame(function () {
          win.pdxWireAiStageRotatorInPipeline(pipelineId);
        });
      }

      var timerEl   = document.getElementById(pipelineId + '-timer');
      var logEl     = document.getElementById(pipelineId + '-log');
      var findingsEl = document.getElementById(pipelineId + '-findings');
      var stageEls  = container.querySelectorAll('.pdx-dp-stage');

      container.classList.add('pdx-dp--running');
      container.classList.remove('pdx-dp--complete');

      var speed = pipelineSpeedForModule(opts.module, opts.speed);
      var startTime = Date.now();
      var timerInterval = setInterval(function() {
        if (!document.body.contains(container)) { clearInterval(timerInterval); return; }
        if (timerEl) timerEl.textContent = ((Date.now() - startTime) / 1000).toFixed(1) + 's';
      }, 50);

      // Pre-built log lines per stage
      var defaultLogs = [
        'Initializing intelligence pipeline…',
        'Establishing secure analysis channel…',
        'Loading threat intelligence modules…',
        'Configuring correlation engine…',
        'Preparing behavioral analysis subsystem…',
        'Activating anomaly detection…',
        'Compiling risk assessment framework…',
        'Finalizing intelligence report…',
      ];
      var logLines = opts.logLines || (isIntelModule(opts.module) && typeof win.pdxDefaultAiStages === 'function'
        ? win.pdxDefaultAiStages()
        : defaultLogs);

      function appendLog(msg) {
        if (!logEl) return;
        var ts = ((Date.now() - startTime) / 1000).toFixed(2);
        var line = document.createElement('div');
        line.className = 'pdx-dp-log-line is-new';
        line.innerHTML = '<span class="pdx-dp-log-ts">[' + ts + 's]</span> ' + escHtml(msg);
        logEl.appendChild(line);
        setTimeout(function() { line.classList.remove('is-new'); }, 400);
        logEl.scrollTop = logEl.scrollHeight;
      }

      function findingIconKey(finding) {
        if (finding && finding.icon) return finding.icon;
        var t = String((finding && (finding.type || finding.severity)) || 'info').toLowerCase();
        if (t === 'critical' || t === 'error') return 'alert-octagon';
        if (t === 'warn' || t === 'warning') return 'alert';
        if (t === 'ok' || t === 'success') return 'check';
        return 'info';
      }

      function showFinding(finding) {
        if (!findingsEl) return;
        var el = document.createElement('div');
        el.className = 'pdx-dp-finding pdx-dp-finding--' + (finding.type || 'info');
        el.innerHTML =
          '<span class="pdx-dp-finding-icon">' + svgIcon(findingIconKey(finding)) + '</span>' +
          '<div class="pdx-dp-finding-body">' +
            '<div class="pdx-dp-finding-label">' + escHtml(finding.label) + '</div>' +
            (finding.value ? '<div class="pdx-dp-finding-value">' + escHtml(finding.value) + '</div>' : '') +
          '</div>';
        findingsEl.appendChild(el);
      }

      return new Promise(function(resolve) {
        var i = 0;
        var stageDurations = stages.map(function(s) {
          var base = s.duration || (520 + Math.random() * 480);
          return Math.max(200, Math.round(base * speed));
        });

        // Guard: abort pipeline if container is no longer in the DOM.
        // This prevents orphaned timers when the panel closes mid-scan.
        function isAlive() {
          return document.body.contains(container);
        }

        function nextStage() {
          if (!isAlive()) {
            clearInterval(timerInterval);
            resolve();
            return;
          }
          if (i >= stageEls.length) {
            clearInterval(timerInterval);
            if (timerEl) timerEl.classList.add('pdx-dp-timer--done');
            container.classList.remove('pdx-dp--running');
            container.classList.add('pdx-dp--complete');
            resolve();
            return;
          }

          var stageEl = stageEls[i];
          stageEl.classList.add('is-active');

          if (logLines[i]) appendLog(logLines[i]);

          if (opts.findings && opts.findings[i]) {
            var findingIdx = i;
            setTimeout(function() {
              if (isAlive()) showFinding(opts.findings[findingIdx]);
            }, stageDurations[i] * 0.6);
          }

          var timingEl = stageEl.querySelector('.pdx-dp-stage-timing');
          var stageStart = Date.now();
          var stageTimer = setInterval(function() {
            if (!isAlive()) { clearInterval(stageTimer); return; }
            if (timingEl) timingEl.textContent = ((Date.now() - stageStart) / 1000).toFixed(1) + 's';
          }, 100);

          if (opts.onStage) opts.onStage(i, stages[i]);

          setTimeout(function() {
            clearInterval(stageTimer);
            if (!isAlive()) { resolve(); return; }
            stageEl.classList.remove('is-active');
            stageEl.classList.add('is-done');
            if (timingEl) timingEl.textContent = ((Date.now() - stageStart) / 1000).toFixed(1) + 's';
            i++;
            nextStage();
          }, stageDurations[i]);
        }

        appendLog('Intelligence pipeline initialized.');
        requestAnimationFrame(function() {
          requestAnimationFrame(nextStage);
        });
      });
    }

    function setupTabs(tabsId, contentId, renderers) {
      var tabsEl    = document.getElementById(tabsId);
      var contentEl = document.getElementById(contentId);
      if (!tabsEl || !contentEl) return;
      tabsEl.addEventListener('click', function(e) {
        var tab = e.target.closest('.pdx-tab');
        if (!tab) return;
        tabsEl.querySelectorAll('.pdx-tab').forEach(function(t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        var key = tab.dataset.tab;
        if (renderers && renderers[key]) contentEl.innerHTML = renderers[key]();
        // v3 re-bind hooks
        if (key === 'build'     && tabsId === 'pdx-builder-tabs')  bindBuilderBuild();
        if (key === 'run'       && tabsId === 'pdx-pipeline-tabs') bindPipelineRun();
        if (key === 'test'      && tabsId === 'pdx-conn-tabs')     bindConnectorTest();
        if (key === 'templates' && tabsId === 'pdx-builder-tabs')  loadBuilderTemplates();
        if (key === 'templates' && tabsId === 'pdx-pipeline-tabs') loadPipelineTemplates();
        if (key === 'library'   && tabsId === 'pdx-conn-tabs')     loadConnectorLibrary();
        if (key === 'history'   && tabsId === 'pdx-builder-tabs')  loadJobHistory('builder', 'pdx-job-history-pane');
        if (key === 'history'   && tabsId === 'pdx-pipeline-tabs') loadJobHistory('pipeline', 'pdx-job-history-pane');
        // v4 re-bind hooks
        wireTabHandlers(tabsId, key);
      });
      // Wire the initially-active tab
      var activeTab = tabsEl.querySelector('.pdx-tab.is-active');
      if (activeTab) wireTabHandlers(tabsId, activeTab.dataset.tab);
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
        if (typeof window.pdxLoadSavedFlows === 'function') {
          window.pdxLoadSavedFlows('builder', 'pdx-builder-tpl-pane', function(flow) {
            var def = flow.definition || {};
            var nameEl = document.getElementById('pdx-builder-name');
            if (nameEl) nameEl.value = flow.name || 'Saved Flow';
            var inp = document.getElementById('pdx-builder-input');
            if (inp && def.input) inp.value = def.input;
            var stepsEl = document.getElementById('pdx-builder-steps');
            if (stepsEl && def.steps) {
              stepsEl.innerHTML = '';
              def.steps.forEach(function(s, i) {
                var row = document.createElement('div');
                row.className = 'pdx-step';
                row.innerHTML = renderStepRow(i, s.type || 'llm', s.prompt || '');
                stepsEl.appendChild(row);
              });
            }
          });
        }
      });
    }

    function loadPipelineTemplates() {
      apiFetch('GET', '/pipeline/templates').then(function(data) {
        var pane = document.getElementById('pdx-pipeline-tpl-pane');
        if (!pane || !data) return;
        var html = '<div class="pdx-tpl-grid">';
        (data.templates || []).forEach(function(t) {
          html += '<div class="pdx-tpl-card" data-tpl-id="' + escHtml(t.id || '') + '"><div class="pdx-tpl-name">' + escHtml(t.label) + '</div><div class="pdx-tpl-steps">' + (t.agents || []).length + ' agents</div><button type="button" class="pdx-btn-ghost pdx-btn-sm pdx-use-tpl">Use</button></div>';
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
      setTimeout(function () { loadJobHistory(module, 'pdx-job-history-pane'); }, 0);
      return '<div class="pdx-tab-pane" id="pdx-job-history-pane"><div class="pdx-loading">Loading jobs…</div></div>';
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
      if (!C.restUrl) return Promise.resolve(null);
      var opts = {
        method: method,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce || '' },
        credentials: 'same-origin',
      };
      if (body && method !== 'GET') opts.body = JSON.stringify(body);
      return fetch(C.restUrl + path, opts)
        .then(function(r) {
          return r.json().catch(function() { return {}; }).then(function(data) {
            if (!data || typeof data !== 'object') data = {};
            data._httpStatus = r.status;
            data._ok = r.ok;
            return data;
          });
        })
        .catch(function() { return null; });
    }

    function logEvent(module, action, meta) {
      if (!C.analytics) return;
      apiFetch('POST', '/event', { module: module, action: action, meta: meta || {} });
    }

    /* ── Helpers ──────────────────────────────────────────── */
    /* ══════════════════════════════════════════════════════
       GLOBAL TARGET NORMALIZATION (no recursion)
       normalizePdxTarget → strip indicator → detectTargetTypeFromString
    ══════════════════════════════════════════════════════ */
    var _pdxNormalizeDepth = 0;

    /**
     * Strip protocol / query / fragment / path for API host extraction.
     * Does not call type detection.
     */
    function stripPdxIndicator(raw) {
      var original = String(raw == null ? '' : raw).trim();
      if (!original) {
        return { value: '', raw: '', hadProtocol: false, hadPath: false, hadQuery: false };
      }

      if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/i.test(original)) {
        return {
          value: original.toLowerCase(),
          raw: original,
          hadProtocol: false,
          hadPath: false,
          hadQuery: false,
        };
      }

      if (/^([0-9a-f]{32}|[0-9a-f]{40}|[0-9a-f]{64})$/i.test(original)) {
        return {
          value: original.toLowerCase(),
          raw: original,
          hadProtocol: false,
          hadPath: false,
          hadQuery: false,
        };
      }

      var hadProtocol = /^[a-z][a-z0-9+.-]*:\/\//i.test(original);
      var hadQuery = /[?]/.test(original);
      var hadFragment = /#/.test(original);
      var hadPath = hadProtocol && /:\/\/[^/?#]+\/.+/.test(original);

      var s = original;
      s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '');
      s = s.replace(/[?#].*$/, '');
      s = (s.split('/')[0] || '').split(':')[0] || '';
      s = s.replace(/^\.+|\.+$/g, '').toLowerCase();

      return {
        value: s,
        raw: original,
        hadProtocol: hadProtocol,
        hadPath: hadPath,
        hadQuery: hadQuery || hadFragment,
      };
    }

    /**
     * Type detection from an already-normalized indicator string only.
     * Never calls normalizePdxTarget.
     */
    function detectTargetTypeFromString(value, meta) {
      meta = meta || {};
      var t = String(value == null ? '' : value).trim().toLowerCase();
      if (!t) return 'unknown';

      if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t)) return 'email';
      if (/^(\d{1,3}\.){3}\d{1,3}$/.test(t)) return 'ip';
      if (/^[0-9a-f:.]+$/i.test(t) && t.indexOf(':') !== -1 && t.split(':').length >= 2) return 'ip';
      if (/^([0-9a-f]{32}|[0-9a-f]{40}|[0-9a-f]{64})$/i.test(t)) return 'hash';
      if (meta.hadProtocol || meta.hadPath || meta.hadQuery) return 'url';
      if (/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9-]{2,})+$/i.test(t)) return 'domain';
      if (/^[a-z0-9-]+\.[a-z]{2,}$/i.test(t)) return 'domain';
      return 'unknown';
    }

    function normalizePdxTarget(input) {
      if (_pdxNormalizeDepth > 4) {
        return { raw: '', host: '', normalized: '', type: 'unknown', error: 'recursion_guard' };
      }
      _pdxNormalizeDepth++;

      try {
        var raw = String(input == null ? '' : input).trim();
        if (!raw) {
          return { raw: '', host: '', normalized: '', type: 'unknown' };
        }

        var stripped = stripPdxIndicator(raw);
        var normalized = stripped.value;
        var type = detectTargetTypeFromString(normalized, stripped);

        return {
          raw: raw,
          host: normalized,
          normalized: normalized,
          type: type,
          hadProtocol: stripped.hadProtocol,
          hadPath: stripped.hadPath,
          hadQuery: stripped.hadQuery,
        };
      } catch (e) {
        return { raw: String(input || ''), host: '', normalized: '', type: 'unknown', error: 'normalize_failed' };
      } finally {
        _pdxNormalizeDepth--;
      }
    }

    function resolvePdxTarget(input) {
      return normalizePdxTarget(input);
    }

    function applyNormalizedInput(inputEl, norm) {
      if (!inputEl || !norm) return norm.normalized || norm.host || '';
      var display = norm.normalized || norm.host || '';
      if (!display) return '';
      if (norm.raw !== display) {
        if (norm.type === 'url' && /^[a-z][a-z0-9+.-]*:\/\//i.test(norm.raw)) {
          inputEl.value = norm.raw.replace(/[?#].*$/, '');
        } else {
          inputEl.value = display;
        }
      }
      return display;
    }

    function formatSourceStatusNote(st) {
      if (!st) return '';
      var parts = [];
      if (st.message) parts.push(st.message);
      if (st.http) parts.push('HTTP ' + st.http);
      if (st.http_code) parts.push('HTTP ' + st.http_code);
      if (st.duration_ms) parts.push(st.duration_ms + 'ms');
      if (st.parse_status && st.parse_status !== 'ok' && st.parse_status !== 'n/a') {
        parts.push(st.parse_status);
      }
      return parts.join(' · ');
    }

    function mapSourceState(st) {
      if (!st || !st.state) return '';
      if (st.state === 'ok') return 'ok';
      if (st.state === 'partial') return 'warn';
      if (st.state === 'skipped') return 'na';
      return 'err';
    }

    function sourceDotColor(status) {
      if (status === 'ok') return 'var(--pdx-green)';
      if (status === 'warn') return 'var(--pdx-yellow)';
      if (status === 'na') return 'var(--pdx-mute, #484f58)';
      return 'var(--pdx-red)';
    }

    function getReportCoverage(data) {
      var q = (data && data.report_quality) || {};
      return q.coverage_tier || (q.reliable === false ? 'partial' : 'verified');
    }

    function isReportUnverified(data) {
      var risk = (data && data.risk) || {};
      if (risk.verdict === 'insufficient_data') return true;
      return getReportCoverage(data) === 'incomplete';
    }

    function isReportPartial(data) {
      return getReportCoverage(data) === 'partial';
    }

    function isReportUnreliable(data) {
      return isReportUnverified(data) || isReportPartial(data);
    }

    function scanBannerMeta(data, displayTarget, completePrefix) {
      var unverified = isReportUnverified(data);
      var partial = isReportPartial(data);
      var q = (data && data.report_quality) || {};
      var msg;
      if (unverified) {
        msg = q.message || 'Insufficient or failed intelligence — do not treat as verified safe';
      } else if (partial) {
        msg = q.message || 'Partial intelligence — core sources verified; review provider status below';
      } else {
        msg = (completePrefix || 'Analysis complete') + ' — verified assessment';
      }
      return { warn: unverified || partial, message: msg + ' — ' + displayTarget, tier: getReportCoverage(data) };
    }

    function renderCoveragePanel(data, srcStatus) {
      var q = (data && data.report_quality) || {};
      var risk = (data && data.risk) || {};
      var tier = getReportCoverage(data);
      var tierLabel = tier === 'verified' ? 'Verified' : tier === 'partial' ? 'Partial' : 'Incomplete';
      var tierColor = tier === 'verified' ? 'var(--pdx-green)' : tier === 'partial' ? 'var(--pdx-yellow)' : 'var(--pdx-red)';
      var html = '<div class="pdx-section pdx-coverage-panel">' +
        '<div class="pdx-section-title">Assessment Coverage <span class="pdx-tag" style="margin-left:8px;background:' + tierColor + '22;color:' + tierColor + ';border-color:' + tierColor + '44">' + escHtml(tierLabel) + '</span></div>' +
        '<p class="pdx-field-hint" style="margin:0 0 10px">' + escHtml(q.message || '') + '</p>';

      var required = q.required_sources || {};
      Object.keys(required).forEach(function(key) {
        html += '<div class="pdx-source-row">' +
          '<div class="pdx-source-dot" style="background:' + sourceDotColor(mapSourceState({ state: required[key] })) + '"></div>' +
          '<span class="pdx-source-name">' + escHtml(key) + ' (required)</span>' +
          '<span class="pdx-source-status">' + escHtml(required[key]) + '</span></div>';
      });

      var contributors = risk.contributing_sources || q.contributing_sources || [];
      if (contributors.length) {
        html += '<div class="pdx-mt-sm" style="font-size:11px;color:var(--pdx-mid,#8b949e)">Scored from verified sources: ' + escHtml(contributors.join(', ')) + '</div>';
      } else if (isReportUnverified(data)) {
        html += '<div class="pdx-mt-sm" style="font-size:11px;color:var(--pdx-mid,#8b949e)">No verified sources contributed to the risk score.</div>';
      }

      if (risk.indicative_score != null && isReportUnverified(data) && risk.indicative_score !== risk.score) {
        html += '<div class="pdx-mt-sm" style="font-size:11px;color:var(--pdx-lo)">Indicative score (not verified): ' + escHtml(String(risk.indicative_score)) + '/100</div>';
      }

      (q.failed_optional || []).forEach(function(key) {
        var note = formatSourceStatusNote(srcStatus[key]) || 'Unavailable';
        html += '<div class="pdx-source-row"><div class="pdx-source-dot" style="background:var(--pdx-red)"></div>' +
          '<span class="pdx-source-name">' + escHtml(key) + ' (optional)</span>' +
          '<span class="pdx-source-status">' + escHtml(note) + '</span></div>';
      });

      html += '</div>';
      return html;
    }

    function formatThreatBool(val, checked) {
      if (!checked) return 'Not verified';
      return val ? '⚠ Yes' : '✓ No (feeds checked)';
    }

    function buildIntelSourceRows(targetType, ctx) {
      var rows = [];
      var threatNote = function () {
        if (!ctx.threat.checked) return formatSourceStatusNote(ctx.srcStatus.threat) || 'Not checked — reputation unverified';
        if (ctx.threat.malicious > 0) return (ctx.threat.malicious + ' malicious') + (formatSourceStatusNote(ctx.srcStatus.threat) ? ' · ' + formatSourceStatusNote(ctx.srcStatus.threat) : '');
        if (ctx.threat.suspicious > 0) return (ctx.threat.suspicious + ' suspicious') + (formatSourceStatusNote(ctx.srcStatus.threat) ? ' · ' + formatSourceStatusNote(ctx.srcStatus.threat) : '');
        return formatSourceStatusNote(ctx.srcStatus.threat) || 'No malicious hits (feeds checked)';
      };
      var threatStatus = mapSourceState(ctx.srcStatus.threat) || (!ctx.threat.checked ? 'err' : (ctx.threat.malicious > 0 ? 'warn' : (ctx.threat.suspicious > 0 ? 'warn' : 'ok')));

      if (targetType === 'ip') {
        rows.push({
          name: 'IP Network Registration (RDAP)',
          status: mapSourceState(ctx.srcStatus.ip_network) || (ctx.ipNetwork && (ctx.ipNetwork.organization || ctx.ipNetwork.cidr) ? 'ok' : 'err'),
          note: formatSourceStatusNote(ctx.srcStatus.ip_network) || (ctx.ipNetwork && ctx.ipNetwork.organization ? 'Network data retrieved' : 'Unavailable')
        });
        rows.push({
          name: 'Reverse DNS (PTR)',
          status: mapSourceState(ctx.srcStatus.reverse_dns) || (ctx.reverseDns && ctx.reverseDns.hostname ? 'ok' : (ctx.reverseDns && ctx.reverseDns.no_record ? 'na' : 'err')),
          note: formatSourceStatusNote(ctx.srcStatus.reverse_dns) || (ctx.reverseDns && ctx.reverseDns.hostname ? ctx.reverseDns.hostname : (ctx.reverseDns && ctx.reverseDns.no_record ? 'No PTR record' : 'Unavailable'))
        });
        rows.push({
          name: 'Geolocation & ASN',
          status: mapSourceState(ctx.srcStatus.geo) || (ctx.geo && ctx.geo.country ? 'ok' : 'err'),
          note: formatSourceStatusNote(ctx.srcStatus.geo) || (ctx.geo && ctx.geo.country ? [ctx.geo.country, ctx.geo.asn || ctx.geo.org].filter(Boolean).join(' · ') : 'Unavailable')
        });
        rows.push({ name: 'Threat Intelligence Feeds', status: threatStatus, note: threatNote() });
        return rows;
      }

      if (targetType === 'hash') {
        rows.push({ name: 'Threat Intelligence Feeds', status: threatStatus, note: threatNote() });
        return rows;
      }

      if (targetType === 'email') {
        rows.push({
          name: 'Domain Registration (RDAP)',
          status: mapSourceState(ctx.srcStatus.rdap) || (ctx.rdap.registrar ? 'ok' : 'err'),
          note: formatSourceStatusNote(ctx.srcStatus.rdap) || (ctx.rdap.registrar ? 'Data retrieved' : 'Unavailable')
        });
        rows.push({
          name: 'DNS / Email Auth',
          status: mapSourceState(ctx.srcStatus.dns) || ((ctx.dns.mx && ctx.dns.mx.length) || ctx.dns.spf ? 'ok' : 'err'),
          note: formatSourceStatusNote(ctx.srcStatus.dns) || ((ctx.dns.mx && ctx.dns.mx.length) ? ctx.dns.mx.length + ' MX record(s)' : 'No MX/SPF data')
        });
        rows.push({ name: 'Threat Intelligence Feeds', status: threatStatus, note: threatNote() });
        return rows;
      }

      rows.push({
        name: 'RDAP / WHOIS Registry',
        status: mapSourceState(ctx.srcStatus.rdap) || (ctx.rdap.registrar ? 'ok' : 'err'),
        note: formatSourceStatusNote(ctx.srcStatus.rdap) || (ctx.rdap.registrar ? 'Data retrieved' : 'Unavailable')
      });
      rows.push({
        name: 'SSL Labs Assessment',
        status: mapSourceState(ctx.srcStatus.ssl) || (ctx.ssl.assessed ? 'ok' : (mapSourceState(ctx.srcStatus.ssl) === 'na' ? 'na' : 'err')),
        note: ctx.ssl.assessed ? ('Grade ' + ctx.ssl.grade + (formatSourceStatusNote(ctx.srcStatus.ssl) ? ' · ' + formatSourceStatusNote(ctx.srcStatus.ssl) : '')) : (formatSourceStatusNote(ctx.srcStatus.ssl) || 'Not assessed')
      });
      rows.push({
        name: 'DNS Resolver',
        status: mapSourceState(ctx.srcStatus.dns) || ((ctx.dns.a && ctx.dns.a.length) ? 'ok' : 'err'),
        note: (ctx.dns.a && ctx.dns.a.length) ? (ctx.dns.a.length + ' A record(s)' + (formatSourceStatusNote(ctx.srcStatus.dns) ? ' · ' + formatSourceStatusNote(ctx.srcStatus.dns) : '')) : (formatSourceStatusNote(ctx.srcStatus.dns) || 'No records')
      });
      if (targetType === 'url') {
        rows.push({
          name: 'URL Forensics',
          status: mapSourceState(ctx.srcStatus.url_forensics) || 'na',
          note: formatSourceStatusNote(ctx.srcStatus.url_forensics) || 'Redirect & page analysis'
        });
      }
      rows.push({ name: 'Threat Intelligence Feeds', status: threatStatus, note: threatNote() });
      return rows;
    }

    /* ══════════════════════════════════════════════════════
       TARGET TYPE DETECTION
       Determines what kind of indicator is being analysed
       so result renderers can show contextually correct data.
    ══════════════════════════════════════════════════════ */
    /**
     * Detect type from user input — normalizes once, then classifies (no mutual recursion).
     */
    function detectTargetType(target) {
      if (target == null || target === '') return 'unknown';
      var raw = String(target).trim();
      if (!raw) return 'unknown';
      if (/[?#\/]/.test(raw) || /^[a-z][a-z0-9+.-]*:\/\//i.test(raw)) {
        return normalizePdxTarget(raw).type;
      }
      return detectTargetTypeFromString(raw, stripPdxIndicator(raw));
    }

    window.PDXTargetUtil = {
      normalize: normalizePdxTarget,
      resolve: resolvePdxTarget,
      detectType: detectTargetType,
      detectTypeFromString: detectTargetTypeFromString,
      strip: stripPdxIndicator,
    };

    /* ══════════════════════════════════════════════════════
       CONTEXTUAL AI SUMMARY GENERATOR
       Produces a human-readable intelligence summary from
       structured scan data. Used when the API does not
       return an ai_summary field.
    ══════════════════════════════════════════════════════ */
    function generateSummary(type, target, data) {
      var risk      = data.risk      || {};
      var score     = risk.score     || 0;
      var verdict   = risk.verdict   || 'insufficient_data';
      var anomalies = data.anomalies || [];
      var rdap      = (data.sources && data.sources.rdap) || {};
      var ssl       = (data.sources && data.sources.ssl)  || {};
      var threat    = (data.sources && data.sources.threat) || {};
      var unverified = isReportUnverified(data);
      var partial = isReportPartial(data);
      var contributors = (risk.contributing_sources || []).join(', ');

      var verdictText = verdict === 'clean' ? 'no significant threats detected'
        : verdict === 'low'    ? 'low-level risk indicators present'
        : verdict === 'medium' ? 'moderate risk indicators requiring attention'
        : verdict === 'high'   ? 'high-risk indicators detected'
        : verdict === 'critical' ? 'critical risk indicators detected'
        : verdict === 'insufficient_data' ? 'insufficient intelligence was collected for a reliable assessment'
        : 'risk assessment completed with limited source coverage';

      if (unverified) {
        var parts = ['Analysis of ' + target + ' could not be fully verified — ' + verdictText + '.'];
        if (risk.indicative_score != null && risk.indicative_score !== score) {
          parts.push('Indicative score (not verified): ' + risk.indicative_score + '/100.');
        }
        parts.push('Do not treat this target as verified safe until required sources respond.');
        return parts.join(' ');
      }

      if (partial) {
        var pParts = ['Partial intelligence analysis of ' + target + ' — ' + verdictText + '.'];
        if (contributors) pParts.push('Verified score based on: ' + contributors + '.');
        pParts.push('Overall risk score: ' + score + '/100.');
        return pParts.join(' ');
      }

      if (type === 'email') {
        var breached = (data.sources && data.sources.hibp && data.sources.hibp.breached);
        var breachCount = (data.sources && data.sources.hibp && data.sources.hibp.breach_count) || 0;
        var parts = ['Intelligence analysis of ' + target + ' identified ' + verdictText + '.'];
        if (breached) parts.push('This address appears in ' + breachCount + ' known data breach' + (breachCount !== 1 ? 'es' : '') + '.');
        if (anomalies.length) parts.push(anomalies.length + ' anomal' + (anomalies.length === 1 ? 'y was' : 'ies were') + ' detected during analysis.');
        parts.push('Risk score: ' + score + '/100.');
        return parts.join(' ');
      }

      if (type === 'ip') {
        var geo = (data.sources && data.sources.geo) || {};
        var ipNet = (data.sources && data.sources.ip_network) || {};
        var country = geo.country || '';
        var asn = geo.asn || geo.org || ipNet.organization || '';
        var parts = ['IP address ' + target + ' analysis completed — ' + verdictText + '.'];
        if (ipNet.organization) parts.push('Network registrant: ' + ipNet.organization + (ipNet.cidr ? ' (' + ipNet.cidr + ')' : '') + '.');
        if (country) parts.push('Geolocation: ' + country + (asn ? ' (' + asn + ')' : '') + '.');
        if (threat.checked && threat.malicious) parts.push('This IP has been flagged by threat intelligence feeds.');
        else if (!threat.checked) parts.push('Threat feed reputation was not verified.');
        if (anomalies.length) parts.push(anomalies.length + ' anomal' + (anomalies.length === 1 ? 'y' : 'ies') + ' detected.');
        parts.push('Risk score: ' + score + '/100.');
        return parts.join(' ');
      }

      if (type === 'hash') {
        var engines = threat.malicious || 0;
        var total   = threat.total     || 0;
        var parts = ['File hash analysis of ' + target.slice(0,16) + '… completed.'];
        if (total) parts.push(engines + ' of ' + total + ' detection engines flagged this hash as malicious.');
        else parts.push(verdictText.charAt(0).toUpperCase() + verdictText.slice(1) + '.');
        if (anomalies.length) parts.push(anomalies.length + ' anomal' + (anomalies.length === 1 ? 'y' : 'ies') + ' detected.');
        return parts.join(' ');
      }

      if (type === 'domain') {
        var parts = ['Domain intelligence analysis of ' + target + ' completed — ' + verdictText + '.'];
        if (rdap.age_days !== undefined) {
          if (rdap.age_days < 30)       parts.push('Domain is very recently registered (' + rdap.age_days + ' days old), which is a common indicator of malicious infrastructure.');
          else if (rdap.age_days < 180) parts.push('Domain was registered ' + rdap.age_days + ' days ago — relatively new.');
          else                          parts.push('Domain has been registered for ' + Math.floor(rdap.age_days/365) + ' year' + (rdap.age_days >= 730 ? 's' : '') + '.');
        }
        if (ssl.grade) {
          var sslNote = (ssl.grade === 'A+' || ssl.grade === 'A') ? 'SSL/TLS configuration is strong (Grade ' + ssl.grade + ').'
            : ssl.grade === 'B' ? 'SSL/TLS configuration has minor weaknesses (Grade ' + ssl.grade + ').'
            : 'SSL/TLS configuration has significant issues (Grade ' + ssl.grade + ').';
          parts.push(sslNote);
        }
        if (threat.malicious) parts.push('Flagged by threat intelligence feeds as malicious.');
        if (anomalies.length) parts.push(anomalies.length + ' anomal' + (anomalies.length === 1 ? 'y' : 'ies') + ' detected during analysis.');
        parts.push('Overall risk score: ' + score + '/100.');
        return parts.join(' ');
      }

      // Generic fallback
      var parts = ['Analysis of ' + target + ' completed — ' + verdictText + '.'];
      if (anomalies.length) parts.push(anomalies.length + ' anomal' + (anomalies.length === 1 ? 'y' : 'ies') + ' detected.');
      parts.push('Risk score: ' + score + '/100.');
      return parts.join(' ');
    }

    /* ══════════════════════════════════════════════════════
       RAW DATA SECTION
       Wraps any raw/technical data behind a collapsed
       "Technical Data" toggle — never shown by default.
    ══════════════════════════════════════════════════════ */
    function rawSection(label, data) {
      var json = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
      return '<div class="pdx-evidence-section pdx-raw-section">' +
        '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
          '<span style="color:var(--pdx-lo);font-size:10px">⚙</span> ' + escHtml(label || 'Technical Data') +
          ' <span class="pdx-evidence-toggle-arrow">▼</span>' +
        '</button>' +
        '<div class="pdx-evidence-body"><pre class="pdx-code pdx-code--raw">' + escHtml(json.slice(0, 3000)) + (json.length > 3000 ? '\n… (truncated)' : '') + '</pre></div>' +
      '</div>';
    }

    /* ══════════════════════════════════════════════════════
       RECOMMENDATION ENGINE
       Generates contextual recommendations from scan data.
    ══════════════════════════════════════════════════════ */
    function generateRecommendations(type, data) {
      var recs = [];
      var risk   = data.risk   || {};
      var quality = data.report_quality || {};
      var coverage = quality.coverage_tier || 'verified';
      var reliable = coverage === 'verified' && risk.verdict !== 'insufficient_data';
      var rdap   = (data.sources && data.sources.rdap) || {};
      var ssl    = (data.sources && data.sources.ssl)  || {};
      var dns    = (data.sources && data.sources.dns)  || {};
      var threat = (data.sources && data.sources.threat) || {};
      var anomalies = data.anomalies || [];

      if (type === 'domain' || type === 'url') {
        if (rdap.age_days !== undefined && rdap.age_days < 90) recs.push('Exercise caution — this domain was registered recently and may be associated with phishing or fraud campaigns.');
        if (ssl.grade && ssl.grade !== 'A+' && ssl.grade !== 'A') recs.push('SSL/TLS configuration should be reviewed. Consider enabling HSTS and upgrading cipher suites.');
        if (!dns.spf)   recs.push('No SPF record detected. Configure SPF to prevent email spoofing from this domain.');
        if (!dns.dmarc) recs.push('No DMARC policy detected. Implement DMARC to protect against domain impersonation.');
        if (threat.checked && threat.malicious) recs.push('This domain has been flagged as malicious. Block at the network perimeter and investigate any recent connections.');
        if (anomalies.length) recs.push('Investigate the detected anomalies — they may indicate infrastructure abuse or compromise.');
      }
      if (type === 'ip') {
        if (threat.checked && threat.malicious) recs.push('Block this IP at the firewall. It has been flagged by threat intelligence feeds.');
        else if (!threat.checked) recs.push('Threat feeds did not respond — do not treat this IP as verified clean.');
        if (anomalies.length) recs.push('Review network logs for connections to/from this IP address.');
      }
      if (type === 'email') {
        var hibp = (data.sources && data.sources.hibp) || {};
        if (hibp.breached) recs.push('This email address appears in known data breaches. Advise the user to change passwords and enable MFA on all associated accounts.');
        if (anomalies.length) recs.push('Treat communications from this address with caution.');
      }
      if (type === 'hash') {
        var threat2 = (data.sources && data.sources.threat) || {};
        if (threat2.checked && threat2.malicious) recs.push('Quarantine and remove this file immediately. Conduct a full endpoint investigation.');
        else if (!threat2.checked) recs.push('Hash reputation was not verified — do not assume this file is safe.');
        else recs.push('Continue monitoring — a clean result does not guarantee safety for all environments.');
      }
      if (!recs.length && !reliable) recs.push('Re-run the scan after verifying server outbound HTTPS and API connectivity.');
      if (!recs.length && risk.verdict === 'insufficient_data') recs.push('Do not treat this target as safe until intelligence sources return verified data.');
      if (!recs.length && risk.score > 50) recs.push('Elevated risk score detected. Conduct further investigation before trusting this target.');
      if (!recs.length && reliable && (risk.verdict === 'clean' || risk.verdict === 'low')) recs.push('No immediate action required. Continue routine monitoring.');
      return recs;
    }

    function escHtml(str) {
      return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* Convert any value to a readable string — prevents [object Object] */
    function safeStr(v) {
      if (v === null || v === undefined) return '';
      if (typeof v === 'string')  return v;
      if (typeof v === 'number' || typeof v === 'boolean') return String(v);
      if (Array.isArray(v)) return v.map(safeStr).join(', ');
      if (typeof v === 'object') {
        var parts = [];
        Object.keys(v).forEach(function(k) {
          var val = v[k];
          if (val !== null && val !== undefined && val !== '') {
            parts.push(k.replace(/_/g,' ') + ': ' + safeStr(val));
          }
        });
        return parts.join(' · ') || JSON.stringify(v);
      }
      return String(v);
    }

    function kvRow(key, value) {
      var display = (value === null || value === undefined) ? '' : (typeof value === 'object' ? safeStr(value) : String(value));
      return '<div class="pdx-kv-row"><span class="pdx-kv-key">' + escHtml(key) + '</span><span class="pdx-kv-val">' + escHtml(display) + '</span></div>';
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
      if (typeof win.pdxIcon === 'function') {
        return win.pdxIcon(name);
      }
      if (typeof win.pdxActionIcon === 'function') {
        var action = win.pdxActionIcon(name);
        if (action) return action;
      }
      if (typeof win.pdxModuleIcon === 'function') {
        return win.pdxModuleIcon(name);
      }
      return '';
    }

    /* ── Mobile ───────────────────────────────────────────── */
    function setupMobile(C, panel, dock) {
      var bp          = C.mobileBreakpoint   || 680;
      var dockPos     = C.mobileDockPosition || 'under-header';
      var swipeClose  = C.mobileSwipeClose   !== false;
      var hideDock    = C.mobileHideDock     !== false;
      var safeArea    = C.mobileSafeArea     !== false;
      var panelHPct   = Math.min(96, Math.max(50, parseInt(C.mobilePanelHeight, 10) || 90));
      var compactMode = C.mobileCompact      === true;
      var iconSize    = parseInt(C.mobileIconSize, 10)   || 0;
      var btnSize     = parseInt(C.mobileBtnSize, 10)    || 0;
      var dockHeight  = Math.min(72, Math.max(36, parseInt(C.mobileDockHeight, 10) || 48));
      var spacing     = C.mobileSpacing || 'default';
      var scale       = C.mobileScale   || 'auto';
      var root        = document.documentElement;
      var isMobile    = false;

      if (!hideDock) dock.dataset.pdxHideDock = 'false';

      // ── CSS var helpers ──────────────────────────────────
      function setProp(n, v) { root.style.setProperty(n, v); }
      function removeProp(n) { root.style.removeProperty(n); }

      // ── Layout vars (dock-top, panel-top, vh) ────────────
      // stampLayoutVars() was already called synchronously in init()
      // for the initial paint. We call it again on resize/orientation.
      function applyLayout() {
        var abH      = getAdminBarH();
        var dockTop  = abH;
        var panelTop = dockTop + dockHeight;
        var vh       = window.innerHeight * 0.01;
        setProp('--pdx-vh',          vh          + 'px');
        setProp('--pdx-dock-top',    dockTop     + 'px');
        setProp('--pdx-dock-h',      dockHeight  + 'px');
        setProp('--pdx-panel-top',   panelTop    + 'px');
        setProp('--pdx-panel-h-pct', panelHPct   + '');
        if (iconSize > 0) setProp('--pdx-icon', iconSize + 'px');
        if (btnSize  > 0) setProp('--pdx-btn',  btnSize  + 'px');
      }

      function clearLayout() {
        ['--pdx-vh','--pdx-dock-top','--pdx-dock-h','--pdx-panel-top',
         '--pdx-panel-h-pct','--pdx-icon','--pdx-btn'
        ].forEach(removeProp);
      }

      // Close button injected globally via injectCloseBtnGlobal() after each renderPanel().

      // ── Enter / exit mobile mode ─────────────────────────
      function enterMobile() {
        isMobile = true;
        panel.classList.add('pdx-panel--mobile');
        dock.classList.add('pdx-dock--mobile');

        if (pdxRoot) {
          pdxRoot.dataset.mobileDock    = dockPos;
          pdxRoot.dataset.mobileSpacing = spacing;
          pdxRoot.dataset.mobileScale   = scale;
          if (compactMode) pdxRoot.dataset.mobileCompact  = '1';
          if (!safeArea)   pdxRoot.dataset.mobileSafeArea = '0';
        }

        applyLayout();
        injectCloseBtnGlobal();
        injectPanelDragHandle();

        // Strip any leftover inline styles — CSS owns all geometry.
        ['top','bottom','left','right','transform','height','max-height','width'].forEach(function(p) {
          dock.style.removeProperty(p);
          panel.style.removeProperty(p);
        });
      }

      function exitMobile() {
        isMobile = false;
        panel.classList.remove('pdx-panel--mobile');
        dock.classList.remove('pdx-dock--mobile');

        if (pdxRoot) {
          delete pdxRoot.dataset.mobileDock;
          delete pdxRoot.dataset.mobileSpacing;
          delete pdxRoot.dataset.mobileScale;
          delete pdxRoot.dataset.mobileCompact;
          delete pdxRoot.dataset.mobileSafeArea;
        }

        clearLayout();
        dock.style.removeProperty('transform');
        dock.style.removeProperty('opacity');
      }

      // ── Responsive check ─────────────────────────────────
      function check() {
        var nowMobile = window.innerWidth <= bp;
        if (nowMobile && !isMobile)  { enterMobile(); }
        else if (!nowMobile && isMobile) { exitMobile(); }
        else if (nowMobile)          { applyLayout(); }
      }

      // Already stamped in init() — just sync the full state.
      check();

      var resizeRaf;
      window.addEventListener('resize', function() {
        if (resizeRaf) cancelAnimationFrame(resizeRaf);
        resizeRaf = requestAnimationFrame(check);
      });

      window.addEventListener('orientationchange', function() {
        setTimeout(function() { applyLayout(); check(); }, 400);
      });

      // ── Swipe to close (header/handle only — never steal content scroll) ──
      if (!swipeClose) return;

      var tsX = 0, tsY = 0, dismissGesture = false;
      var isUnderHeader = (dockPos === 'under-header');

      function touchInDismissZone(target) {
        return !!(target && target.closest && (
          target.closest('.pdx-panel-drag-handle') ||
          target.closest('.pdx-ph-hd') ||
          target.closest('.pdx-panel-close') ||
          target.closest('.pdx-mobile-close')
        ));
      }

      panel.addEventListener('touchstart', function (e) {
        if (!e.touches.length) return;
        tsX = e.touches[0].clientX;
        tsY = e.touches[0].clientY;
        dismissGesture = touchInDismissZone(e.target);
      }, { passive: true });

      panel.addEventListener('touchend', function (e) {
        if (!dismissGesture || !e.changedTouches.length) return;
        var endX = e.changedTouches[0].clientX;
        var endY = e.changedTouches[0].clientY;
        var dx = Math.abs(endX - tsX);
        var dy = isUnderHeader ? (tsY - endY) : (endY - tsY);
        if (dy > 100 && dx < dy * 0.55) closePanel();
        dismissGesture = false;
      }, { passive: true });
    }

    function injectPanelDragHandle() {
      if (!panel.querySelector('.pdx-panel-drag-handle')) {
        var handle = document.createElement('div');
        handle.type = 'button';
        handle.className = 'pdx-panel-drag-handle';
        handle.setAttribute('aria-label', 'Drag to close panel');
        panelInner.insertBefore(handle, panelInner.firstChild);
        handle.addEventListener('click', closePanel);
      }
    }


    /* ══════════════════════════════════════════════════════
       v4: SSE INFRASTRUCTURE
    ══════════════════════════════════════════════════════ */
    function startSSE(channel, onMessage, _retries) {
      if (!window.EventSource || !C.restUrl) return;
      var retries = _retries || 0;
      if (retries > 5) return; // stop after 5 consecutive failures
      var base = C.restUrl.replace(/\/wp-json\/pdx\/v1\/?$/, '');
      var url  = base + '/wp-json/pdx/v1/sse?channel=' + encodeURIComponent(channel) + '&nonce=' + encodeURIComponent(C.nonce || '');
      var es   = new EventSource(url);
      es.onmessage = onMessage;
      if (!state.sseRetries) state.sseRetries = {};
      es.onopen    = function() { state.sseRetries[channel] = 0; };
      es.onerror   = function() {
        es.close();
        if (state.sseConnections && state.sseConnections[channel] === es) {
          delete state.sseConnections[channel];
        }
        var next = (state.sseRetries[channel] || retries) + 1;
        if (next > 5) return;
        state.sseRetries[channel] = next;
        var delay = Math.min(30000, 3000 * Math.pow(2, next - 1));
        setTimeout(function() { startSSE(channel, onMessage, next); }, delay);
      };
      if (state.sseConnections) state.sseConnections[channel] = es;
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


    function wireUnlockButtons(container) {
      if (!container) return;
      container.querySelectorAll('.pdx-unlock-btn').forEach(function(b) {
        if (b.dataset.pdxWired) return;
        b.dataset.pdxWired = '1';
        b.addEventListener('click', function(e) {
          var btn = e.currentTarget;
          initiatePayment(btn.dataset.module, parseFloat(btn.dataset.price), btn.dataset.currency);
        });
      });
    }

    function showPaymentRequiredResult(container, moduleId, label, data) {
      var mod = Object.assign({}, (C.modules && C.modules[moduleId]) || {}, {
        id: moduleId,
        label: label || moduleId
      });
      container.innerHTML = renderPaywallInline(mod, { price: data.price, currency: data.currency });
      wireUnlockButtons(container);
    }

    function buildQueueBadge() {
      var badge = document.createElement('span');
      badge.id = 'pdx-queue-badge';
      badge.className = 'pdx-dock-queue-badge';
      badge.setAttribute('aria-label', 'Running jobs');
      dock.appendChild(badge);
    }

    function debounce(fn, wait) {
      var timer;
      return function() {
        var ctx = this, args = arguments;
        clearTimeout(timer);
        timer = setTimeout(function() { fn.apply(ctx, args); }, wait);
      };
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
            svgIcon('cmd-search') +
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

      input.addEventListener('input', debounce(function() {
        var q = input.value.trim();
        apiFetch('GET', '/command/search?q=' + encodeURIComponent(q)).then(function(data) {
          currentResults = (data && data.results) || [];
          selectedIdx = 0;
          renderCmdResults(results, currentResults, selectedIdx, handleCmdSelect);
        });
      }, 300));

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
          '<span class="pdx-cmd-icon">' + svgIcon(item.icon || 'info') + '</span>' +
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
      panelInner.innerHTML =
        '<div class="pdx-ph pdx-ph--investigation">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('investigation') + '<span>Investigation Board</span><span class="pdx-badge pdx-badge--new">v4</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Correlation engine active"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Correlate IOCs, build investigation timelines, cluster threat actors, and manage cases with team collaboration. Advanced correlation engine identifies hidden relationships across indicators.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">IOC Correlation</span>' +
              '<span class="pdx-cap-tag">Timeline</span>' +
              '<span class="pdx-cap-tag">Threat Clusters</span>' +
              '<span class="pdx-cap-tag">Case Management</span>' +
              '<span class="pdx-cap-tag">Team Collab</span>' +
            '</div>' +
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
        if (!el || state.activeModule !== 'investigation') return;
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
        if (!el || state.activeModule !== 'investigation') return;
        if (!state.activeTeam) { el.innerHTML = '<div class="pdx-tab-pane"><div class="pdx-empty">No team selected. Create a team first.</div></div>'; return; }
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
      panelInner.innerHTML =
        '<div class="pdx-ph pdx-ph--graph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('graph') + '<span>Infrastructure Graph</span><span class="pdx-badge pdx-badge--new">v4</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Graph engine ready"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Visual IOC relationship mapping — seed any indicator to build an interactive graph of connected infrastructure, threat actors, and related indicators with AI-generated summaries.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">IOC Graph</span>' +
              '<span class="pdx-cap-tag">Pivot Analysis</span>' +
              '<span class="pdx-cap-tag">AI Summary</span>' +
              '<span class="pdx-cap-tag">Export</span>' +
            '</div>' +
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
      var btn = document.getElementById('pdx-graph-btn');
      if (!input || !canvas) return;
      var norm = normalizePdxTarget(input.value);
      if (!norm.host) { showNotif('Enter a valid indicator', 'warn'); return; }
      var value = applyNormalizedInput(input, norm);
      if (isBtnBusy(btn)) return;
      setBtnBusy(btn, true, 'Mapping…');

      var graphStages = [
        { label: 'Seeding IOC graph engine',              detail: 'Initializing relationship traversal from: ' + value,  duration: 440 },
        { label: 'Resolving first-order relationships',   detail: 'Querying direct connections and associations',         duration: 780 },
        { label: 'Expanding second-order nodes',          detail: 'Traversing connected infrastructure nodes',            duration: 860 },
        { label: 'Enriching node metadata',               detail: 'Adding geolocation, ASN, and reputation data',         duration: 720 },
        { label: 'Calculating edge confidence scores',    detail: 'Weighting relationship strength and recency',          duration: 560 },
        { label: 'Running AI graph analysis',             detail: 'Generating natural language infrastructure summary',   duration: 680 },
        { label: 'Rendering relationship graph',          detail: 'Building interactive force-directed visualization',    duration: 380 },
      ];
      var graphLogLines = [
        'Graph engine seeded with IOC: ' + value,
        'Resolving first-order relationships…',
        'Expanding to second-order infrastructure nodes…',
        'Enriching nodes with geolocation and ASN data…',
        'Calculating edge confidence scores…',
        'Running AI infrastructure analysis…',
        'Rendering interactive graph…',
      ];

      // Show pipeline in detail area while canvas is hidden
      canvas.style.display = 'none';
      detail.innerHTML = buildDeepPipeline('pdx-graph-pipeline', graphStages, {
        title: 'Infrastructure Graph — ' + value, showLog: true, module: 'graph',
      });

      var apiDone = false, pipelineDone = false, minDone = false, apiData = null;
      var graphStarted = Date.now();
      function tryGraphFinish() {
        if (!apiDone || !pipelineDone || !minDone) return;
        finalizeGraph(canvas, detail, controls, apiData, value);
      }
      runDeepPipeline('pdx-graph-pipeline', graphStages, { logLines: graphLogLines, module: 'graph' }).then(function() {
        pipelineDone = true;
        tryGraphFinish();
      });
      whenMinDisplayElapsed(graphStarted, PDX_INTEL_MIN_DISPLAY_MS).then(function () {
        minDone = true;
        tryGraphFinish();
      });
      apiFetch('POST', '/intel/correlate', { value: value }).then(function(data) {
        apiData = data; apiDone = true;
        tryGraphFinish();
      });
    }

    function finalizeGraph(canvas, detail, controls, data, value) {
      var btn = document.getElementById('pdx-graph-btn');
      setBtnBusy(btn, false);
      if (!data) { detail.innerHTML = '<div class="pdx-error">Correlation failed.</div>'; return; }
      var nodes = data.nodes || [];
      var edges = data.edges || [];
      state.graphData = { nodes: nodes, edges: edges };
      canvas.style.display = 'block';
      controls.style.display = 'flex';
      drawGraph(canvas, nodes, edges, detail);
      renderGraphLegend(document.getElementById('pdx-graph-legend'));
      if (data.ai_summary) {
        detail.innerHTML = '<div class="pdx-ai-summary"><div class="pdx-ai-label">AI Summary</div><div class="pdx-ai-text">' + escHtml(data.ai_summary) + '</div></div>';
      }
      setupGraphControls(canvas, nodes, edges, detail);
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
        var color = n.type === 'ip' ? '#7e7e7e' : n.type === 'domain' ? '#555555' : n.type === 'hash' ? '#888888' : n.type === 'email' ? '#ffffff' : '#8b8b8b';
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
        var mx = (e.clientX - rect.left) / (canvas.style.transform ? 1 : 1);
        var my = (e.clientY - rect.top);
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
              '<button class="pdx-btn-ghost pdx-mt-sm pdx-graph-pivot-btn" data-ioc="' + escHtml(n.id) + '">Pivot on this IOC</button>' +
            '</div>';
            // Wire pivot button immediately after injecting HTML
            var pivotBtn = detail.querySelector('.pdx-graph-pivot-btn');
            if (pivotBtn) {
              pivotBtn.addEventListener('click', function() {
                var gi = document.getElementById('pdx-graph-input');
                if (gi) { gi.value = pivotBtn.dataset.ioc; buildGraph(); }
              });
            }
          }
        });
      };
    }

    function renderGraphLegend(el) {
      if (!el) return;
      el.innerHTML = ['ip:#7e7e7e', 'domain:#555555', 'hash:#888888', 'email:#ffffff', 'other:#8b8b8b'].map(function(s) {
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
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('team') + '<span>Teams</span><span class="pdx-badge pdx-badge--new">v4</span>' +
              '<span class="pdx-module-status-dot pdx-module-status-dot--online" title="Collaboration active"></span>' +
            '</div>' +
            '<div class="pdx-ph-desc">Manage team members, assign roles, and collaborate on investigations and cases. Role-based access control ensures each member sees only what they need.</div>' +
            '<div class="pdx-module-caps">' +
              '<span class="pdx-cap-tag">Member Management</span>' +
              '<span class="pdx-cap-tag">Role-Based Access</span>' +
              '<span class="pdx-cap-tag">Case Sharing</span>' +
              '<span class="pdx-cap-tag">Collaboration</span>' +
            '</div>' +
          '</div>' +
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
        if (!el || state.activeModule !== 'team') return;
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
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + svgIcon('billing') + '<span>Billing & Plans</span></div>' +
            '<div class="pdx-ph-desc">Manage your subscription, view credit balance, and upgrade your plan to unlock additional modules, higher rate limits, and enterprise features.</div>' +
            '<div class="pdx-module-caps"><span class="pdx-cap-tag">Plan Management</span><span class="pdx-cap-tag">Credit Balance</span><span class="pdx-cap-tag">Usage Analytics</span><span class="pdx-cap-tag">Invoices</span></div>' +
          '</div>' +
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
        var body = panelInner.querySelector('.pdx-ph-body');
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
      panelInner.innerHTML =
        '<div class="pdx-ph">' +
          '<div class="pdx-ph-hd">' +
            '<div class="pdx-ph-title">' + modIcon('memory') + '<span>AI Memory</span><span class="pdx-badge pdx-badge--new">v4</span><span class="pdx-module-status-dot pdx-module-status-dot--online" title="Memory engine active"></span></div>' +
            '<div class="pdx-ph-desc">Long-term agent memory with semantic search — store facts, preferences, findings, and context that persist across all AI sessions and modules.</div>' +
            '<div class="pdx-module-caps"><span class="pdx-cap-tag">Semantic Search</span><span class="pdx-cap-tag">Long-term Storage</span><span class="pdx-cap-tag">Cross-module</span><span class="pdx-cap-tag">Importance Scoring</span></div>' +
          '</div>' +
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
        if (!el || state.activeModule !== 'memory') return;
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
    function formatActivityTime(evt) {
      var ts = evt && evt.ts;
      var d;
      if (typeof ts === 'number') d = new Date(ts < 1e12 ? ts * 1000 : ts);
      else if (ts) d = new Date(ts);
      else d = new Date();
      return isNaN(d.getTime()) ? '' : d.toLocaleTimeString();
    }

    function refreshActivityFeed() {
      var feed = document.getElementById('pdx-activity-feed');
      if (!feed) return;
      var html = '';
      state.liveActivity.slice(0, 30).forEach(function(evt) {
        var cls = evt.severity === 'critical' ? 'pdx-activity--critical' : evt.severity === 'high' ? 'pdx-activity--high' : evt.severity === 'warn' ? 'pdx-activity--warn' : 'pdx-activity--info';
        html += '<div class="pdx-activity-item ' + cls + '">' +
          '<span class="pdx-activity-module">' + escHtml(evt.module || 'system') + '</span>' +
          '<span class="pdx-activity-action">' + escHtml(evt.action || '') + '</span>' +
          '<span class="pdx-activity-time">' + formatActivityTime(evt) + '</span>' +
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
      bindClickOnce(btn, runCorrelate);
      if (!inp.dataset.pdxBound) {
        inp.dataset.pdxBound = '1';
        inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') runCorrelate(); });
      }
      var saved = loadPanelState('investigation');
      if (saved && saved.view === 'result' && saved.data) {
        if (inp && saved.target) inp.value = saved.target;
        var res = document.getElementById('pdx-inv-result');
        if (res) {
          state._skipPanelScrollReset = true;
          renderCorrResult(res, saved.data, saved.target);
          prependRestoreBanner(res, 'investigation', saved.target, runCorrelate);
        }
      }
    }

    function runCorrelate() {
      var inp  = document.getElementById('pdx-inv-input');
      var type = document.getElementById('pdx-inv-type');
      var res  = document.getElementById('pdx-inv-result');
      var btn  = document.getElementById('pdx-inv-btn');
      if (!inp || !res) return;
      var norm = normalizePdxTarget(inp.value);
      if (!norm.host) { showNotif('Enter a valid indicator', 'warn'); return; }
      var value = applyNormalizedInput(inp, norm);
      clearPanelState('investigation');

      var corrStages = [
        { label: 'Initializing correlation engine',       detail: 'Loading IOC relationship graph database',              duration: 480 },
        { label: 'Classifying indicator type',            detail: 'Auto-detecting IOC type: IP / domain / hash / email',  duration: 420 },
        { label: 'Querying threat intelligence graph',    detail: 'Traversing relationship edges in IOC graph',           duration: 860 },
        { label: 'Cross-referencing intelligence feeds',  detail: 'Matching against OTX, Abuse.ch, VirusTotal',          duration: 940 },
        { label: 'Identifying related infrastructure',    detail: 'Mapping connected IPs, domains, and certificates',    duration: 780 },
        { label: 'Running AI relationship analysis',      detail: 'Generating natural language summary of findings',      duration: 720 },
        { label: 'Building correlation report',           detail: 'Compiling relationships and confidence scores',        duration: 420 },
      ];

      runIntelPipeline({
        btn: btn,
        result: res,
        module: 'investigation',
        id: 'pdx-corr-pipeline',
        stages: corrStages,
        title: 'IOC Correlation — ' + value,
        busyLabel: 'Correlating…',
        logLines: [
          'Correlation engine initialized for: ' + value,
          'Classifying IOC type…',
          'Querying IOC relationship graph…',
          'Cross-referencing threat intelligence feeds…',
          'Mapping related infrastructure nodes…',
          'Running AI relationship analysis…',
          'Compiling correlation report…',
        ],
        api: function () {
          return apiFetch('POST', '/intel/correlate', { value: value, type: type ? type.value : '' });
        },
        onSuccess: function (el, data) { renderCorrResult(el, data, value); },
        errorMsg: 'Correlation failed.',
      });
    }

    function renderCorrResult(res, data, value) {
      if (!data) { res.innerHTML = '<div class="pdx-error">Correlation failed.</div>'; return; }
      var edges   = data.edges   || [];
      var nodes   = data.nodes   || [];
      var clusters= data.clusters|| [];
      var html = '<div class="pdx-result">';

      html += '<div class="pdx-scan-complete"><div class="pdx-scan-complete-dot"></div>' +
        '<span>Correlation complete — ' + escHtml(value) + '</span>' +
        '<span class="pdx-scan-complete-time">' + edges.length + ' relationship' + (edges.length !== 1 ? 's' : '') + '</span>' +
      '</div>';

      /* Metrics */
      html += '<div class="pdx-metric-grid">' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + nodes.length + '</div><div class="pdx-metric-label">Nodes</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + edges.length + '</div><div class="pdx-metric-label">Relationships</div></div>' +
        (clusters.length ? '<div class="pdx-metric-card"><div class="pdx-metric-value">' + clusters.length + '</div><div class="pdx-metric-label">Clusters</div></div>' : '') +
        (data.confidence ? '<div class="pdx-metric-card"><div class="pdx-metric-value">' + data.confidence + '%</div><div class="pdx-metric-label">Confidence</div></div>' : '') +
      '</div>';

      /* Relationship table */
      if (edges.length) {
        html += '<div class="pdx-evidence-section"><button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">Relationships (' + edges.length + ') <span class="pdx-evidence-toggle-arrow">▼</span></button>' +
          '<div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        edges.slice(0, 15).forEach(function(e) {
          var src = safeStr(e.source || e.from || '');
          var tgt = safeStr(e.target || e.to   || '');
          var rel = safeStr(e.relation || e.type || e.label || 'related');
          var conf= e.confidence || e.weight || '';
          html += kvRow(escHtml(src) + ' → ' + escHtml(tgt), escHtml(rel) + (conf ? ' (' + conf + '%)' : ''));
        });
        html += '</div></div></div>';
      }

      /* Node inventory */
      if (nodes.length) {
        html += '<div class="pdx-evidence-section"><button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">Infrastructure Nodes (' + nodes.length + ') <span class="pdx-evidence-toggle-arrow">▼</span></button>' +
          '<div class="pdx-evidence-body"><div style="display:flex;flex-wrap:wrap;gap:4px">';
        nodes.slice(0, 30).forEach(function(n) {
          var label = typeof n === 'object' ? (n.label || n.id || n.value || safeStr(n)) : safeStr(n);
          var type  = typeof n === 'object' ? (n.type || n.group || '') : '';
          html += '<span class="pdx-ioc-chip-v5" title="' + escHtml(type) + '">' + escHtml(label) + '</span>';
        });
        if (nodes.length > 30) html += '<span class="pdx-tag">+' + (nodes.length - 30) + ' more</span>';
        html += '</div></div></div>';
      }

      /* AI Summary — always shown */
      var corrSummary = data.ai_summary ||
        'Correlation analysis of "' + value + '" identified ' + edges.length + ' relationship' + (edges.length !== 1 ? 's' : '') +
        ' across ' + nodes.length + ' infrastructure node' + (nodes.length !== 1 ? 's' : '') + '. ' +
        (clusters.length ? clusters.length + ' cluster' + (clusters.length !== 1 ? 's' : '') + ' of related indicators detected. ' : '') +
        (edges.length > 5 ? 'This indicator has significant infrastructure connections — investigate related nodes for further context.' : 'Limited connections found — indicator may be isolated or newly observed.');

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-correlation') + '</span>' +
        '<span class="pdx-report-summary-title">Correlation Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(corrSummary) + '</div>' +
      '</div>';

      html += '<button class="pdx-btn-ghost pdx-btn-sm pdx-mt-sm" id="pdx-corr-graph-btn">View in Infrastructure Graph</button>';
      html += rawSection('Raw Response', data);
      html += '</div>';
      res.innerHTML = html;
      savePanelState('investigation', {
        view: 'result',
        target: value,
        data: data,
      });

      var graphBtn = document.getElementById('pdx-corr-graph-btn');
      if (graphBtn) graphBtn.addEventListener('click', function() {
        openPanel('graph');
        setTimeout(function() {
          var gi = document.getElementById('pdx-graph-input');
          if (gi) { gi.value = value; buildGraph(); }
        }, 250);
      });
    }

    function wireInvTimeline() {
      var btn = document.getElementById('pdx-tl-btn');
      var inp = document.getElementById('pdx-tl-input');
      if (!btn || !inp) return;
      bindClickOnce(btn, function() {
        var norm = normalizePdxTarget(inp.value);
        if (!norm.host && !norm.normalized) return;
        var target = applyNormalizedInput(inp, norm);
        var res = document.getElementById('pdx-tl-result');
        if (!target || !res) return;

        var tlStages = [
          { label: 'Initializing timeline reconstruction',  detail: 'Loading historical intelligence databases',           duration: 460 },
          { label: 'Querying registration history',         detail: 'RDAP historical records and ownership changes',       duration: 680 },
          { label: 'Scanning certificate transparency',     detail: 'SSL/TLS certificate issuance history',                duration: 720 },
          { label: 'Correlating threat feed events',        detail: 'Matching target against historical IOC reports',      duration: 860 },
          { label: 'Reconstructing activity timeline',      detail: 'Ordering events chronologically with confidence',     duration: 580 },
          { label: 'Generating timeline report',            detail: 'Compiling annotated event sequence',                  duration: 380 },
        ];
        var tlLogLines = [
          'Timeline reconstruction initialized for: ' + target,
          'Querying RDAP historical registration records…',
          'Scanning certificate transparency logs…',
          'Correlating against historical threat feed events…',
          'Ordering events chronologically…',
          'Generating annotated timeline report…',
        ];

        res.innerHTML = buildDeepPipeline('pdx-tl-pipeline', tlStages, {
          title: 'Timeline — ' + target, showLog: true,
        });

        var apiDone = false, pipelineDone = false, apiData = null;
        runDeepPipeline('pdx-tl-pipeline', tlStages, { logLines: tlLogLines }).then(function() {
          pipelineDone = true;
          if (apiDone) renderTimelineResult(res, apiData);
        });
        apiFetch('GET', '/intel/timeline?target=' + encodeURIComponent(target) + '&days=90').then(function(data) {
          apiData = data; apiDone = true;
          if (pipelineDone) renderTimelineResult(res, data);
        });
      });
    }

    function renderTimelineResult(res, data) {
      var events = (data && data.timeline) || [];
      if (!events.length) { res.innerHTML = '<div class="pdx-empty">No timeline data found for this target.</div>'; return; }

      var html = '<div class="pdx-result">';

      html += '<div class="pdx-scan-complete"><div class="pdx-scan-complete-dot"></div>' +
        '<span>Timeline reconstructed</span>' +
        '<span class="pdx-scan-complete-time">' + events.length + ' event' + (events.length !== 1 ? 's' : '') + '</span>' +
      '</div>';

      /* Group events by category if available */
      var categories = {};
      events.forEach(function(ev) {
        var cat = ev.category || ev.type || 'Event';
        if (!categories[cat]) categories[cat] = 0;
        categories[cat]++;
      });
      var catKeys = Object.keys(categories);
      if (catKeys.length > 1) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Event Categories</div><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">';
        catKeys.forEach(function(c) {
          html += '<span class="pdx-ioc-chip-v5">' + escHtml(c) + ' <strong>' + categories[c] + '</strong></span>';
        });
        html += '</div></div>';
      }

      /* Timeline */
      html += '<div class="pdx-timeline-v5">';
      events.slice(0, 20).forEach(function(ev) {
        var date = ev.date || ev.timestamp || ev.time || '';
        var desc = ev.description || ev.event || ev.title || safeStr(ev);
        var src  = ev.source || ev.feed || '';
        var sev  = ev.severity || ev.risk || '';
        var dotColor = sev === 'critical' ? '#888888' : sev === 'high' ? '#7e7e7e' : sev === 'medium' ? '#555555' : 'var(--pdx-text-muted)';
        html += '<div class="pdx-tl-event-v5">' +
          '<div class="pdx-tl-dot-v5" style="background:' + dotColor + '"></div>' +
          '<div class="pdx-tl-body-v5">' +
            (date ? '<div class="pdx-tl-date-v5">' + escHtml(date) + (sev ? ' <span style="font-size:9px;padding:1px 5px;border-radius:2px;background:' + dotColor + '22;color:' + dotColor + '">' + escHtml(sev.toUpperCase()) + '</span>' : '') + '</div>' : '') +
            '<div class="pdx-tl-desc-v5">' + escHtml(desc) + '</div>' +
            (src ? '<div class="pdx-tl-source-v5">Source: ' + escHtml(src) + '</div>' : '') +
          '</div>' +
        '</div>';
      });
      if (events.length > 20) {
        html += '<div class="pdx-tl-event-v5"><div class="pdx-tl-dot-v5" style="background:var(--pdx-lo)"></div>' +
          '<div class="pdx-tl-body-v5"><div class="pdx-tl-desc-v5" style="color:var(--pdx-lo)">+ ' + (events.length - 20) + ' more events</div></div></div>';
      }
      html += '</div>';

      /* Summary */
      var earliest = events[events.length - 1];
      var latest   = events[0];
      var tlSummary = events.length + ' event' + (events.length !== 1 ? 's' : '') + ' reconstructed' +
        (earliest && (earliest.date || earliest.timestamp) ? ' from ' + (earliest.date || earliest.timestamp) : '') +
        (latest   && (latest.date   || latest.timestamp)   ? ' to '   + (latest.date   || latest.timestamp)   : '') + '. ' +
        (catKeys.length > 1 ? 'Events span ' + catKeys.length + ' categories: ' + catKeys.join(', ') + '.' : '');

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-timeline') + '</span>' +
        '<span class="pdx-report-summary-title">Timeline Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(tlSummary) + '</div>' +
      '</div>';

      html += rawSection('Raw Response', data);
      html += '</div>';
      res.innerHTML = html;
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
        var contentEl    = document.getElementById('pdx-mem-content');
        var typeEl       = document.getElementById('pdx-mem-type');
        var importanceEl = document.getElementById('pdx-mem-importance');
        var res          = document.getElementById('pdx-mem-store-result');
        if (!contentEl || !typeEl || !importanceEl || !res) return;
        var content    = contentEl.value.trim();
        var mem_type   = typeEl.value;
        var importance = parseInt(importanceEl.value) || 50;
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
       v4: WIRE UP DYNAMIC HANDLERS after tab render
    ══════════════════════════════════════════════════════ */

    function wireThreatFeeds() {
      var btn = document.getElementById('pdx-feeds-sync-btn');
      var res = document.getElementById('pdx-feeds-result');
      if (!btn || !res || btn.dataset.pdxWired) return;
      btn.dataset.pdxWired = '1';
      btn.addEventListener('click', function () {
        runIntelPipeline({
          btn: btn,
          result: res,
          module: 'threat',
          id: 'pdx-feeds-pipeline',
          stages: [
            { label: 'Connecting to feed endpoints', detail: 'AlienVault OTX, Abuse.ch, CISA KEV', duration: 360 },
            { label: 'Validating API credentials', detail: 'Checking rate limits and auth tokens', duration: 320 },
            { label: 'Synchronizing pulse databases', detail: 'Pulling latest IOC pulses', duration: 400 },
            { label: 'Refreshing local IOC cache', detail: 'Updating dock intelligence index', duration: 340 },
          ],
          title: 'Threat Feed Sync',
          busyLabel: 'Syncing…',
          showLog: true,
          logLines: [
            'Threat feed sync initiated…',
            'Authenticating with OTX API…',
            'Pulling Abuse.ch URLhaus updates…',
            'Refreshing CISA KEV catalog…',
          ],
          api: function () {
            return apiFetch('GET', '/threat/feeds').then(function (d) {
              if (!d) return { ok: false, error: 'Empty response from feed sync.' };
              return d;
            });
          },
          onSuccess: function (el, data) { el.innerHTML = renderThreatFeedsList(data); },
          errorMsg: 'Threat feed sync failed.',
        });
      });
    }

    function wireTabHandlers(tabsId, tabKey) {
      // Investigation board
      if (tabsId === 'pdx-inv-tabs') {
        if (tabKey === 'correlate') wireInvCorrelate();
        if (tabKey === 'timeline')  wireInvTimeline();
      }
      if (tabsId === 'pdx-threat-tabs' && tabKey === 'feeds') wireThreatFeeds();
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
          function runCveLookup() {
            var q = cveInp.value.trim();
            var res = document.getElementById('pdx-cve-result');
            if (!q || !res) return;

            var cveStages = [
              { label: 'Querying NVD vulnerability database',  detail: 'Searching NIST National Vulnerability Database',     duration: 520 },
              { label: 'Fetching CVSS scoring data',           detail: 'Retrieving base, temporal, and environmental scores', duration: 480 },
              { label: 'Checking exploit availability',        detail: 'Cross-referencing ExploitDB and Metasploit modules',  duration: 640 },
              { label: 'Correlating affected software',        detail: 'Mapping CPE entries to affected product versions',    duration: 560 },
              { label: 'Retrieving remediation guidance',      detail: 'Fetching vendor advisories and patch information',    duration: 420 },
            ];
            res.innerHTML = buildDeepPipeline('pdx-cve-pipeline', cveStages, { title: 'CVE Analysis — ' + q, showLog: true, module: 'threat' });
            var apiDone = false, pipelineDone = false, apiData = null;
            runDeepPipeline('pdx-cve-pipeline', cveStages, { module: 'threat',
              logLines: [
                'CVE lookup initialized for: ' + q,
                'Querying NVD REST API v2.0…',
                'Fetching CVSS v3.1 scoring vectors…',
                'Checking ExploitDB and Metasploit…',
                'Mapping CPE affected software entries…',
                'Retrieving vendor patch advisories…',
              ]
            }).then(function() {
              pipelineDone = true;
              if (apiDone) renderCVEResult(res, apiData, q);
            });
            apiFetch('GET', '/threat/cve?q=' + encodeURIComponent(q)).then(function(data) {
              if (!data || data._ok === false || data.error) {
                res.innerHTML = '<div class="pdx-error">' + escHtml((data && (data.error || data.message)) || 'CVE lookup failed.') + '</div>';
                return;
              }
              apiData = data; apiDone = true;
              if (pipelineDone) renderCVEResult(res, data, q);
            });
          }
          bindClickOnce(cveBtn, runCveLookup);
          if (!cveInp.dataset.pdxBound) {
            cveInp.dataset.pdxBound = '1';
            cveInp.addEventListener('keydown', function(e) { if (e.key === 'Enter') runCveLookup(); });
          }
        }
      }
      // Attack surface
      if (tabsId === 'pdx-threat-tabs' && tabKey === 'surface') {
        var surfBtn = document.getElementById('pdx-surface-btn');
        var surfInp = document.getElementById('pdx-surface-input');
        if (surfBtn && surfInp) {
          function runSurfaceScan() {
            var norm = normalizePdxTarget(surfInp.value);
            if (!norm.host) { showNotif('Enter a valid domain', 'warn'); return; }
            var domain = applyNormalizedInput(surfInp, norm);
            var res = document.getElementById('pdx-surface-result');
            if (!res) return;

            var surfStages = [
              { label: 'Initializing attack surface scanner',  detail: 'Loading Shodan and DNS enumeration modules',          duration: 440 },
              { label: 'Enumerating subdomains',               detail: 'Brute-force and certificate transparency enumeration', duration: 860 },
              { label: 'Scanning exposed ports & services',    detail: 'Querying Shodan internet-wide scan data',              duration: 940 },
              { label: 'Fingerprinting technologies',          detail: 'Identifying web frameworks, servers, and CMS',        duration: 720 },
              { label: 'Checking for known vulnerabilities',   detail: 'Matching services against CVE database',              duration: 680 },
              { label: 'Mapping attack surface',               detail: 'Compiling exposure report with risk ratings',         duration: 480 },
            ];
            res.innerHTML = buildDeepPipeline('pdx-surf-pipeline', surfStages, { title: 'Attack Surface — ' + domain, showLog: true, module: 'threat' });
            var apiDone = false, pipelineDone = false, apiData = null;
            runDeepPipeline('pdx-surf-pipeline', surfStages, { module: 'threat',
              logLines: [
                'Attack surface scanner initialized for: ' + domain,
                'Running subdomain enumeration…',
                'Querying Shodan for exposed services…',
                'Fingerprinting web technologies…',
                'Matching services against CVE database…',
                'Compiling attack surface report…',
              ]
            }).then(function() {
              pipelineDone = true;
              if (apiDone) renderSurfaceResult(res, apiData, domain);
            });
            apiFetch('GET', '/threat/surface?domain=' + encodeURIComponent(domain)).then(function(data) {
              if (!data || data._ok === false || data.error) {
                res.innerHTML = '<div class="pdx-error">' + escHtml((data && (data.error || data.message)) || 'Attack surface scan failed.') + '</div>';
                return;
              }
              apiData = data; apiDone = true;
              if (pipelineDone) renderSurfaceResult(res, data, domain);
            });
          }
          bindClickOnce(surfBtn, runSurfaceScan);
          if (!surfInp.dataset.pdxBound) {
            surfInp.dataset.pdxBound = '1';
            surfInp.addEventListener('keydown', function(e) { if (e.key === 'Enter') runSurfaceScan(); });
          }
        }
      }
    }

    /* ══════════════════════════════════════════════════════
       CVE RESULT RENDERER
    ══════════════════════════════════════════════════════ */
    function renderCVEResult(container, data, q) {
      if (!data || data._ok === false || data.error) {
        container.innerHTML = '<div class="pdx-error">' + escHtml(data && (data.error || data.message) ? (data.error || data.message) : 'CVE lookup failed.') + '</div>';
        return;
      }
      if (!data.cves || !data.cves.length) { container.innerHTML = '<div class="pdx-empty">No CVEs found for "' + escHtml(q) + '".</div>'; return; }
      var cves = data.cves.slice(0, 8);
      var html = '<div class="pdx-result">';
      html += '<div class="pdx-scan-complete"><div class="pdx-scan-complete-dot"></div><span>' + cves.length + ' CVE' + (cves.length !== 1 ? 's' : '') + ' found for "' + escHtml(q) + '"</span></div>';

      cves.forEach(function(c) {
        var cvss = parseFloat(c.cvss || c.cvss_score || 0);
        var severity = cvss >= 9 ? 'critical' : cvss >= 7 ? 'high' : cvss >= 4 ? 'medium' : 'low';
        var sevColor = cvss >= 9 ? '#888888' : cvss >= 7 ? '#7e7e7e' : cvss >= 4 ? '#555555' : '#8b8b8b';
        html += '<div class="pdx-evidence-section">' +
          '<button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">' +
            '<span style="font-family:var(--pdx-mono);color:var(--pdx-hi)">' + escHtml(c.id || 'CVE') + '</span>' +
            '<span style="margin-left:8px;padding:1px 7px;border-radius:3px;font-size:10px;background:' + sevColor + '22;color:' + sevColor + ';border:1px solid ' + sevColor + '44">' + severity.toUpperCase() + (cvss ? ' ' + cvss.toFixed(1) : '') + '</span>' +
            '<span class="pdx-evidence-toggle-arrow" style="margin-left:auto">▼</span>' +
          '</button>' +
          '<div class="pdx-evidence-body">' +
            '<div class="pdx-prose" style="margin-bottom:10px">' + escHtml((c.description || '').slice(0, 400)) + '</div>' +
            '<div class="pdx-kv-grid">';
        if (c.cvss || c.cvss_score) html += kvRow('CVSS Score', safeStr(c.cvss || c.cvss_score) + ' / 10 (' + severity + ')');
        if (c.cvss_vector)   html += kvRow('CVSS Vector',   c.cvss_vector);
        if (c.published)     html += kvRow('Published',     c.published);
        if (c.modified)      html += kvRow('Last Modified', c.modified);
        if (c.cwe)           html += kvRow('CWE',           safeStr(c.cwe));
        if (c.affected && c.affected.length) html += kvRow('Affected', c.affected.slice(0,4).map(safeStr).join(', '));
        if (c.references && c.references.length) html += kvRow('References', c.references.length + ' advisories');
        if (c.exploit_available !== undefined) html += kvRow('Exploit Available', c.exploit_available ? '⚠ Yes' : '✓ No');
        if (c.patch_available !== undefined)   html += kvRow('Patch Available',   c.patch_available   ? '✓ Yes' : '✗ No');
        html += '</div>';
        if (c.remediation) html += '<div style="margin-top:8px"><div class="pdx-section-title" style="margin-bottom:4px">Remediation</div><div class="pdx-prose">' + escHtml(c.remediation) + '</div></div>';
        html += '</div></div>';
      });

      /* ── CVE Summary ── */
      var critCount = cves.filter(function(c){ return parseFloat(c.cvss||c.cvss_score||0) >= 9; }).length;
      var highCount = cves.filter(function(c){ var s=parseFloat(c.cvss||c.cvss_score||0); return s>=7&&s<9; }).length;
      var cveSummary = cves.length + ' CVE' + (cves.length !== 1 ? 's' : '') + ' found for "' + q + '". ' +
        (critCount ? critCount + ' critical' + (highCount ? ', ' + highCount + ' high severity. ' : '. ') : (highCount ? highCount + ' high severity. ' : '')) +
        (cves.some(function(c){ return c.exploit_available; }) ? 'At least one exploit is publicly available — prioritize patching.' : 'No public exploits confirmed in this result set.');

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-cve') + '</span>' +
        '<span class="pdx-report-summary-title">Vulnerability Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(cveSummary) + '</div>' +
      '</div>';

      html += rawSection('Raw Response', data);
      html += '</div>';
      container.innerHTML = html;
    }

    /* ══════════════════════════════════════════════════════
       ATTACK SURFACE RESULT RENDERER
    ══════════════════════════════════════════════════════ */
    function renderSurfaceResult(container, data, domain) {
      if (!data || data._ok === false || data.error) {
        container.innerHTML = '<div class="pdx-error">' + escHtml(data && (data.error || data.message) ? (data.error || data.message) : 'Attack surface scan failed.') + '</div>';
        return;
      }
      var ports      = data.ports      || [];
      var subdomains = data.subdomains || [];
      var services   = data.services   || [];
      var techs      = data.technologies || data.tech || [];
      var vulns      = data.vulnerabilities || data.vulns || [];
      var dns        = data.dns || [];
      var providers  = data.provider_status || {};
      var warnings   = data.warnings || [];
      var hasData    = ports.length || subdomains.length || services.length || vulns.length || dns.length || techs.length;
      var scanWarn   = data.scan_complete === false || warnings.length > 0;
      var html = '<div class="pdx-result">';

      html += '<div class="pdx-scan-complete' + (scanWarn ? ' pdx-scan-complete--warn' : '') + '">' +
        '<div class="pdx-scan-complete-dot"></div><span>' +
        escHtml(data.summary || (scanWarn ? 'Attack surface scan completed with gaps — ' + domain : 'Attack surface mapped — ' + domain)) +
        '</span></div>';

      if (warnings.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">Provider Warnings</div>';
        warnings.forEach(function(w) { html += '<div class="pdx-anomaly">' + svgIcon('alert') + '<span>' + escHtml(w) + '</span></div>'; });
        html += '</div>';
      }

      if (Object.keys(providers).length) {
        var shodanHost = providers.shodan || {};
        var shodanDns  = providers.shodan_dns || {};
        var hostOk     = shodanHost.state === 'ok' || shodanHost.state === 'partial';
        var dnsSkipped = shodanDns.state === 'skipped';

        html += '<div class="pdx-section"><div class="pdx-section-title">Provider Status</div>';
        if (hostOk && dnsSkipped) {
          html += '<div class="pdx-field-hint pdx-provider-partial-note">Shodan Host API is working; only the Shodan DNS subdomain API is unavailable on your plan. Port, service, and vulnerability data below are from Host API; subdomains use CT/DNS sources.</div>';
        }
        Object.keys(providers).forEach(function(key) {
          var meta = providers[key] || {};
          var st = mapSourceState(meta) || 'err';
          var note = formatSourceStatusNote(meta) || (meta.state || 'unknown');
          if (meta.http) note += ' · HTTP ' + meta.http;
          if (meta.resolved_ip) note += ' · IP ' + meta.resolved_ip;
          if (meta.key_loaded === false) note += ' · key not loaded';
          html += '<div class="pdx-source-row"><div class="pdx-source-dot" style="background:' + sourceDotColor(st) + '"></div>' +
            '<span class="pdx-source-name">' + escHtml(key.replace(/_/g, ' ')) + '</span>' +
            '<span class="pdx-source-status pdx-source-status--' + st + '">' + escHtml(note) + '</span></div>';
        });
        if (data.api_keys && data.api_keys.shodan === false) {
          html += '<div class="pdx-field-hint" style="margin-top:8px">Shodan API key not loaded — configure in Admin → API Keys.</div>';
        } else if (data.resolved_ip) {
          html += '<div class="pdx-field-hint" style="margin-top:8px">Resolved IP: ' + escHtml(data.resolved_ip) + '</div>';
        }
        html += '</div>';
      }

      if (!hasData && !scanWarn) {
        html += '<div class="pdx-empty">No attack surface data returned. Check provider status above for authentication or connectivity errors.</div>';
      }

      /* Metric grid */
      html += '<div class="pdx-metric-grid">' +
        '<div class="pdx-metric-card' + (ports.length > 10 ? ' pdx-metric-card--amber' : '') + '"><div class="pdx-metric-value">' + ports.length + '</div><div class="pdx-metric-label">Open Ports</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + subdomains.length + '</div><div class="pdx-metric-label">Subdomains</div></div>' +
        '<div class="pdx-metric-card"><div class="pdx-metric-value">' + services.length + '</div><div class="pdx-metric-label">Services</div></div>' +
        '<div class="pdx-metric-card' + (vulns.length ? ' pdx-metric-card--red' : ' pdx-metric-card--green') + '"><div class="pdx-metric-value">' + vulns.length + '</div><div class="pdx-metric-label">Vulnerabilities</div></div>' +
      '</div>';

      /* Open ports */
      if (ports.length) {
        html += '<div class="pdx-evidence-section"><button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">Open Ports (' + ports.length + ') <span class="pdx-evidence-toggle-arrow">▼</span></button><div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        ports.slice(0, 20).forEach(function(p) {
          var port = typeof p === 'object' ? (p.port || p.number || safeStr(p)) : safeStr(p);
          var svcEntry = services.find(function(s) { return String(s.port) === String(port); });
          var svc  = svcEntry ? (svcEntry.service || '') : '';
          var banner = svcEntry ? (svcEntry.banner || '') : '';
          html += kvRow('Port ' + port, (svc ? svc : '') + (banner ? ' — ' + banner.slice(0,60) : ''));
        });
        html += '</div></div></div>';
      }

      /* DNS records */
      if (dns.length) {
        html += '<div class="pdx-evidence-section"><button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">DNS Records (' + dns.length + ') <span class="pdx-evidence-toggle-arrow">▼</span></button><div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        dns.slice(0, 20).forEach(function(r) {
          html += kvRow(r.type || 'Record', r.value || '');
        });
        html += '</div></div></div>';
      }

      /* Subdomains */
      if (subdomains.length) {
        html += '<div class="pdx-evidence-section"><button class="pdx-evidence-toggle" onclick="this.closest(\'.pdx-evidence-section\').classList.toggle(\'is-open\')">Subdomains (' + subdomains.length + ') <span class="pdx-evidence-toggle-arrow">▼</span></button><div class="pdx-evidence-body"><div class="pdx-kv-grid">';
        subdomains.slice(0, 15).forEach(function(s) {
          var sub = typeof s === 'object' ? (s.subdomain || s.host || safeStr(s)) : safeStr(s);
          var ip  = typeof s === 'object' ? (s.ip || '') : '';
          html += kvRow(sub, ip || 'Resolved');
        });
        html += '</div></div></div>';
      }

      /* Technologies */
      if (techs.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title">Detected Technologies</div><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">';
        techs.slice(0, 20).forEach(function(t) {
          var name = typeof t === 'object' ? (t.name || safeStr(t)) : safeStr(t);
          var ver  = typeof t === 'object' ? (t.version || '') : '';
          html += '<span class="pdx-ioc-chip-v5">' + escHtml(name) + (ver ? ' ' + escHtml(ver) : '') + '</span>';
        });
        html += '</div></div>';
      }

      /* Vulnerabilities */
      if (vulns.length) {
        html += '<div class="pdx-section"><div class="pdx-section-title pdx-section-title--warn">⚠ Vulnerabilities (' + vulns.length + ')</div><div class="pdx-factors">';
        vulns.slice(0, 8).forEach(function(v) {
          var id   = typeof v === 'object' ? (v.id || v.cve || '') : safeStr(v);
          var desc = typeof v === 'object' ? (v.description || v.title || '') : '';
          var sev  = typeof v === 'object' ? (v.severity || v.risk || 'medium') : 'medium';
          var cls  = sev === 'critical' ? 'pdx-factor--critical' : sev === 'high' ? 'pdx-factor--high' : 'pdx-factor--medium';
          html += '<div class="pdx-factor ' + cls + '"><span class="pdx-factor-name">' + escHtml(id) + '</span><span class="pdx-factor-val">' + escHtml(desc.slice(0,80)) + '</span><span class="pdx-factor-risk">' + escHtml(sev) + '</span></div>';
        });
        html += '</div></div>';
      }

      /* ── Attack Surface Summary ── */
      var surfSummary = 'Attack surface mapping of ' + domain + ' complete. ' +
        ports.length + ' open port' + (ports.length !== 1 ? 's' : '') + ' detected' +
        (subdomains.length ? ', ' + subdomains.length + ' subdomain' + (subdomains.length !== 1 ? 's' : '') + ' enumerated' : '') +
        (techs.length ? ', ' + techs.length + ' technolog' + (techs.length !== 1 ? 'ies' : 'y') + ' fingerprinted' : '') + '. ' +
        (vulns.length
          ? vulns.length + ' potential vulnerabilit' + (vulns.length !== 1 ? 'ies' : 'y') + ' identified — review and remediate before exposure.'
          : 'No known vulnerabilities matched in this scan.');

      html += '<div class="pdx-report-summary">' +
        '<div class="pdx-report-summary-header"><span class="pdx-report-summary-icon">' + svgIcon('report-attack-surface') + '</span>' +
        '<span class="pdx-report-summary-title">Attack Surface Summary</span></div>' +
        '<div class="pdx-report-summary-text">' + escHtml(surfSummary) + '</div>' +
        (vulns.length ? '<div class="pdx-report-recs"><div class="pdx-report-recs-title">Recommendations</div><ul class="pdx-report-recs-list">' +
          '<li>Audit all open ports and close any that are not required for business operations.</li>' +
          (subdomains.length > 5 ? '<li>Review subdomain inventory — unused subdomains increase attack surface.</li>' : '') +
          (vulns.length ? '<li>Prioritise patching identified vulnerabilities, starting with critical and high severity.</li>' : '') +
        '</ul></div>' : '') +
      '</div>';

      html += rawSection('Raw Response', data);
      html += '</div>';
      container.innerHTML = html;
    }

  } /* end init */
}());
