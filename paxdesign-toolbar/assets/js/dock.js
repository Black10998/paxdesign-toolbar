/**
 * PaxDesign Utility Dock — v2.0.2
 *
 * Architecture fix: backdrop and panel are appended directly to <body>
 * so they are never trapped inside a theme's stacking context or
 * overflow:hidden container. The dock rail stays inside #pdx-root.
 */
(function () {
  'use strict';

  /* ─── Boot ─────────────────────────────────────────────── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {

    /* Config injected by wp_localize_script */
    if (typeof PDX_CONFIG === 'undefined') return;
    var C = PDX_CONFIG;

    /* Dock rail must exist */
    var dock = document.getElementById('pdx-dock');
    if (!dock) return;

    /* ── Create backdrop + panel on <body> ──────────────── */
    var backdrop = document.createElement('div');
    backdrop.id  = 'pdx-backdrop';
    document.body.appendChild(backdrop);

    var panel = document.createElement('aside');
    panel.id              = 'pdx-panel';
    panel.setAttribute('role',       'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'Tool panel');

    var inner = document.createElement('div');
    inner.id  = 'pdx-panel-inner';
    panel.appendChild(inner);
    document.body.appendChild(panel);

    /* Position class so CSS knows which side to slide from */
    var pos = (C.position === 'right') ? 'right' : 'left';
    panel.setAttribute('data-position', pos);

    /* ── Apply accent color ─────────────────────────────── */
    if (C.accentColor) {
      document.documentElement.style.setProperty('--pdx-accent', C.accentColor);
    }

    /* ── State ──────────────────────────────────────────── */
    var current = null;

    /* ── SVG icons ──────────────────────────────────────── */
    var IC = {
      shield:   svgWrap('M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'),
      plus:     svgWrap('M12 5v14M5 12h14'),
      user:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/></svg>',
      grid:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v6M14.5 17h6"/></svg>',
      search:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-3.5-3.5"/></svg>',
      link:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
      layers:   svgWrap('M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'),
      pipeline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 12h4l4-5M7 12l4 1 4 4"/></svg>',
      x:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>'
    };

    function svgWrap(d) {
      return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>';
    }

    /* ── Service content ────────────────────────────────── */
    var SVC = {
      create: {
        title: 'Create', sub: 'Custom digital product development',
        desc: 'From concept to deployment — web apps, automation tools, dashboards, and AI-powered systems built to your exact workflow.',
        features: ['Full-stack web application development','Custom admin panels and dashboards','API design and backend architecture','Database design and optimisation','Deployment and DevOps setup']
      },
      personas: {
        title: 'AI Personas', sub: 'Custom AI agents with defined identity',
        desc: 'Branded AI assistants with specific personalities, knowledge bases, and communication styles — for customer service, sales, or internal operations.',
        features: ['Custom personality and tone configuration','Domain-specific knowledge base integration','Multi-channel deployment (web, Telegram, WhatsApp)','Conversation memory and context handling','Analytics and conversation monitoring']
      },
      automation: {
        title: 'Browser Automation', sub: 'End-to-end web workflow automation',
        desc: 'Automate repetitive browser-based tasks — data collection, form processing, monitoring, and multi-step workflows built on Playwright and Puppeteer.',
        features: ['Web scraping and structured data extraction','Form automation and submission pipelines','Scheduled monitoring and alerting','Screenshot and PDF generation at scale','Login-protected site automation']
      },
      osint: {
        title: 'OSINT Agents', sub: 'Automated open-source intelligence',
        desc: 'Structured intelligence gathering from public sources — for research, due diligence, brand monitoring, and threat awareness.',
        features: ['Domain and IP reputation analysis','Social media and public record monitoring','Entity relationship mapping','Automated reporting and alerting','Custom data source integration']
      },
      connectors: {
        title: 'Connectors', sub: 'API integrations and data bridges',
        desc: 'The integrations your stack is missing — connecting CRMs, databases, SaaS platforms, and custom systems so your data flows where it needs to go.',
        features: ['REST and GraphQL API integration','Webhook design and event pipelines','CRM and ERP data synchronisation','Real-time data streaming','Legacy system modernisation bridges']
      },
      builder: {
        title: 'AI Builder', sub: 'Low-code AI system construction',
        desc: 'Custom AI workflows, model integrations, and intelligent automation — designed and deployed without requiring your team to maintain complex code.',
        features: ['LLM workflow design and orchestration','Prompt engineering and optimisation','RAG system design and deployment','Model fine-tuning consultation','AI system monitoring and evaluation']
      },
      pipeline: {
        title: 'Agent Pipeline', sub: 'Multi-agent orchestration systems',
        desc: 'Specialised AI agents that collaborate on complex tasks — research, analysis, generation, and decision pipelines built for your specific use case.',
        features: ['Multi-agent task decomposition design','Autonomous research and synthesis pipelines','Human-in-the-loop workflow integration','Agent monitoring and failure handling','Custom tool and API integration for agents']
      }
    };

    /* ── Open ────────────────────────────────────────────── */
    function openPanel(id) {
      if (current === id) { closePanel(); return; }

      /* Button states */
      var btns = dock.querySelectorAll('.pdx-btn');
      for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('is-active');
        btns[i].setAttribute('aria-expanded', 'false');
      }
      var ab = dock.querySelector('[data-module="' + id + '"]');
      if (ab) { ab.classList.add('is-active'); ab.setAttribute('aria-expanded', 'true'); }

      /* Render */
      inner.innerHTML = id === 'trust' ? buildTrust() : buildService(id);

      /* Show — order matters: set content first, then make visible */
      backdrop.classList.add('is-open');
      panel.classList.add('is-open');
      document.body.classList.add('pdx-no-scroll');
      current = id;

      if (id === 'trust') {
        var inp = document.getElementById('pdx-trust-input');
        if (inp) setTimeout(function () { inp.focus(); }, 260);
      }

      track(id, 'open');
    }

    /* ── Close ───────────────────────────────────────────── */
    function closePanel() {
      panel.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      document.body.classList.remove('pdx-no-scroll');

      var btns = dock.querySelectorAll('.pdx-btn');
      for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('is-active');
        btns[i].setAttribute('aria-expanded', 'false');
      }

      if (current) track(current, 'close');
      current = null;
    }

    /* ── Panel header ────────────────────────────────────── */
    function hdr(iconKey, title, sub) {
      return '<div class="pdx-ph">' +
        '<div class="pdx-ph__left">' +
          '<div class="pdx-ph__icon">' + (IC[iconKey] || IC.shield) + '</div>' +
          '<div><div class="pdx-ph__title">' + x(title) + '</div>' +
               '<div class="pdx-ph__sub">'   + x(sub)   + '</div></div>' +
        '</div>' +
        '<button class="pdx-ph__close" id="pdx-close" type="button" aria-label="Close">' + IC.x + '</button>' +
      '</div>';
    }

    /* ── Service panel ───────────────────────────────────── */
    function buildService(id) {
      var s = SVC[id];
      if (!s) return '<div class="pdx-error">Module not found.</div>';
      var mod  = (C.modules && C.modules[id]) || {};
      var icon = mod.icon || id;
      var li   = s.features.map(function (f) { return '<li>' + x(f) + '</li>'; }).join('');
      var href = x(C.contact || '#contact');
      return hdr(icon, s.title, s.sub) +
        '<p class="pdx-body">' + x(s.desc) + '</p>' +
        '<ul class="pdx-features">' + li + '</ul>' +
        '<div class="pdx-cta">' +
          '<a href="' + href + '" class="pdx-cta-primary">'   + x(C.ctaPrimary   || 'Start a project') + '</a>' +
          '<a href="' + href + '" class="pdx-cta-secondary">' + x(C.ctaSecondary || 'Learn more')      + '</a>' +
        '</div>';
    }

    /* ── Trust panel ─────────────────────────────────────── */
    function buildTrust() {
      return hdr('shield', 'Trust Check', 'Reputation \u00b7 SSL \u00b7 Domain age \u00b7 Risk score') +
        '<div class="pdx-trust-row">' +
          '<input class="pdx-trust-input" id="pdx-trust-input" type="text"' +
                 ' placeholder="domain.com or https://\u2026" autocomplete="off" spellcheck="false"/>' +
          '<button class="pdx-trust-scan" id="pdx-trust-scan" type="button">Scan</button>' +
        '</div>' +
        '<div id="pdx-trust-output"></div>';
    }

    /* ── Trust scan ──────────────────────────────────────── */
    function parseDomain(raw) {
      var s = raw.trim();
      if (!s) return null;
      if (!/^https?:\/\//i.test(s)) s = 'https://' + s;
      try { var u = new URL(s); return { href: s, host: u.hostname }; }
      catch (e) { return null; }
    }

    function runScan() {
      var inp = document.getElementById('pdx-trust-input');
      var out = document.getElementById('pdx-trust-output');
      var btn = document.getElementById('pdx-trust-scan');
      if (!inp || !out || !btn) return;

      var p = parseDomain(inp.value);
      if (!p) { out.innerHTML = '<div class="pdx-error">Enter a valid domain or URL.</div>'; return; }

      btn.disabled = true; btn.textContent = 'Scanning\u2026';
      out.innerHTML = '<div class="pdx-spinner"><div class="pdx-spinner__ring"></div>Checking ' + x(p.host) + '\u2026</div>';

      var restUrl = C.restUrl + '/trust?domain=' + encodeURIComponent(p.host);
      fetch(restUrl, { headers: { 'X-WP-Nonce': C.nonce } })
        .then(function (r) { if (!r.ok) throw 0; return r.json(); })
        .then(function (d) { out.innerHTML = buildResult(p.host, p.href, d.rdap, d.ssl, null); })
        .catch(function () {
          Promise.all([rdap(p.host), ssl(p.host), gsb(p.href)])
            .then(function (r) { out.innerHTML = buildResult(p.host, p.href, r[0], r[1], r[2]); })
            .catch(function () { out.innerHTML = '<div class="pdx-error">Scan failed. Check your connection.</div>'; });
        })
        .finally(function () { btn.disabled = false; btn.textContent = 'Scan'; });
    }

    function rdap(d) { return fetch('https://rdap.org/domain/' + encodeURIComponent(d)).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; }); }
    function ssl(d)  { return fetch('https://api.ssllabs.com/api/v3/analyze?host=' + encodeURIComponent(d) + '&fromCache=on&maxAge=24&all=done').then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; }); }
    function gsb(h)  { return fetch('https://transparencyreport.google.com/transparencyreport/api/v3/safebrowsing/status?site=' + encodeURIComponent(h)).then(function (r) { return r.ok ? r.text() : null; }).catch(function () { return null; }); }

    function buildResult(domain, href, rdapData, sslData, gsbData) {
      var score = 100, good = [], warn = [], bad = [];
      var isHttps = /^https:/i.test(href);
      isHttps ? good.push('HTTPS') : (bad.push('No HTTPS'), score -= 20);

      var regDate = null, registrar = null, age = null;
      if (rdapData) {
        var ents = rdapData.entities || [];
        outer: for (var i = 0; i < ents.length; i++) {
          var vc = ents[i].vcardArray && ents[i].vcardArray[1];
          if (vc) for (var j = 0; j < vc.length; j++) { if (vc[j][0] === 'fn') { registrar = vc[j][3]; break outer; } }
        }
        var evts = rdapData.events || [];
        for (var k = 0; k < evts.length; k++) {
          if (evts[k].eventAction === 'registration') {
            regDate = new Date(evts[k].eventDate);
            age = Math.floor((Date.now() - regDate.getTime()) / 86400000);
            break;
          }
        }
        if (age !== null) {
          if (age < 30) { bad.push('Domain < 30 days'); score -= 30; }
          else if (age < 180) { warn.push('Domain < 6 months'); score -= 12; }
          else good.push('Domain ' + (age < 365 ? age + 'd' : Math.floor(age / 365) + 'y') + ' old');
        }
      }

      var grade = null;
      if (sslData && sslData.endpoints && sslData.endpoints[0]) {
        grade = sslData.endpoints[0].grade || null;
        if (grade) {
          var g0 = grade[0];
          if (g0 === 'A') good.push('SSL ' + grade);
          else if (g0 === 'B') { warn.push('SSL ' + grade); score -= 8; }
          else { bad.push('SSL ' + grade); score -= 20; }
        }
      }

      if (gsbData) {
        try {
          var parsed = JSON.parse(gsbData.replace(/^\)\]\}'\n/, ''));
          var threat = parsed && parsed[0] && parsed[0][1] && parsed[0][1][0];
          if (threat && threat !== 0) { bad.push('Google flagged'); score -= 40; }
          else if (threat === 0) good.push('Google: clean');
        } catch (e) {}
      }

      score = Math.max(0, Math.min(100, score));
      var cls   = score >= 70 ? 'safe'     : score >= 40 ? 'warn'        : 'danger';
      var label = score >= 70 ? 'Low Risk' : score >= 40 ? 'Medium Risk' : 'High Risk';

      var tags = good.map(function (t) { return '<span class="pdx-tag pdx-tag--green">'  + x(t) + '</span>'; }).join('') +
                 warn.map(function (t) { return '<span class="pdx-tag pdx-tag--yellow">' + x(t) + '</span>'; }).join('') +
                 bad.map(function  (t) { return '<span class="pdx-tag pdx-tag--red">'    + x(t) + '</span>'; }).join('');

      var rows = [
        ['Domain',     domain,    ''],
        ['Protocol',   isHttps ? 'HTTPS' : 'HTTP', isHttps ? 'ok' : 'bad'],
        ['SSL Grade',  grade || (isHttps ? 'Present' : 'None'), grade ? (grade[0] === 'A' ? 'ok' : 'mid') : (isHttps ? '' : 'bad')],
        ['Domain Age', age !== null ? (age < 365 ? age + ' days' : Math.floor(age / 365) + ' years') : 'Unavailable', age !== null && age < 30 ? 'bad' : ''],
        ['Registrar',  registrar ? registrar.substring(0, 32) : 'Unavailable', ''],
        ['Registered', regDate ? regDate.toISOString().split('T')[0] : 'Unavailable', '']
      ];

      var rowsHTML = rows.map(function (r) {
        return '<div class="pdx-result__row"><span class="pdx-result__row-key">' + x(r[0]) + '</span>' +
               '<span class="pdx-result__row-val' + (r[2] ? ' pdx-result__row-val--' + r[2] : '') + '">' + x(r[1]) + '</span></div>';
      }).join('');

      return '<div class="pdx-result">' +
        '<div class="pdx-result__score"><span class="pdx-result__num pdx-result__num--' + cls + '">' + score + '</span><span class="pdx-result__label">' + label + '</span></div>' +
        '<div class="pdx-result__bar"><div class="pdx-result__fill pdx-result__fill--' + cls + '" style="width:' + score + '%"></div></div>' +
        '<div class="pdx-result__tags">' + tags + '</div>' +
        '<div class="pdx-result__rows">' + rowsHTML + '</div>' +
        '<p class="pdx-result__source">Sources: RDAP \u00b7 SSL Labs \u00b7 Google Safe Browsing</p>' +
      '</div>';
    }

    /* ── Analytics ───────────────────────────────────────── */
    function track(id, action) {
      if (!C.analytics) return;
      try { fetch(C.restUrl + '/event', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce }, body: JSON.stringify({ module: id, action: action }) }); }
      catch (e) {}
    }

    /* ── XSS escape ──────────────────────────────────────── */
    function x(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    /* ── Event delegation ────────────────────────────────── */
    document.addEventListener('click', function (e) {
      var el = e.target;

      /* Walk up: dock button */
      while (el && el !== document.body) {
        if (el.getAttribute && el.getAttribute('data-module') && dock.contains(el)) {
          openPanel(el.getAttribute('data-module'));
          return;
        }
        el = el.parentNode;
      }

      /* Walk up: close button */
      el = e.target;
      while (el && el !== document.body) {
        if (el.id === 'pdx-close') { closePanel(); return; }
        el = el.parentNode;
      }

      /* Backdrop click */
      if (e.target === backdrop) { closePanel(); return; }

      /* Scan button */
      if (e.target.id === 'pdx-trust-scan') { runScan(); return; }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && current) { closePanel(); return; }
      if (e.key === 'Enter' && e.target && e.target.id === 'pdx-trust-input') { runScan(); }
    });

  } /* end init() */

}());
