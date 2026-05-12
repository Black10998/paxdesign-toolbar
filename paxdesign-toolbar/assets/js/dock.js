/**
 * PaxDesign Utility Dock — v2.0.1
 *
 * Wrapped in DOMContentLoaded so it runs after the footer HTML is in the DOM,
 * regardless of where WordPress injects the <script> tag.
 */
(function () {
  'use strict';

  function init() {

    /* ── Guard: config must exist ───────────────────────── */
    if (typeof PDX_CONFIG === 'undefined') {
      return;
    }

    var CFG = PDX_CONFIG;

    /* ── Guard: DOM elements must exist ─────────────────── */
    var root     = document.getElementById('pdx-root');
    var backdrop = document.getElementById('pdx-backdrop');
    var panel    = document.getElementById('pdx-panel');
    var inner    = document.getElementById('pdx-panel-inner');

    if (!root || !backdrop || !panel || !inner) {
      return;
    }

    /* ── SVG Icons ───────────────────────────────────────── */
    var ICONS = {
      shield:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
      plus:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
      user:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/></svg>',
      grid:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v6M14.5 17h6"/></svg>',
      search:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-3.5-3.5"/></svg>',
      link:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
      layers:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
      pipeline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 12h4l4-5M7 12l4 1 4 4"/></svg>',
      x:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>',
      circle:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="12" cy="12" r="8"/></svg>'
    };

    /* ── Service Content ─────────────────────────────────── */
    var SVC = {
      create: {
        title: 'Create',
        sub:   'Custom digital product development',
        desc:  'From concept to deployment — web apps, automation tools, dashboards, and AI-powered systems built to your exact workflow.',
        features: [
          'Full-stack web application development',
          'Custom admin panels and dashboards',
          'API design and backend architecture',
          'Database design and optimisation',
          'Deployment and DevOps setup'
        ]
      },
      personas: {
        title: 'AI Personas',
        sub:   'Custom AI agents with defined identity',
        desc:  'Branded AI assistants with specific personalities, knowledge bases, and communication styles — for customer service, sales, or internal operations.',
        features: [
          'Custom personality and tone configuration',
          'Domain-specific knowledge base integration',
          'Multi-channel deployment (web, Telegram, WhatsApp)',
          'Conversation memory and context handling',
          'Analytics and conversation monitoring'
        ]
      },
      automation: {
        title: 'Browser Automation',
        sub:   'End-to-end web workflow automation',
        desc:  'Automate repetitive browser-based tasks — data collection, form processing, monitoring, and multi-step workflows built on Playwright and Puppeteer.',
        features: [
          'Web scraping and structured data extraction',
          'Form automation and submission pipelines',
          'Scheduled monitoring and alerting',
          'Screenshot and PDF generation at scale',
          'Login-protected site automation'
        ]
      },
      osint: {
        title: 'OSINT Agents',
        sub:   'Automated open-source intelligence',
        desc:  'Structured intelligence gathering from public sources — for research, due diligence, brand monitoring, and threat awareness.',
        features: [
          'Domain and IP reputation analysis',
          'Social media and public record monitoring',
          'Entity relationship mapping',
          'Automated reporting and alerting',
          'Custom data source integration'
        ]
      },
      connectors: {
        title: 'Connectors',
        sub:   'API integrations and data bridges',
        desc:  'The integrations your stack is missing — connecting CRMs, databases, SaaS platforms, and custom systems so your data flows where it needs to go.',
        features: [
          'REST and GraphQL API integration',
          'Webhook design and event pipelines',
          'CRM and ERP data synchronisation',
          'Real-time data streaming',
          'Legacy system modernisation bridges'
        ]
      },
      builder: {
        title: 'AI Builder',
        sub:   'Low-code AI system construction',
        desc:  'Custom AI workflows, model integrations, and intelligent automation — designed and deployed without requiring your team to maintain complex code.',
        features: [
          'LLM workflow design and orchestration',
          'Prompt engineering and optimisation',
          'RAG system design and deployment',
          'Model fine-tuning consultation',
          'AI system monitoring and evaluation'
        ]
      },
      pipeline: {
        title: 'Agent Pipeline',
        sub:   'Multi-agent orchestration systems',
        desc:  'Specialised AI agents that collaborate on complex tasks — research, analysis, generation, and decision pipelines built for your specific use case.',
        features: [
          'Multi-agent task decomposition design',
          'Autonomous research and synthesis pipelines',
          'Human-in-the-loop workflow integration',
          'Agent monitoring and failure handling',
          'Custom tool and API integration for agents'
        ]
      }
    };

    /* ── State ───────────────────────────────────────────── */
    var current = null;

    /* ── Apply accent color ──────────────────────────────── */
    if (CFG.accentColor) {
      root.style.setProperty('--pdx-accent', CFG.accentColor);
    }

    /* ── Open panel ──────────────────────────────────────── */
    function openPanel(moduleId) {
      if (current === moduleId) { closePanel(); return; }

      var btns = root.querySelectorAll('.pdx-btn');
      for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('is-active');
        btns[i].setAttribute('aria-expanded', 'false');
      }

      var activeBtn = root.querySelector('[data-module="' + moduleId + '"]');
      if (activeBtn) {
        activeBtn.classList.add('is-active');
        activeBtn.setAttribute('aria-expanded', 'true');
      }

      inner.innerHTML = (moduleId === 'trust') ? buildTrustPanel() : buildServicePanel(moduleId);

      panel.classList.add('is-open');
      backdrop.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      current = moduleId;

      if (moduleId === 'trust') {
        var inp = document.getElementById('pdx-trust-input');
        if (inp) { setTimeout(function () { inp.focus(); }, 240); }
      }

      trackEvent(moduleId, 'open');
    }

    /* ── Close panel ─────────────────────────────────────── */
    function closePanel() {
      panel.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      document.body.style.overflow = '';

      var btns = root.querySelectorAll('.pdx-btn');
      for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('is-active');
        btns[i].setAttribute('aria-expanded', 'false');
      }

      if (current) trackEvent(current, 'close');
      current = null;
    }

    /* ── Panel header ────────────────────────────────────── */
    function buildHeader(iconKey, title, sub) {
      var icon = ICONS[iconKey] || ICONS.circle;
      return (
        '<div class="pdx-ph">' +
          '<div class="pdx-ph__left">' +
            '<div class="pdx-ph__icon">' + icon + '</div>' +
            '<div>' +
              '<div class="pdx-ph__title">' + esc(title) + '</div>' +
              '<div class="pdx-ph__sub">'   + esc(sub)   + '</div>' +
            '</div>' +
          '</div>' +
          '<button class="pdx-ph__close" id="pdx-close" type="button" aria-label="Close panel">' +
            ICONS.x +
          '</button>' +
        '</div>'
      );
    }

    /* ── Service panel ───────────────────────────────────── */
    function buildServicePanel(moduleId) {
      var svc = SVC[moduleId];
      if (!svc) {
        return '<div class="pdx-error">Module "' + esc(moduleId) + '" not found.</div>';
      }

      var mod  = (CFG.modules && CFG.modules[moduleId]) ? CFG.modules[moduleId] : {};
      var icon = mod.icon || 'circle';

      var featureItems = '';
      for (var i = 0; i < svc.features.length; i++) {
        featureItems += '<li>' + esc(svc.features[i]) + '</li>';
      }

      var contact      = CFG.contact      || '#contact';
      var ctaPrimary   = CFG.ctaPrimary   || 'Start a project';
      var ctaSecondary = CFG.ctaSecondary || 'Learn more';

      return (
        buildHeader(icon, svc.title, svc.sub) +
        '<p class="pdx-body">' + esc(svc.desc) + '</p>' +
        '<ul class="pdx-features">' + featureItems + '</ul>' +
        '<div class="pdx-cta">' +
          '<a href="' + esc(contact) + '" class="pdx-cta-primary">'   + esc(ctaPrimary)   + '</a>' +
          '<a href="' + esc(contact) + '" class="pdx-cta-secondary">' + esc(ctaSecondary) + '</a>' +
        '</div>'
      );
    }

    /* ── Trust panel ─────────────────────────────────────── */
    function buildTrustPanel() {
      return (
        buildHeader('shield', 'Trust Check', 'Reputation \u00b7 SSL \u00b7 Domain age \u00b7 Risk score') +
        '<div class="pdx-trust-row">' +
          '<input class="pdx-trust-input" id="pdx-trust-input" type="text"' +
                 ' placeholder="domain.com or https://\u2026"' +
                 ' autocomplete="off" spellcheck="false" aria-label="Domain to check"/>' +
          '<button class="pdx-trust-scan" id="pdx-trust-scan" type="button">Scan</button>' +
        '</div>' +
        '<div id="pdx-trust-output"></div>'
      );
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
      var inputEl  = document.getElementById('pdx-trust-input');
      var outputEl = document.getElementById('pdx-trust-output');
      var scanBtn  = document.getElementById('pdx-trust-scan');
      if (!inputEl || !outputEl || !scanBtn) return;

      var parsed = parseDomain(inputEl.value);
      if (!parsed) {
        outputEl.innerHTML = '<div class="pdx-error">Enter a valid domain or URL.</div>';
        return;
      }

      scanBtn.disabled    = true;
      scanBtn.textContent = 'Scanning\u2026';
      outputEl.innerHTML  =
        '<div class="pdx-spinner">' +
          '<div class="pdx-spinner__ring"></div>' +
          'Checking ' + esc(parsed.host) + '\u2026' +
        '</div>';

      var restUrl = CFG.restUrl + '/trust?domain=' + encodeURIComponent(parsed.host);

      fetch(restUrl, { headers: { 'X-WP-Nonce': CFG.nonce } })
        .then(function (r) {
          if (!r.ok) throw new Error('proxy_fail');
          return r.json();
        })
        .then(function (data) {
          outputEl.innerHTML  = buildTrustResult(parsed.host, parsed.href, data.rdap, data.ssl, null);
          scanBtn.disabled    = false;
          scanBtn.textContent = 'Scan';
        })
        .catch(function () {
          Promise.all([
            fetchRDAP(parsed.host),
            fetchSSL(parsed.host),
            fetchGSB(parsed.href)
          ]).then(function (res) {
            outputEl.innerHTML = buildTrustResult(parsed.host, parsed.href, res[0], res[1], res[2]);
          }).catch(function () {
            outputEl.innerHTML = '<div class="pdx-error">Scan failed. Check your connection and try again.</div>';
          }).then(function () {
            scanBtn.disabled    = false;
            scanBtn.textContent = 'Scan';
          });
        });
    }

    function fetchRDAP(domain) {
      return fetch('https://rdap.org/domain/' + encodeURIComponent(domain))
        .then(function (r) { return r.ok ? r.json() : null; })
        .catch(function () { return null; });
    }

    function fetchSSL(domain) {
      return fetch('https://api.ssllabs.com/api/v3/analyze?host=' + encodeURIComponent(domain) + '&fromCache=on&maxAge=24&all=done')
        .then(function (r) { return r.ok ? r.json() : null; })
        .catch(function () { return null; });
    }

    function fetchGSB(href) {
      return fetch('https://transparencyreport.google.com/transparencyreport/api/v3/safebrowsing/status?site=' + encodeURIComponent(href))
        .then(function (r) { return r.ok ? r.text() : null; })
        .catch(function () { return null; });
    }

    /* ── Trust result HTML ───────────────────────────────── */
    function buildTrustResult(domain, href, rdap, ssl, gsb) {
      var score = 100;
      var good = [], warn = [], bad = [];

      var isHttps = /^https:/i.test(href);
      if (isHttps) { good.push('HTTPS'); }
      else         { bad.push('No HTTPS'); score -= 20; }

      var regDate = null, registrar = null, domainAge = null;
      if (rdap) {
        var ents = rdap.entities || [];
        outer: for (var i = 0; i < ents.length; i++) {
          var vcard = ents[i].vcardArray && ents[i].vcardArray[1];
          if (vcard) {
            for (var j = 0; j < vcard.length; j++) {
              if (vcard[j][0] === 'fn') { registrar = vcard[j][3]; break outer; }
            }
          }
        }
        var evts = rdap.events || [];
        for (var k = 0; k < evts.length; k++) {
          if (evts[k].eventAction === 'registration') {
            regDate   = new Date(evts[k].eventDate);
            domainAge = Math.floor((Date.now() - regDate.getTime()) / 86400000);
            break;
          }
        }
        if (domainAge !== null) {
          if      (domainAge < 30)  { bad.push('Domain < 30 days');   score -= 30; }
          else if (domainAge < 180) { warn.push('Domain < 6 months'); score -= 12; }
          else { good.push('Domain ' + (domainAge < 365 ? domainAge + 'd' : Math.floor(domainAge / 365) + 'y') + ' old'); }
        }
      }

      var sslGrade = null;
      if (ssl && ssl.endpoints && ssl.endpoints[0]) {
        sslGrade = ssl.endpoints[0].grade || null;
        if (sslGrade) {
          var g0 = sslGrade.charAt(0);
          if      (g0 === 'A') { good.push('SSL ' + sslGrade); }
          else if (g0 === 'B') { warn.push('SSL ' + sslGrade); score -= 8; }
          else                 { bad.push('SSL '  + sslGrade); score -= 20; }
        }
      }

      if (gsb) {
        try {
          var clean  = gsb.replace(/^\)\]\}'\n/, '');
          var parsed = JSON.parse(clean);
          var threat = parsed && parsed[0] && parsed[0][1] && parsed[0][1][0];
          if (threat && threat !== 0) { bad.push('Google flagged'); score -= 40; }
          else if (threat === 0)      { good.push('Google: clean'); }
        } catch (e) { /* ignore */ }
      }

      score = Math.max(0, Math.min(100, score));
      var cls   = score >= 70 ? 'safe'     : score >= 40 ? 'warn'        : 'danger';
      var label = score >= 70 ? 'Low Risk' : score >= 40 ? 'Medium Risk' : 'High Risk';

      var tags = '';
      for (var gi = 0; gi < good.length; gi++) { tags += '<span class="pdx-tag pdx-tag--green">'  + esc(good[gi]) + '</span>'; }
      for (var wi = 0; wi < warn.length; wi++) { tags += '<span class="pdx-tag pdx-tag--yellow">' + esc(warn[wi]) + '</span>'; }
      for (var bi = 0; bi < bad.length;  bi++) { tags += '<span class="pdx-tag pdx-tag--red">'    + esc(bad[bi])  + '</span>'; }

      var rows = [
        { k: 'Domain',     v: domain,    c: '' },
        { k: 'Protocol',   v: isHttps ? 'HTTPS' : 'HTTP', c: isHttps ? 'ok' : 'bad' },
        { k: 'SSL Grade',  v: sslGrade || (isHttps ? 'Present' : 'None'), c: sslGrade ? (sslGrade.charAt(0) === 'A' ? 'ok' : 'mid') : (isHttps ? '' : 'bad') },
        { k: 'Domain Age', v: domainAge !== null ? (domainAge < 365 ? domainAge + ' days' : Math.floor(domainAge / 365) + ' years') : 'Unavailable', c: (domainAge !== null && domainAge < 30) ? 'bad' : '' },
        { k: 'Registrar',  v: registrar ? registrar.substring(0, 32) : 'Unavailable', c: '' },
        { k: 'Registered', v: regDate ? regDate.toISOString().split('T')[0] : 'Unavailable', c: '' }
      ];

      var rowsHTML = '';
      for (var ri = 0; ri < rows.length; ri++) {
        var r = rows[ri];
        rowsHTML += '<div class="pdx-result__row"><span class="pdx-result__row-key">' + esc(r.k) + '</span><span class="pdx-result__row-val' + (r.c ? ' pdx-result__row-val--' + r.c : '') + '">' + esc(r.v) + '</span></div>';
      }

      return (
        '<div class="pdx-result">' +
          '<div class="pdx-result__score">' +
            '<span class="pdx-result__num pdx-result__num--' + cls + '">' + score + '</span>' +
            '<span class="pdx-result__label">' + label + '</span>' +
          '</div>' +
          '<div class="pdx-result__bar"><div class="pdx-result__fill pdx-result__fill--' + cls + '" style="width:' + score + '%"></div></div>' +
          '<div class="pdx-result__tags">' + tags + '</div>' +
          '<div class="pdx-result__rows">' + rowsHTML + '</div>' +
          '<p class="pdx-result__source">Sources: RDAP \u00b7 SSL Labs \u00b7 Google Safe Browsing</p>' +
        '</div>'
      );
    }

    /* ── Analytics ───────────────────────────────────────── */
    function trackEvent(moduleId, action) {
      if (!CFG.analytics) return;
      try {
        fetch(CFG.restUrl + '/event', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
          body:    JSON.stringify({ module: moduleId, action: action })
        });
      } catch (e) { /* silent */ }
    }

    /* ── XSS escape ──────────────────────────────────────── */
    function esc(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    /* ── Event delegation ────────────────────────────────── */
    document.addEventListener('click', function (e) {
      var target = e.target;

      /* Walk up from click target to find [data-module] */
      var el = target;
      while (el && el !== document.body) {
        if (el.getAttribute && el.getAttribute('data-module') && root.contains(el)) {
          openPanel(el.getAttribute('data-module'));
          return;
        }
        el = el.parentNode;
      }

      /* Walk up to find #pdx-close */
      el = target;
      while (el && el !== document.body) {
        if (el.id === 'pdx-close') { closePanel(); return; }
        el = el.parentNode;
      }

      /* Backdrop */
      if (target === backdrop) { closePanel(); return; }

      /* Scan button */
      if (target.id === 'pdx-trust-scan') { runScan(); return; }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && current) { closePanel(); return; }
      if (e.key === 'Enter' && e.target && e.target.id === 'pdx-trust-input') { runScan(); }
    });

    panel.addEventListener('touchmove', function (e) {
      e.stopPropagation();
    }, { passive: true });

  } /* end init() */

  /* ── Boot ────────────────────────────────────────────────── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

}());
