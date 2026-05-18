/**
 * PDX icon system — module dock icons + unique action/UI icons (no aliasing).
 */
(function (global) {
  'use strict';

  var VB =
    ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';

  function wrapMod(inner, mod, extra) {
    return (
      '<svg class="pdx-mod-icon pdx-mod-icon--' +
      mod +
      (extra ? ' ' + extra : '') +
      '"' +
      VB +
      inner +
      '</svg>'
    );
  }

  function wrapAction(inner, extra) {
    return '<svg class="pdx-icon' + (extra ? ' ' + extra : '') + '"' + VB + inner + '</svg>';
  }

  /* Each module: unique silhouette — no shared magnifier / multi-user / node-grid language. */
  var MODULE_ICONS = {
    trust: wrapMod(
      '<path d="M12 2l8 4v6c0 5-4 9-8 10-5-3.5-8-9-8-10V6l8-4z"/><path d="M9 12l2.5 2.5L15 10"/>',
      'trust'
    ),
    osint: wrapMod(
      '<circle cx="11" cy="11" r="7"/><path d="M3 11h16"/><path d="M11 4a12 12 0 0 1 0 14"/><path d="M16 16l5 5"/>',
      'osint'
    ),
    threat: wrapMod(
      '<path d="M12 3l8 14H4L12 3z"/><path d="M12 9v3"/><circle cx="12" cy="16" r="1"/>',
      'threat'
    ),
    personas: wrapMod(
      '<circle cx="9" cy="9" r="3"/><path d="M4 20c0-3.5 2.5-6 5-6"/><path d="M15 7h5v6h-4l-2 2V7z"/>',
      'personas'
    ),
    builder: wrapMod(
      '<path d="M5 17l7-7"/><path d="M13 6l6 5-2 2-5-5-2 2-3-3z"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="18" r="2"/>',
      'builder'
    ),
    pipeline: wrapMod(
      '<circle cx="5" cy="12" r="2"/><path d="M7 12h3l2-4 2 4h3"/><circle cx="19" cy="12" r="2"/><path d="M12 5v14"/>',
      'pipeline'
    ),
    automation: wrapMod(
      '<rect x="4" y="6" width="16" height="11" rx="2"/><path d="M4 9h16"/><path d="M14 14l4 4"/><path d="M18 11v7"/>',
      'automation'
    ),
    connectors: wrapMod(
      '<path d="M7 9h3v4H7z"/><path d="M14 9h3v4h-3z"/><path d="M10 13h4v2h-4z"/><path d="M12 15v4"/>',
      'connectors'
    ),
    create: wrapMod(
      '<path d="M6 5h12v14H6z"/><path d="M9 11l-2 2 2 2"/><path d="M13 15h4"/>',
      'create'
    ),
    investigation: wrapMod(
      '<rect x="4" y="5" width="16" height="14" rx="1"/><circle cx="8" cy="10" r="1.5"/><circle cx="15" cy="9" r="1.5"/><circle cx="11" cy="15" r="1.5"/><path d="M8 10l7-1M15 9l-4 6"/>',
      'investigation'
    ),
    graph: wrapMod(
      '<circle cx="12" cy="12" r="2"/><path d="M12 4v4M12 16v4M4 12h4M16 12h4"/><path d="M6.8 6.8l2.8 2.8M14.4 14.4l2.8 2.8M17.2 6.8l-2.8 2.8M6.8 17.2l2.8-2.8"/>',
      'graph'
    ),
    memory: wrapMod(
      '<path d="M9 7c-2 0-3.5 1.5-3.5 3.5S7 14 9 14c0 2 2 3.5 3 3.5s3-1.5 3-3.5S14 14 15 14c2 0 3.5-1.5 3.5-3.5S17 7 15 7c0-2-2-3-3-3s-3 1-3 3"/>',
      'memory'
    ),
    team: wrapMod(
      '<circle cx="12" cy="7" r="2.5"/><circle cx="7" cy="13" r="2"/><circle cx="17" cy="13" r="2"/><path d="M5 19c0-2 3.2-3.5 7-3.5s7 1.5 7 3.5"/>',
      'team'
    ),
    workspace: wrapMod(
      '<path d="M9 6h9l2 2v10H9z"/><path d="M7 8h9l2 2v10H7z"/><path d="M5 10h9l2 2v10H5z"/>',
      'workspace'
    ),
    circle: wrapMod('<circle cx="12" cy="12" r="7"/><path d="M12 8v4l3 2"/>', 'circle'),
  };

  var D = 'pdx-icon--danger';

  var ACTION_ICONS = {
    alert: wrapAction('<path d="M12 2l9 16H3L12 2z"/><path d="M12 9v4"/><path d="M12 17h.01"/>', D),
    'alert-octagon': wrapAction(
      '<path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/><path d="M12 8v5"/><path d="M12 16h.01"/>',
      D
    ),
    'x-circle': wrapAction('<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>', D),
    check: wrapAction('<path d="M5 13l4 4L19 7"/>'),
    info: wrapAction('<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>'),
    'lock-paywall': wrapAction(
      '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="16" r="1"/>'
    ),
    billing: wrapAction(
      '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 15h2"/><path d="M12 15h5"/>'
    ),
    'cmd-search': wrapAction(
      '<circle cx="10" cy="10" r="5"/><path d="M21 21l-4-4"/><path d="M17 7h3M18.5 5.5v3"/>'
    ),
    'report-trust': wrapAction(
      '<path d="M7 4h10v16H7z"/><path d="M9 8h6"/><path d="M12 3l2 2h-4l2-2z"/><path d="M10 14l1.5 1.5L14 12"/>'
    ),
    'report-osint': wrapAction(
      '<path d="M7 4h10v16H7z"/><path d="M9 8h6"/><circle cx="15" cy="15" r="3"/><path d="M17 17l2 2"/>'
    ),
    'report-builder': wrapAction(
      '<path d="M7 4h10v16H7z"/><path d="M9 8h2v2H9z"/><path d="M13 8h2v6h-2z"/><path d="M9 14h2v2H9z"/>'
    ),
    'report-pipeline': wrapAction(
      '<path d="M7 4h10v16H7z"/><circle cx="10" cy="11" r="1.5"/><circle cx="14" cy="11" r="1.5"/><path d="M11.5 11h1"/><circle cx="12" cy="16" r="1.5"/>'
    ),
    'report-automation': wrapAction(
      '<path d="M7 4h10v16H7z"/><path d="M9 9h6"/><path d="M10 13h4"/><path d="M12 16v2"/><path d="M10 18h4"/>'
    ),
    'report-connectors': wrapAction(
      '<path d="M7 4h10v16H7z"/><circle cx="10" cy="12" r="1.5"/><circle cx="14" cy="12" r="1.5"/><path d="M11.5 12h1"/>'
    ),
    'report-dev': wrapAction('<path d="M7 4h10v16H7z"/><path d="M10 16l-2-3 2-3"/><path d="M14 10l2 3-2 3"/>'),
    'report-threat': wrapAction('<path d="M7 4h10v16H7z"/><path d="M12 9v4"/><path d="M12 16h.01"/>', D),
    'report-memory': wrapAction(
      '<path d="M7 4h10v16H7z"/><ellipse cx="12" cy="12" rx="3" ry="1.5"/><path d="M9 12v3c0 .8 1.3 1.5 3 1.5s3-.7 3-1.5v-3"/>'
    ),
    'report-graph': wrapAction(
      '<path d="M7 4h10v16H7z"/><circle cx="10" cy="10" r="1"/><circle cx="14" cy="9" r="1"/><circle cx="11" cy="15" r="1"/><path d="M11 10l3-1M10 11l1 4"/>'
    ),
    'report-correlation': wrapAction(
      '<path d="M7 4h10v16H7z"/><circle cx="10" cy="11" r="1.5"/><circle cx="14" cy="9" r="1.5"/><circle cx="13" cy="15" r="1.5"/><path d="M11.5 11l2-2M11.5 11.5l1.5 3.5"/>'
    ),
    'report-timeline': wrapAction(
      '<path d="M7 4h10v16H7z"/><path d="M10 8v8"/><circle cx="10" cy="8" r="1"/><circle cx="10" cy="14" r="1"/><circle cx="10" cy="17" r="1"/>'
    ),
    'report-cve': wrapAction(
      '<path d="M7 4h10v16H7z"/><path d="M12 8v4"/><path d="M12 15h.01"/>',
      D
    ),
    'report-attack-surface': wrapAction(
      '<path d="M7 4h10v16H7z"/><circle cx="12" cy="12" r="3"/><path d="M12 5v2M12 17v2M5 12h2M17 12h2"/>'
    ),
    'agent-trace': wrapAction(
      '<circle cx="10" cy="9" r="3"/><path d="M4 20c0-3 2.5-5 6-5"/><path d="M16 12l3 3"/><path d="M19 9v6"/>'
    ),
    'agent-bot': wrapAction(
      '<rect x="5" y="8" width="14" height="10" rx="2"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><circle cx="9" cy="13" r="1"/><circle cx="15" cy="13" r="1"/><path d="M10 17h4"/>'
    ),
    rdap: wrapAction('<circle cx="12" cy="12" r="9"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20"/><path d="M12 2a15 15 0 0 0 0 20"/>'),
    whois: wrapAction(
      '<path d="M6 4h12v16H6z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>'
    ),
    'ssl-cert': wrapAction(
      '<path d="M12 3l7 4v5c0 4-3 7-7 9-4-2-7-5-7-9V7l7-4z"/><path d="M9 12h6"/><path d="M12 9v6"/>'
    ),
    'dns-records': wrapAction(
      '<rect x="4" y="4" width="16" height="6" rx="1"/><rect x="4" y="14" width="16" height="6" rx="1"/><path d="M8 7h.01M8 17h.01"/><path d="M12 10v4"/>'
    ),
    'geo-pin': wrapAction(
      '<path d="M12 21s6-5.2 6-10a6 6 0 1 0-12 0c0 4.8 6 10 6 10z"/><circle cx="12" cy="11" r="2"/><path d="M12 14v3"/>'
    ),
    'virus-scan': wrapAction(
      '<circle cx="11" cy="11" r="6"/><path d="M20 20l-3-3"/><path d="M8 11h2"/><path d="M13 11h2"/>',
      D
    ),
    'shodan-radar': wrapAction(
      '<path d="M12 12l8-4"/><circle cx="12" cy="12" r="9"/><path d="M12 3v3M12 18v3"/><path d="M3 12h3M18 12h3"/>'
    ),
    'breach-check': wrapAction(
      '<path d="M12 3l7 4v5c0 4-3 7-7 9"/><path d="M9 12l2 2 4-4"/><path d="M5 5l14 14"/>',
      D
    ),
    'email-hunter': wrapAction(
      '<path d="M4 6h16v12H4z"/><path d="M4 6l8 6 8-6"/><circle cx="17" cy="17" r="3"/><path d="M16 17h2"/>'
    ),
    'abuse-ch': wrapAction('<path d="M12 2l9 16H3L12 2z"/><path d="M9 9l6 6M15 9l-6 6"/>', D),
    'threat-feed': wrapAction(
      '<path d="M4 11a8 8 0 0 1 16 0"/><path d="M12 11v8"/><circle cx="12" cy="19" r="1"/><path d="M8 15h8"/>'
    ),
    'scan-new': wrapAction(
      '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>'
    ),
    'investigation-new': wrapAction(
      '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 9h8"/><path d="M8 13h5"/><circle cx="17" cy="17" r="2"/>'
    ),
    'workspace-folder': wrapAction('<path d="M3 7h6l2 2h10v10H3z"/><path d="M3 7v12"/>'),
    'ioc-threat': wrapAction(
      '<path d="M8 3l-2 4 4 1-3 3 4-1 1 4 4-1-1 4 4 1-3-3 4 1 2 4"/><circle cx="12" cy="12" r="2"/>',
      D
    ),
    'audit-log': wrapAction(
      '<path d="M8 4h11v16H8z"/><path d="M5 7h3v14H5z"/><path d="M11 9h5"/><path d="M11 13h5"/><path d="M11 17h3"/>'
    ),
    'connector-rest': wrapAction('<path d="M8 9H5a2 2 0 0 0 0 4h3"/><path d="M16 15h3a2 2 0 0 0 0-4h-3"/><path d="M8 12h8"/>'),
    'connector-webhook': wrapAction('<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>'),
    'connector-openai': wrapAction(
      '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6v6H9z"/><path d="M12 5v3M12 16v3M5 12h3M16 12h3"/>'
    ),
    'connector-slack': wrapAction('<path d="M6 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/><path d="M10 10V6a2 2 0 1 0-4 0 2 2 0 0 0 4 0z"/><path d="M14 10h4a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/><path d="M14 14v4a2 2 0 1 0 4 0 2 2 0 0 0-4 0z"/>'),
    'connector-airtable': wrapAction(
      '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 10h16"/><path d="M10 10v9"/><path d="M14 10v9"/>'
    ),
    'connector-notion': wrapAction(
      '<path d="M6 4h12v16l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6"/><path d="M9 12h4"/>'
    ),
    'connector-github': wrapAction(
      '<path d="M9 18c-4 1-4-2-4-2s-1-3 2-4"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="8" r="2"/><path d="M8 18l4-6 4 4 4-2"/>'
    ),
    'connector-zapier': wrapAction(
      '<path d="M4 12h6"/><path d="M14 8h6"/><path d="M14 16h6"/><circle cx="10" cy="12" r="2"/><circle cx="20" cy="8" r="2"/><circle cx="20" cy="16" r="2"/>'
    ),
    zap: wrapAction('<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>'),
    cpu: wrapAction(
      '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6v6H9z"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/>'
    ),
    message: wrapAction('<path d="M21 14a2 2 0 0 1-2 2H8l-5 3V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'),
    file: wrapAction('<path d="M8 4h8l4 4v12H8z"/><path d="M16 4v4h4"/>'),
    code: wrapAction('<path d="M9 18l-6-6 6-6"/><path d="M15 6l6 6-6 6"/>'),
    export: wrapAction('<path d="M12 4v10"/><path d="M8 10l4 4 4-4"/><path d="M5 20h14"/>'),
    refresh: wrapAction('<path d="M20 12a8 8 0 1 1-2-5"/><path d="M20 5v5h-5"/>'),
    settings: wrapAction(
      '<circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'
    ),
    unlock: wrapAction('<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0"/>'),
    link: wrapAction(
      '<path d="M10 13a4 4 0 0 0 5.7.3l2-2a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 11a4 4 0 0 0-5.7-.3l-2 2a4 4 0 0 0 5.7 5.7l1-1"/>'
    ),
    folder: wrapAction('<path d="M3 7h6l2 2h10v10H3z"/>'),
    search: wrapAction('<circle cx="11" cy="11" r="6"/><path d="M20 20l-3-3"/>'),
    shield: wrapAction('<path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7l7-4z"/>'),
    user: wrapAction('<circle cx="12" cy="8" r="3"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/>'),
    layers: wrapAction('<path d="M12 3L3 8l9 5 9-5-9-5z"/><path d="M3 13l9 5 9-5"/><path d="M3 18l9 5 9-5"/>'),
    grid: wrapAction(
      '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><path d="M16.5 13v6M13.5 16h6"/>'
    ),
    plus: wrapAction('<path d="M12 6v12M6 12h12"/>'),
  };

  /** Map legacy/API slug → dedicated action icon (never module icons). */
  /** Never alias module IDs (trust, threat, pipeline, …) — only legacy action slugs. */
  var ACTION_ALIAS = {
    alert: 'alert',
    warning: 'alert',
    'alert-triangle': 'alert',
    'lock-premium': 'lock-paywall',
    'report-search': 'report-osint',
    ssl: 'ssl-cert',
    dns: 'dns-records',
    geo: 'geo-pin',
    vt: 'virus-scan',
    shodan: 'shodan-radar',
    hibp: 'breach-check',
    hunter: 'email-hunter',
    abuse: 'abuse-ch',
  };

  function resolveActionKey(name) {
    if (!name) return 'info';
    if (ACTION_ICONS[name]) return name;
    if (ACTION_ALIAS[name] && ACTION_ICONS[ACTION_ALIAS[name]]) return ACTION_ALIAS[name];
    if (MODULE_ICONS[name]) return null;
    return 'info';
  }

  function actionIcon(name) {
    var key = resolveActionKey(name);
    if (key) return ACTION_ICONS[key];
    return null;
  }

  function moduleIcon(name) {
    if (!name) return MODULE_ICONS.trust;
    if (MODULE_ICONS[name]) return MODULE_ICONS[name];
    return MODULE_ICONS.trust;
  }

  function pdxIcon(name) {
    var action = actionIcon(name);
    if (action) return action;
    return moduleIcon(name);
  }

  function buildIntelActivity(moduleId, title) {
    if (typeof global.pdxBuildAiAnalysisLoader === 'function') {
      var stages =
        typeof global.pdxDefaultAiStages === 'function'
          ? global.pdxDefaultAiStages()
          : undefined;
      return global.pdxBuildAiAnalysisLoader(moduleId, {
        title: title || 'Intelligence analysis in progress',
        stages: stages,
      });
    }
    var mod = MODULE_ICONS[moduleId] ? moduleId : moduleId || 'trust';
    if (!MODULE_ICONS[mod]) mod = 'trust';
    var label = title || 'Intelligence scan in progress';
    return (
      '<div class="pdx-intel-activity pdx-intel-activity--' +
      mod +
      '" role="status" aria-live="polite">' +
      '<div class="pdx-intel-activity__viz">' +
      '<span class="pdx-intel-node pdx-intel-node--a"></span>' +
      '<span class="pdx-intel-node pdx-intel-node--b"></span>' +
      '<span class="pdx-intel-node pdx-intel-node--c"></span>' +
      '<span class="pdx-intel-signal pdx-intel-signal--1"></span>' +
      '<span class="pdx-intel-signal pdx-intel-signal--2"></span>' +
      '<span class="pdx-intel-signal pdx-intel-signal--3"></span>' +
      '<span class="pdx-intel-core"></span>' +
      '</div>' +
      '<div class="pdx-intel-activity__meta">' +
      moduleIcon(mod) +
      '<div class="pdx-intel-activity__copy">' +
      '<span class="pdx-intel-activity__title">' +
      label +
      '</span>' +
      '<span class="pdx-intel-activity__status">Intelligence engine active</span>' +
      '</div>' +
      '</div>' +
      '</div>'
    );
  }

  global.PDX_MODULE_ICONS = MODULE_ICONS;
  global.PDX_ACTION_ICONS = ACTION_ICONS;
  global.pdxModuleIcon = moduleIcon;
  global.pdxActionIcon = actionIcon;
  global.pdxIcon = pdxIcon;
  global.pdxBuildIntelActivity = buildIntelActivity;
})(typeof window !== 'undefined' ? window : this);
