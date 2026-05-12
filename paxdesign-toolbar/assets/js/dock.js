/**
 * PaxDesign Utility Dock — v2.1.0
 * Full interactive SaaS dock with PayPal payments, AI chat, OSINT, and real tool panels.
 */
(function () {
  'use strict';

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    if (typeof PDX_CONFIG === 'undefined') return;
    var C = PDX_CONFIG;

    var dock = document.getElementById('pdx-dock');
    if (!dock) return;

    /* ── Create body-level backdrop + panel ── */
    var backdrop = document.createElement('div');
    backdrop.id  = 'pdx-backdrop';
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
    panel.setAttribute('data-position', C.position === 'right' ? 'right' : 'left');

    if (C.accentColor) {
      document.documentElement.style.setProperty('--pdx-accent', C.accentColor);
    }

    /* ── State ── */
    var current   = null;
    var accessMap = {};   /* module_id -> {status, tier, label, price, currency} */
    var chatHistory = {}; /* module_id -> [{role,content}] */

    /* Load access status once */
    fetchAccessStatus();

    /* ── Icons ── */
    var IC = {
      shield:   svg('M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'),
      plus:     svg('M12 5v14M5 12h14'),
      user:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/></svg>',
      grid:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M17.5 14v6M14.5 17h6"/></svg>',
      search:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-3.5-3.5"/></svg>',
      link:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
      layers:   svg('M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'),
      pipeline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="2"/><circle cx="19" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 12h4l4-5M7 12l4 1 4 4"/></svg>',
      x:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>',
      send:     svg('M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z'),
      lock:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
      check:    svg('M20 6L9 17l-5-5'),
      paypal:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 11C7 11 6 17 10 17H15C18 17 20 15 20 12C20 9 18 7 15 7H9C6 7 5 9 5 12"/><path d="M5 12C5 12 4 18 8 18H13"/></svg>'
    };
    function svg(d) {
      return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>';
    }
    /* ── Helpers ── */
    function x(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function fmt(amount, currency) {
      try { return new Intl.NumberFormat('en-US',{style:'currency',currency:currency||'USD'}).format(amount); }
      catch(e) { return (currency||'USD') + ' ' + parseFloat(amount).toFixed(2); }
    }
    function spinner(msg) {
      return '<div class="pdx-spinner"><div class="pdx-spinner__ring"></div>' + x(msg||'Loading\u2026') + '</div>';
    }
    function errBox(msg) {
      return '<div class="pdx-error">' + x(msg) + '</div>';
    }
    function hdr(iconKey, title, sub) {
      return '<div class="pdx-ph">' +
        '<div class="pdx-ph__left">' +
          '<div class="pdx-ph__icon">' + (IC[iconKey]||IC.shield) + '</div>' +
          '<div><div class="pdx-ph__title">' + x(title) + '</div>' +
               '<div class="pdx-ph__sub">'   + x(sub)   + '</div></div>' +
        '</div>' +
        '<button class="pdx-ph__close" id="pdx-close" type="button" aria-label="Close">' + IC.x + '</button>' +
      '</div>';
    }
    function statusBadge(acc) {
      if (!acc) return '';
      var cls = acc.status === 'active' ? 'green' : acc.tier === 'preview' ? 'yellow' : 'mute';
      return '<span class="pdx-tag pdx-tag--' + cls + '">' + x(acc.label||acc.status) + '</span>';
    }
    function fetchAccessStatus() {
      fetch(C.restUrl + '/pay/status', { headers: { 'X-WP-Nonce': C.nonce } })
        .then(function(r){ return r.ok ? r.json() : {}; })
        .then(function(d){ accessMap = d || {}; })
        .catch(function(){});
    }
    function post(url, data) {
      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
        body: JSON.stringify(data)
      });
    }
    /* ── Open / Close ── */
    function openPanel(id) {
      if (current === id) { closePanel(); return; }
      dock.querySelectorAll('.pdx-btn').forEach(function(b){
        b.classList.remove('is-active'); b.setAttribute('aria-expanded','false');
      });
      var ab = dock.querySelector('[data-module="'+id+'"]');
      if (ab) { ab.classList.add('is-active'); ab.setAttribute('aria-expanded','true'); }
      inner.innerHTML = spinner('Opening\u2026');
      backdrop.classList.add('is-open');
      panel.classList.add('is-open');
      document.body.classList.add('pdx-no-scroll');
      current = id;
      renderPanel(id);
      track(id, 'open');
    }
    function closePanel() {
      panel.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      document.body.classList.remove('pdx-no-scroll');
      dock.querySelectorAll('.pdx-btn').forEach(function(b){
        b.classList.remove('is-active'); b.setAttribute('aria-expanded','false');
      });
      if (current) track(current, 'close');
      current = null;
    }
    function renderPanel(id) {
      switch(id) {
        case 'trust':      inner.innerHTML = buildTrustPanel();      break;
        case 'create':     inner.innerHTML = buildBriefPanel();      break;
        case 'personas':   inner.innerHTML = buildChatPanel(id);     break;
        case 'automation': inner.innerHTML = buildAutomationPanel(); break;
        case 'osint':      inner.innerHTML = buildOsintPanel();      break;
        case 'connectors': inner.innerHTML = buildConnectorsPanel(); break;
        case 'builder':    inner.innerHTML = buildBuilderPanel();    break;
        case 'pipeline':   inner.innerHTML = buildPipelinePanel();   break;
        default:           inner.innerHTML = errBox('Unknown module.');
      }
    }
    function track(id, action) {
      if (!C.analytics) return;
      try { post(C.restUrl+'/event', {module:id, action:action}); } catch(e){}
    }
    /* ── Panel builders ── */
    function buildTrustPanel() {
      return hdr('shield','Trust Check','Reputation \u00b7 SSL \u00b7 Domain age \u00b7 Risk score') +
        '<div class="pdx-trust-row">' +
          '<input class="pdx-trust-input" id="pdx-trust-input" type="text"' +
                 ' placeholder="domain.com or https://\u2026" autocomplete="off" spellcheck="false"/>' +
          '<button class="pdx-trust-scan" id="pdx-trust-scan" type="button">Scan</button>' +
        '</div><div id="pdx-trust-output"></div>';
    }

    function buildChatPanel(id) {
      var acc = accessMap[id] || {};
      var mod = (C.modules && C.modules[id]) || {};
      var locked = acc.status === 'locked';
      var previewNote = (acc.tier === 'preview' && acc.status !== 'active')
        ? '<div class="pdx-preview-note">'+IC.lock+' Preview mode — '+x(acc.label||'3 free messages')+'. Full access: <strong>'+fmt(acc.price,acc.currency)+'</strong></div>'
        : '';
      return hdr('user','AI Personas','Chat with a custom AI persona') +
        statusBadge(acc) +
        previewNote +
        '<div class="pdx-field" style="margin:14px 0 10px">' +
          '<label class="pdx-field-label">Persona</label>' +
          '<select class="pdx-select" id="pdx-persona-select">' +
            '<option value="assistant">Professional Assistant</option>' +
            '<option value="analyst">Business Analyst</option>' +
            '<option value="developer">Senior Developer</option>' +
            '<option value="strategist">Strategic Consultant</option>' +
          '</select>' +
        '</div>' +
        '<div class="pdx-chat" id="pdx-chat-messages"></div>' +
        '<div class="pdx-chat-row">' +
          '<textarea class="pdx-chat-input" id="pdx-chat-input" rows="2" placeholder="Ask anything\u2026"></textarea>' +
          '<button class="pdx-chat-send" id="pdx-chat-send" type="button">' + IC.send + '</button>' +
        '</div>' +
        (locked ? buildPaywall(id, acc) : '');
    }

    function buildOsintPanel() {
      var id  = 'osint';
      var acc = accessMap[id] || {};
      return hdr('search','OSINT / JailBreak Agents','Intelligence scan \u00b7 Domain \u00b7 IP \u00b7 Breaches') +
        statusBadge(acc) +
        '<div class="pdx-trust-row" style="margin-top:14px">' +
          '<input class="pdx-trust-input" id="pdx-osint-input" type="text" placeholder="domain.com or IP address" autocomplete="off"/>' +
          '<button class="pdx-trust-scan" id="pdx-osint-scan" type="button">Scan</button>' +
        '</div>' +
        '<div id="pdx-osint-output"></div>';
    }

    function buildBriefPanel() {
      return hdr('plus','Create','Submit a project brief') +
        '<p class="pdx-body">Tell us what you need. We\'ll scope it and respond within 24 hours.</p>' +
        '<div class="pdx-form-grid">' +
          field('text','pdx-brief-name','Your Name','Name') +
          field('email','pdx-brief-email','Email Address','Email') +
          '<div class="pdx-field">' +
            '<label class="pdx-field-label">Project Type</label>' +
            '<select class="pdx-select" id="pdx-brief-type">' +
              '<option value="">Select type\u2026</option>' +
              '<option value="web-app">Web Application</option>' +
              '<option value="ai-system">AI System</option>' +
              '<option value="automation">Automation</option>' +
              '<option value="api">API / Integration</option>' +
              '<option value="other">Other</option>' +
            '</select>' +
          '</div>' +
          '<div class="pdx-field">' +
            '<label class="pdx-field-label">Budget Range</label>' +
            '<select class="pdx-select" id="pdx-brief-budget">' +
              '<option value="">Select range\u2026</option>' +
              '<option value="<1k">Under $1,000</option>' +
              '<option value="1k-5k">$1,000 \u2013 $5,000</option>' +
              '<option value="5k-20k">$5,000 \u2013 $20,000</option>' +
              '<option value="20k+">$20,000+</option>' +
            '</select>' +
          '</div>' +
        '</div>' +
        '<div class="pdx-field" style="margin-top:10px">' +
          '<label class="pdx-field-label">Project Details</label>' +
          '<textarea class="pdx-trust-input" id="pdx-brief-details" rows="4" placeholder="Describe your project, goals, and any technical requirements\u2026" style="height:auto;resize:vertical"></textarea>' +
        '</div>' +
        '<div id="pdx-brief-output" style="margin-top:8px"></div>' +
        '<button class="pdx-cta-primary" id="pdx-brief-submit" style="margin-top:12px;width:100%;justify-content:center">Send Brief</button>';
    }

    function buildAutomationPanel() {
      var id  = 'automation';
      var acc = accessMap[id] || {};
      if (acc.status === 'locked') {
        return hdr('grid','Browser Automation','Submit a URL + task, receive structured results') +
          buildPaywall(id, acc);
      }
      return hdr('grid','Browser Automation','Submit a URL + task, receive structured results') +
        statusBadge(acc) +
        '<p class="pdx-body">Submit a target URL and describe the automation task. Results are delivered as structured JSON or a downloadable report.</p>' +
        '<div class="pdx-form-grid">' +
          field('url','pdx-auto-url','Target URL','https://example.com') +
        '</div>' +
        '<div class="pdx-field" style="margin-top:10px">' +
          '<label class="pdx-field-label">Task Description</label>' +
          '<textarea class="pdx-trust-input" id="pdx-auto-task" rows="3" placeholder="e.g. Extract all product names and prices from the listing page\u2026" style="height:auto;resize:vertical"></textarea>' +
        '</div>' +
        '<div id="pdx-auto-output" style="margin-top:8px"></div>' +
        '<button class="pdx-cta-primary" id="pdx-auto-submit" style="margin-top:12px;width:100%;justify-content:center">Submit Task</button>';
    }

    function buildConnectorsPanel() {
      var id  = 'connectors';
      var acc = accessMap[id] || {};
      if (acc.status === 'locked') {
        return hdr('link','Connectors','API integrations and data bridges') + buildPaywall(id, acc);
      }
      return hdr('link','Connectors','Test and configure API connections') +
        statusBadge(acc) +
        '<p class="pdx-body">Enter an API endpoint to test connectivity, inspect headers, and validate responses.</p>' +
        '<div class="pdx-form-grid">' +
          field('url','pdx-conn-url','API Endpoint URL','https://api.example.com/endpoint') +
          '<div class="pdx-field">' +
            '<label class="pdx-field-label">Method</label>' +
            '<select class="pdx-select" id="pdx-conn-method"><option>GET</option><option>POST</option><option>PUT</option><option>DELETE</option></select>' +
          '</div>' +
        '</div>' +
        '<div class="pdx-field" style="margin-top:10px">' +
          '<label class="pdx-field-label">Headers (JSON)</label>' +
          '<textarea class="pdx-trust-input pdx-mono" id="pdx-conn-headers" rows="2" placeholder=\'{"Authorization":"Bearer token"}\' style="height:auto;font-size:11px"></textarea>' +
        '</div>' +
        '<div class="pdx-field" style="margin-top:8px">' +
          '<label class="pdx-field-label">Body (JSON, for POST/PUT)</label>' +
          '<textarea class="pdx-trust-input pdx-mono" id="pdx-conn-body" rows="2" placeholder=\'{"key":"value"}\' style="height:auto;font-size:11px"></textarea>' +
        '</div>' +
        '<div id="pdx-conn-output" style="margin-top:8px"></div>' +
        '<button class="pdx-cta-primary" id="pdx-conn-test" style="margin-top:12px;width:100%;justify-content:center">Test Connection</button>';
    }

    function buildBuilderPanel() {
      var id  = 'builder';
      var acc = accessMap[id] || {};
      var locked = acc.status === 'locked';
      var previewNote = (acc.tier === 'preview' && !locked)
        ? '<div class="pdx-preview-note">'+IC.lock+' Preview: single-step flows only. Full access: <strong>'+fmt(acc.price,acc.currency)+'</strong></div>'
        : '';
      return hdr('layers','AI Builder','Design and deploy AI workflows') +
        statusBadge(acc) + previewNote +
        (locked ? buildPaywall(id, acc) :
        '<p class="pdx-body">Define an AI workflow step. Describe the input, the AI task, and the expected output format.</p>' +
        '<div class="pdx-field" style="margin-top:10px">' +
          '<label class="pdx-field-label">Step Name</label>' +
          '<input class="pdx-trust-input" id="pdx-builder-name" type="text" placeholder="e.g. Summarise customer feedback"/>' +
        '</div>' +
        '<div class="pdx-field" style="margin-top:8px">' +
          '<label class="pdx-field-label">Input Data / Context</label>' +
          '<textarea class="pdx-trust-input" id="pdx-builder-input" rows="3" placeholder="Paste sample input data or describe the data source\u2026" style="height:auto;resize:vertical"></textarea>' +
        '</div>' +
        '<div class="pdx-field" style="margin-top:8px">' +
          '<label class="pdx-field-label">AI Instruction</label>' +
          '<textarea class="pdx-trust-input" id="pdx-builder-prompt" rows="2" placeholder="e.g. Summarise into 3 bullet points, extract sentiment\u2026" style="height:auto;resize:vertical"></textarea>' +
        '</div>' +
        '<div id="pdx-builder-output" style="margin-top:8px"></div>' +
        '<button class="pdx-cta-primary" id="pdx-builder-run" style="margin-top:12px;width:100%;justify-content:center">Run Step</button>');
    }

    function buildPipelinePanel() {
      var id  = 'pipeline';
      var acc = accessMap[id] || {};
      if (acc.status === 'locked') {
        return hdr('pipeline','Agent Pipeline','Multi-agent orchestration') + buildPaywall(id, acc);
      }
      return hdr('pipeline','Agent Pipeline','Define and run a multi-agent task chain') +
        statusBadge(acc) +
        '<p class="pdx-body">Describe a complex goal. The pipeline will decompose it into agent tasks and return a structured result.</p>' +
        '<div class="pdx-field" style="margin-top:10px">' +
          '<label class="pdx-field-label">Goal</label>' +
          '<textarea class="pdx-trust-input" id="pdx-pipeline-goal" rows="3" placeholder="e.g. Research competitors in the SaaS analytics space, summarise pricing models, and identify gaps\u2026" style="height:auto;resize:vertical"></textarea>' +
        '</div>' +
        '<div class="pdx-field" style="margin-top:8px">' +
          '<label class="pdx-field-label">Output Format</label>' +
          '<select class="pdx-select" id="pdx-pipeline-format">' +
            '<option value="bullets">Bullet points</option>' +
            '<option value="table">Structured table</option>' +
            '<option value="report">Full report</option>' +
            '<option value="json">JSON</option>' +
          '</select>' +
        '</div>' +
        '<div id="pdx-pipeline-output" style="margin-top:8px"></div>' +
        '<button class="pdx-cta-primary" id="pdx-pipeline-run" style="margin-top:12px;width:100%;justify-content:center">Run Pipeline</button>';
    }

    function field(type, id, label, placeholder) {
      return '<div class="pdx-field"><label class="pdx-field-label">'+x(label)+'</label>' +
             '<input class="pdx-trust-input" id="'+id+'" type="'+type+'" placeholder="'+x(placeholder)+'"/></div>';
    }
    /* ── Trust tool ── */
    function runTrustScan() {
      var inp = document.getElementById('pdx-trust-input');
      var out = document.getElementById('pdx-trust-output');
      var btn = document.getElementById('pdx-trust-scan');
      if (!inp||!out||!btn) return;
      var raw = inp.value.trim();
      if (!raw) { out.innerHTML = errBox('Enter a domain or URL.'); return; }
      if (!/^https?:\/\//i.test(raw)) raw = 'https://' + raw;
      var host;
      try { host = new URL(raw).hostname; } catch(e) { out.innerHTML = errBox('Invalid URL.'); return; }
      btn.disabled = true; btn.textContent = 'Scanning\u2026';
      out.innerHTML = spinner('Checking ' + host + '\u2026');
      fetch(C.restUrl + '/trust?domain=' + encodeURIComponent(host), { headers: {'X-WP-Nonce': C.nonce} })
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){ out.innerHTML = d ? buildTrustResult(host, raw, d.rdap, d.ssl) : errBox('Scan failed.'); })
        .catch(function(){ out.innerHTML = errBox('Scan failed. Check your connection.'); })
        .finally(function(){ btn.disabled=false; btn.textContent='Scan'; });
    }

    function buildTrustResult(domain, href, rdap, ssl) {
      var score=100, good=[], warn=[], bad=[];
      var isHttps = /^https:/i.test(href);
      isHttps ? good.push('HTTPS') : (bad.push('No HTTPS'), score-=20);
      var regDate=null, registrar=null, age=null;
      if (rdap) {
        outer: for (var i=0;i<(rdap.entities||[]).length;i++) {
          var vc=(rdap.entities[i].vcardArray||[])[1]||[];
          for (var j=0;j<vc.length;j++) { if(vc[j][0]==='fn'){registrar=vc[j][3];break outer;} }
        }
        for (var k=0;k<(rdap.events||[]).length;k++) {
          if (rdap.events[k].eventAction==='registration') {
            regDate=new Date(rdap.events[k].eventDate);
            age=Math.floor((Date.now()-regDate.getTime())/86400000); break;
          }
        }
        if (age!==null) {
          if (age<30){bad.push('Domain < 30 days');score-=30;}
          else if (age<180){warn.push('Domain < 6 months');score-=12;}
          else good.push('Domain '+(age<365?age+'d':Math.floor(age/365)+'y')+' old');
        }
      }
      var grade=null;
      if (ssl&&ssl.endpoints&&ssl.endpoints[0]) {
        grade=ssl.endpoints[0].grade||null;
        if (grade) { var g0=grade[0]; if(g0==='A')good.push('SSL '+grade); else if(g0==='B'){warn.push('SSL '+grade);score-=8;} else{bad.push('SSL '+grade);score-=20;} }
      }
      score=Math.max(0,Math.min(100,score));
      var cls=score>=70?'safe':score>=40?'warn':'danger';
      var label=score>=70?'Low Risk':score>=40?'Medium Risk':'High Risk';
      var tags=good.map(function(t){return '<span class="pdx-tag pdx-tag--green">'+x(t)+'</span>';}).join('')+
               warn.map(function(t){return '<span class="pdx-tag pdx-tag--yellow">'+x(t)+'</span>';}).join('')+
               bad.map(function(t){return '<span class="pdx-tag pdx-tag--red">'+x(t)+'</span>';}).join('');
      var rows=[
        ['Domain',domain,''],['Protocol',isHttps?'HTTPS':'HTTP',isHttps?'ok':'bad'],
        ['SSL Grade',grade||(isHttps?'Present':'None'),grade?(grade[0]==='A'?'ok':'mid'):(isHttps?'':'bad')],
        ['Domain Age',age!==null?(age<365?age+' days':Math.floor(age/365)+' years'):'Unavailable',age!==null&&age<30?'bad':''],
        ['Registrar',registrar?registrar.substring(0,32):'Unavailable',''],
        ['Registered',regDate?regDate.toISOString().split('T')[0]:'Unavailable','']
      ];
      var rowsHTML=rows.map(function(r){return '<div class="pdx-result__row"><span class="pdx-result__row-key">'+x(r[0])+'</span><span class="pdx-result__row-val'+(r[2]?' pdx-result__row-val--'+r[2]:'')+'">' +x(r[1])+'</span></div>';}).join('');
      return '<div class="pdx-result">'+
        '<div class="pdx-result__score"><span class="pdx-result__num pdx-result__num--'+cls+'">'+score+'</span><span class="pdx-result__label">'+label+'</span></div>'+
        '<div class="pdx-result__bar"><div class="pdx-result__fill pdx-result__fill--'+cls+'" style="width:'+score+'%"></div></div>'+
        '<div class="pdx-result__tags">'+tags+'</div>'+
        '<div class="pdx-result__rows">'+rowsHTML+'</div>'+
        '<p class="pdx-result__source">Sources: RDAP \u00b7 SSL Labs</p></div>';
    }
    /* ── AI chat tool ── */
    function sendChat() {
      var inp  = document.getElementById('pdx-chat-input');
      var msgs = document.getElementById('pdx-chat-messages');
      var btn  = document.getElementById('pdx-chat-send');
      var sel  = document.getElementById('pdx-persona-select');
      if (!inp||!msgs||!btn) return;
      var msg = inp.value.trim();
      if (!msg) return;
      var id = current;
      if (!chatHistory[id]) chatHistory[id] = [];
      chatHistory[id].push({role:'user', content:msg});
      msgs.innerHTML += '<div class="pdx-chat-msg pdx-chat-msg--user">'+x(msg)+'</div>';
      inp.value = '';
      msgs.scrollTop = msgs.scrollHeight;
      var thinking = document.createElement('div');
      thinking.className = 'pdx-chat-msg pdx-chat-msg--ai pdx-chat-msg--thinking';
      thinking.innerHTML = '<span></span><span></span><span></span>';
      msgs.appendChild(thinking);
      msgs.scrollTop = msgs.scrollHeight;
      btn.disabled = true;
      post(C.restUrl+'/ai/chat', {
        module_id: id,
        message:   msg,
        persona:   sel ? sel.value : 'assistant'
      })
      .then(function(r){ return r.json(); })
      .then(function(d){
        thinking.remove();
        if (d.error === 'payment_required') {
          var pw = document.createElement('div');
          pw.innerHTML = buildPaywall(id, {
            status:'locked', tier:'preview',
            price: d.price, currency: d.currency,
            label: 'Unlock full access'
          });
          msgs.appendChild(pw);
        } else if (d.error) {
          msgs.innerHTML += '<div class="pdx-chat-msg pdx-chat-msg--ai pdx-chat-msg--err">'+x(d.error)+'</div>';
        } else {
          chatHistory[id].push({role:'assistant', content:d.reply});
          msgs.innerHTML += '<div class="pdx-chat-msg pdx-chat-msg--ai">'+mdToHtml(d.reply)+'</div>';
        }
        msgs.scrollTop = msgs.scrollHeight;
      })
      .catch(function(){
        thinking.remove();
        msgs.innerHTML += '<div class="pdx-chat-msg pdx-chat-msg--ai pdx-chat-msg--err">Connection error. Try again.</div>';
      })
      .finally(function(){ btn.disabled=false; });
    }

    /* Minimal markdown: bold, code, line breaks */
    function mdToHtml(s) {
      return x(s)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code style="background:rgba(255,255,255,.08);padding:1px 5px;border-radius:3px;font-size:11px">$1</code>')
        .replace(/\n/g, '<br>');
    }
    /* ── OSINT tool ── */
    function runOsintScan() {
      var inp = document.getElementById('pdx-osint-input');
      var out = document.getElementById('pdx-osint-output');
      var btn = document.getElementById('pdx-osint-scan');
      if (!inp||!out||!btn) return;
      var target = inp.value.trim();
      if (!target) { out.innerHTML = errBox('Enter a domain or IP address.'); return; }
      btn.disabled=true; btn.textContent='Scanning\u2026';
      out.innerHTML = spinner('Running intelligence scan\u2026');
      post(C.restUrl+'/osint/scan', {target: target})
        .then(function(r){ return r.json(); })
        .then(function(d){ out.innerHTML = buildOsintResult(d); })
        .catch(function(){ out.innerHTML = errBox('Scan failed. Check your connection.'); })
        .finally(function(){ btn.disabled=false; btn.textContent='Scan'; });
    }

    function buildOsintResult(d) {
      if (d.error) return errBox(d.error);
      var html = '';
      var sections = d.sections || {};
      Object.keys(sections).forEach(function(key) {
        var sec = sections[key];
        var badge = sec.free
          ? '<span class="pdx-tag pdx-tag--mute" style="font-size:10px">Free</span>'
          : '<span class="pdx-tag pdx-tag--green" style="font-size:10px">Full</span>';
        html += '<div class="pdx-osint-section">' +
          '<div class="pdx-osint-section__hdr">' + x(sec.label) + badge + '</div>' +
          '<div class="pdx-result__rows">';
        var data = sec.data || {};
        Object.keys(data).forEach(function(k) {
          html += '<div class="pdx-result__row">' +
            '<span class="pdx-result__row-key">' + x(k) + '</span>' +
            '<span class="pdx-result__row-val">' + x(String(data[k])) + '</span>' +
          '</div>';
        });
        if (sec.list && sec.list.length) {
          html += '<div class="pdx-result__row"><span class="pdx-result__row-key">Breach names</span>' +
            '<span class="pdx-result__row-val" style="font-size:10px">' + sec.list.map(x).join(', ') + '</span></div>';
        }
        html += '</div></div>';
      });
      if (d.paywall) {
        html += buildPaywall(d.paywall.module_id, {
          status:'locked', tier:'preview',
          price: d.paywall.price, currency: d.paywall.currency,
          label: 'Unlock full report'
        });
      }
      return html || errBox('No data returned.');
    }
    /* ── Project brief tool ── */
    function submitBrief() {
      var out = document.getElementById('pdx-brief-output');
      var btn = document.getElementById('pdx-brief-submit');
      if (!out||!btn) return;
      var name    = (document.getElementById('pdx-brief-name')    ||{}).value||'';
      var email   = (document.getElementById('pdx-brief-email')   ||{}).value||'';
      var type    = (document.getElementById('pdx-brief-type')    ||{}).value||'';
      var budget  = (document.getElementById('pdx-brief-budget')  ||{}).value||'';
      var details = (document.getElementById('pdx-brief-details') ||{}).value||'';
      if (!name||!email||!details) { out.innerHTML=errBox('Name, email, and details are required.'); return; }
      btn.disabled=true; btn.textContent='Sending\u2026';
      post(C.restUrl+'/brief/submit', {name:name, email:email, type:type, budget:budget, details:details})
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d.ok) {
            out.innerHTML = '<div class="pdx-success">'+IC.check+' '+x(d.message)+'</div>';
            btn.style.display='none';
          } else {
            out.innerHTML = errBox(d.error||'Submission failed.');
            btn.disabled=false; btn.textContent='Send Brief';
          }
        })
        .catch(function(){ out.innerHTML=errBox('Connection error.'); btn.disabled=false; btn.textContent='Send Brief'; });
    }

    /* ── Automation tool ── */
    function submitAutomation() {
      var out = document.getElementById('pdx-auto-output');
      var btn = document.getElementById('pdx-auto-submit');
      if (!out||!btn) return;
      var url  = (document.getElementById('pdx-auto-url') ||{}).value||'';
      var task = (document.getElementById('pdx-auto-task')||{}).value||'';
      if (!url||!task) { out.innerHTML=errBox('URL and task description are required.'); return; }
      btn.disabled=true; btn.textContent='Submitting\u2026';
      out.innerHTML = spinner('Sending to automation engine\u2026');
      /* Route through AI chat with automation persona */
      post(C.restUrl+'/ai/chat', {
        module_id: 'automation',
        message: 'Target URL: '+url+'\n\nTask: '+task+'\n\nProvide a structured plan and simulated result for this browser automation task.',
        persona: 'developer'
      })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.error==='payment_required') {
          out.innerHTML = buildPaywall('automation', {status:'locked',tier:'paid',price:d.price,currency:d.currency,label:'Unlock Automation'});
        } else if (d.error) {
          out.innerHTML = errBox(d.error);
        } else {
          out.innerHTML = '<div class="pdx-result-block"><div class="pdx-result-block__label">Automation Plan</div>' +
            '<div class="pdx-result-block__body">'+mdToHtml(d.reply)+'</div></div>';
        }
      })
      .catch(function(){ out.innerHTML=errBox('Connection error.'); })
      .finally(function(){ btn.disabled=false; btn.textContent='Submit Task'; });
    }

    /* ── Connectors tool ── */
    function testConnection() {
      var out = document.getElementById('pdx-conn-output');
      var btn = document.getElementById('pdx-conn-test');
      if (!out||!btn) return;
      var url     = (document.getElementById('pdx-conn-url')    ||{}).value||'';
      var method  = (document.getElementById('pdx-conn-method') ||{}).value||'GET';
      var headers = (document.getElementById('pdx-conn-headers')||{}).value||'{}';
      var body    = (document.getElementById('pdx-conn-body')   ||{}).value||'';
      if (!url) { out.innerHTML=errBox('Enter an API endpoint URL.'); return; }
      var parsedHeaders = {};
      try { parsedHeaders = JSON.parse(headers); } catch(e) { out.innerHTML=errBox('Headers must be valid JSON.'); return; }
      btn.disabled=true; btn.textContent='Testing\u2026';
      out.innerHTML = spinner('Connecting\u2026');
      var opts = { method: method, headers: parsedHeaders };
      if ((method==='POST'||method==='PUT') && body) opts.body = body;
      var t0 = Date.now();
      fetch(url, opts)
        .then(function(r){
          var ms = Date.now()-t0;
          return r.text().then(function(txt){
            var preview = txt.length>500 ? txt.substring(0,500)+'…' : txt;
            var statusCls = r.ok ? 'ok' : 'bad';
            out.innerHTML = '<div class="pdx-result__rows">' +
              row('Status',   r.status+' '+r.statusText, statusCls) +
              row('Time',     ms+'ms', '') +
              row('Size',     txt.length+' bytes', '') +
              row('Content-Type', r.headers.get('content-type')||'unknown', '') +
            '</div>' +
            '<div class="pdx-result-block" style="margin-top:10px">' +
              '<div class="pdx-result-block__label">Response Preview</div>' +
              '<pre class="pdx-pre">'+x(preview)+'</pre>' +
            '</div>';
          });
        })
        .catch(function(e){ out.innerHTML=errBox('Request failed: '+e.message+'. (CORS may block direct browser requests — use server-side proxy for production.)'); })
        .finally(function(){ btn.disabled=false; btn.textContent='Test Connection'; });
    }
    function row(k,v,cls) {
      return '<div class="pdx-result__row"><span class="pdx-result__row-key">'+x(k)+'</span>' +
             '<span class="pdx-result__row-val'+(cls?' pdx-result__row-val--'+cls:'')+'">' +x(v)+'</span></div>';
    }

    /* ── AI Builder tool ── */
    function runBuilderStep() {
      var out = document.getElementById('pdx-builder-output');
      var btn = document.getElementById('pdx-builder-run');
      if (!out||!btn) return;
      var name   = (document.getElementById('pdx-builder-name')  ||{}).value||'';
      var input  = (document.getElementById('pdx-builder-input') ||{}).value||'';
      var prompt = (document.getElementById('pdx-builder-prompt')||{}).value||'';
      if (!prompt) { out.innerHTML=errBox('AI instruction is required.'); return; }
      btn.disabled=true; btn.textContent='Running\u2026';
      out.innerHTML = spinner('Processing AI step\u2026');
      var fullMsg = (name?'Step: '+name+'\n\n':'')+(input?'Input data:\n'+input+'\n\n':'')+'Instruction: '+prompt;
      post(C.restUrl+'/ai/chat', { module_id:'builder', message:fullMsg, persona:'analyst' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d.error==='payment_required') {
            out.innerHTML = buildPaywall('builder',{status:'locked',tier:'paid',price:d.price,currency:d.currency,label:'Unlock AI Builder'});
          } else if (d.error) {
            out.innerHTML = errBox(d.error);
          } else {
            out.innerHTML = '<div class="pdx-result-block"><div class="pdx-result-block__label">Output'+(name?' — '+x(name):'')+'</div>' +
              '<div class="pdx-result-block__body">'+mdToHtml(d.reply)+'</div></div>';
          }
        })
        .catch(function(){ out.innerHTML=errBox('Connection error.'); })
        .finally(function(){ btn.disabled=false; btn.textContent='Run Step'; });
    }

    /* ── Agent Pipeline tool ── */
    function runPipeline() {
      var out = document.getElementById('pdx-pipeline-output');
      var btn = document.getElementById('pdx-pipeline-run');
      if (!out||!btn) return;
      var goal   = (document.getElementById('pdx-pipeline-goal')  ||{}).value||'';
      var format = (document.getElementById('pdx-pipeline-format')||{}).value||'bullets';
      if (!goal) { out.innerHTML=errBox('Describe the goal for the pipeline.'); return; }
      btn.disabled=true; btn.textContent='Running pipeline\u2026';
      out.innerHTML = spinner('Orchestrating agents\u2026');
      var formatInstr = {bullets:'Respond in clear bullet points.',table:'Respond as a structured markdown table.',report:'Respond as a detailed professional report.',json:'Respond as valid JSON only.'};
      var msg = 'You are a multi-agent orchestration system. Decompose the following goal into sub-tasks, execute each, and synthesise the results.\n\nGoal: '+goal+'\n\n'+( formatInstr[format]||'');
      post(C.restUrl+'/ai/chat', { module_id:'pipeline', message:msg, persona:'strategist' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d.error==='payment_required') {
            out.innerHTML = buildPaywall('pipeline',{status:'locked',tier:'paid',price:d.price,currency:d.currency,label:'Unlock Agent Pipeline'});
          } else if (d.error) {
            out.innerHTML = errBox(d.error);
          } else {
            out.innerHTML = '<div class="pdx-result-block"><div class="pdx-result-block__label">Pipeline Result</div>' +
              '<div class="pdx-result-block__body">'+mdToHtml(d.reply)+'</div></div>';
          }
        })
        .catch(function(){ out.innerHTML=errBox('Connection error.'); })
        .finally(function(){ btn.disabled=false; btn.textContent='Run Pipeline'; });
    }
    /* ── Paywall gate ── */
    function buildPaywall(moduleId, acc) {
      var price    = acc && acc.price    ? fmt(acc.price, acc.currency) : '';
      var label    = acc && acc.label    ? acc.label : 'Unlock Full Access';
      var tier     = acc && acc.tier     ? acc.tier  : 'paid';
      var previewMsg = tier === 'preview'
        ? '<p class="pdx-body" style="margin-bottom:12px">You\'ve used your free preview. Unlock full access to continue.</p>'
        : '<p class="pdx-body" style="margin-bottom:12px">This is a premium tool. Purchase one-time access to unlock all features.</p>';
      return '<div class="pdx-paywall">' +
        '<div class="pdx-paywall__icon">' + IC.lock + '</div>' +
        '<div class="pdx-paywall__title">' + x(label) + '</div>' +
        (price ? '<div class="pdx-paywall__price">' + x(price) + ' <span>one-time</span></div>' : '') +
        previewMsg +
        '<button class="pdx-paywall__btn" data-pay-module="' + x(moduleId) + '" type="button">' +
          IC.paypal + ' Pay with PayPal' +
        '</button>' +
        '<div class="pdx-paywall__note">Secure payment via PayPal. Instant access after payment.</div>' +
        '<div id="pdx-pay-status-' + x(moduleId) + '"></div>' +
      '</div>';
    }
    /* ── PayPal flow ── */
    function startPayment(moduleId) {
      var statusEl = document.getElementById('pdx-pay-status-' + moduleId);
      var btn = document.querySelector('[data-pay-module="' + moduleId + '"]');
      if (!statusEl || !btn) return;
      btn.disabled = true;
      btn.textContent = 'Creating order\u2026';
      post(C.restUrl + '/pay/create', { module_id: moduleId })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.error) {
            statusEl.innerHTML = errBox(d.error);
            btn.disabled = false; btn.innerHTML = IC.paypal + ' Pay with PayPal';
            return;
          }
          /* Open PayPal in a popup window */
          var w = 500, h = 650;
          var left = Math.round((screen.width  - w) / 2);
          var top  = Math.round((screen.height - h) / 2);
          var popup = window.open(
            d.approve_url,
            'pdx_paypal',
            'width='+w+',height='+h+',left='+left+',top='+top+',toolbar=0,menubar=0,location=0'
          );
          statusEl.innerHTML = '<div class="pdx-paywall__note" style="color:#d29922">'+IC.paypal+' PayPal window opened. Complete payment there.</div>';

          /* Poll for popup close, then capture */
          var poll = setInterval(function() {
            if (!popup || popup.closed) {
              clearInterval(poll);
              capturePayment(moduleId, d.order_id, statusEl, btn);
            }
          }, 800);
        })
        .catch(function() {
          statusEl.innerHTML = errBox('Failed to create payment order.');
          btn.disabled = false; btn.innerHTML = IC.paypal + ' Pay with PayPal';
        });
    }

    function capturePayment(moduleId, orderId, statusEl, btn) {
      statusEl.innerHTML = spinner('Verifying payment\u2026');
      post(C.restUrl + '/pay/capture', { module_id: moduleId, order_id: orderId })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.ok) {
            accessMap[moduleId] = { status: 'active', tier: 'paid', label: 'Unlocked' };
            statusEl.innerHTML = '<div class="pdx-success">' + IC.check +
              ' Payment confirmed! Reloading tool\u2026</div>';
            setTimeout(function() { renderPanel(moduleId); }, 1200);
          } else {
            statusEl.innerHTML = errBox(d.error || 'Payment not confirmed. If you completed payment, please refresh.');
            if (btn) { btn.disabled=false; btn.innerHTML = IC.paypal + ' Pay with PayPal'; }
          }
        })
        .catch(function() {
          statusEl.innerHTML = errBox('Could not verify payment. Please refresh the page.');
        });
    }

    /* Handle return from PayPal redirect (non-popup fallback) */
    (function checkReturnCapture() {
      var params = new URLSearchParams(window.location.search);
      if (params.get('pdx_capture') !== '1') return;
      var moduleId = params.get('pdx_module') || '';
      var token    = params.get('token') || '';
      if (!moduleId || !token) return;
      post(C.restUrl + '/pay/capture', { module_id: moduleId, order_id: token })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.ok) {
            accessMap[moduleId] = { status: 'active', tier: 'paid', label: 'Unlocked' };
            /* Clean URL */
            var clean = window.location.pathname;
            window.history.replaceState({}, '', clean);
          }
        })
        .catch(function(){});
    })();
    /* ── Event delegation ── */
    document.addEventListener('click', function(e) {
      var el = e.target;

      /* Dock button */
      while (el && el !== document.body) {
        if (el.getAttribute && el.getAttribute('data-module') && dock.contains(el)) {
          openPanel(el.getAttribute('data-module')); return;
        }
        el = el.parentNode;
      }

      /* Close button */
      el = e.target;
      while (el && el !== document.body) {
        if (el.id === 'pdx-close') { closePanel(); return; }
        el = el.parentNode;
      }

      /* Backdrop */
      if (e.target === backdrop) { closePanel(); return; }

      /* Tool action buttons */
      var t = e.target.id || (e.target.closest && e.target.closest('[id]') && e.target.closest('[id]').id) || '';
      switch(t) {
        case 'pdx-trust-scan':   runTrustScan();    return;
        case 'pdx-osint-scan':   runOsintScan();    return;
        case 'pdx-chat-send':    sendChat();        return;
        case 'pdx-brief-submit': submitBrief();     return;
        case 'pdx-auto-submit':  submitAutomation();return;
        case 'pdx-conn-test':    testConnection();  return;
        case 'pdx-builder-run':  runBuilderStep();  return;
        case 'pdx-pipeline-run': runPipeline();     return;
      }

      /* PayPal pay button */
      var payBtn = e.target.closest ? e.target.closest('[data-pay-module]') : null;
      if (!payBtn && e.target.getAttribute) payBtn = e.target.getAttribute('data-pay-module') ? e.target : null;
      if (payBtn) { startPayment(payBtn.getAttribute('data-pay-module')); return; }
    });

    /* Keyboard */
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && current) { closePanel(); return; }
      if (e.key === 'Enter') {
        if (e.target.id === 'pdx-trust-input') { runTrustScan(); return; }
        if (e.target.id === 'pdx-osint-input') { runOsintScan(); return; }
      }
      if ((e.key === 'Enter' && (e.ctrlKey || e.metaKey)) && e.target.id === 'pdx-chat-input') {
        sendChat(); return;
      }
    });

    panel.addEventListener('touchmove', function(e) { e.stopPropagation(); }, { passive: true });

  } /* end init */
}());
