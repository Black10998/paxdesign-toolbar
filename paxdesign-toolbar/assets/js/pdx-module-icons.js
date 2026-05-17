/**
 * Per-module SVG identity + intelligence activity visuals (v8.2).
 */
(function (global) {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';

  function el(tag, attrs) {
    var node = document.createElementNS(NS, tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        node.setAttribute(k, attrs[k]);
      });
    }
    return node;
  }

  function wrap(inner, cls) {
    return (
      '<svg class="pdx-mod-icon ' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      inner +
      '</svg>'
    );
  }

  var ICONS = {
    trust: wrap(
      '<path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7l7-4z"/><path d="M9 12l2 2 4-4"/>',
      'pdx-mod-icon--trust'
    ),
    osint: wrap(
      '<circle cx="11" cy="11" r="6"/><path d="M20 20l-3-3"/><path d="M8 11h6M11 8v6"/>',
      'pdx-mod-icon--osint'
    ),
    threat: wrap(
      '<path d="M12 2l9 16H3L12 2z"/><path d="M12 9v4M12 17h.01"/>',
      'pdx-mod-icon--threat'
    ),
    personas: wrap(
      '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M14 20c0-2 2-3.5 4-3.5"/>',
      'pdx-mod-icon--personas'
    ),
    builder: wrap(
      '<rect x="3" y="4" width="6" height="5" rx="1"/><rect x="15" y="4" width="6" height="5" rx="1"/><rect x="9" y="15" width="6" height="5" rx="1"/><path d="M6 9v3h3M18 9v3h-3M12 12v3"/>',
      'pdx-mod-icon--builder'
    ),
    pipeline: wrap(
      '<circle cx="5" cy="12" r="2"/><circle cx="12" cy="6" r="2"/><circle cx="19" cy="12" r="2"/><circle cx="12" cy="18" r="2"/><path d="M7 12h3M14 12h3M12 8v2M12 14v2"/>',
      'pdx-mod-icon--pipeline'
    ),
    automation: wrap(
      '<rect x="4" y="5" width="16" height="12" rx="2"/><path d="M8 9h8M8 13h5"/><path d="M9 5V3M15 5V3"/>',
      'pdx-mod-icon--automation'
    ),
    timeline: wrap(
      '<path d="M4 6h16M4 12h10M4 18h14"/><circle cx="18" cy="12" r="2"/><circle cx="20" cy="18" r="2"/>',
      'pdx-mod-icon--timeline'
    ),
    investigation: wrap(
      '<circle cx="10" cy="10" r="6"/><path d="M21 21l-4-4"/><path d="M10 7v6M7 10h6"/>',
      'pdx-mod-icon--investigation'
    ),
    graph: wrap(
      '<circle cx="6" cy="6" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="8" cy="18" r="2"/><circle cx="18" cy="17" r="2"/><path d="M8 6h8M7 17l9-8M8 8l8 9"/>',
      'pdx-mod-icon--graph'
    ),
    memory: wrap(
      '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
      'pdx-mod-icon--memory'
    ),
    team: wrap(
      '<circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M3 19c0-2.5 2.5-4 5-4"/><path d="M21 19c0-2.5-2.5-4-5-4"/><path d="M12 19c0-2 1.5-3.5 3.5-3.5"/>',
      'pdx-mod-icon--team'
    ),
    check: wrap('<path d="M5 13l4 4L19 7"/>', 'pdx-mod-icon--check'),
    alert: wrap('<path d="M12 2l9 16H3L12 2z"/><path d="M12 9v4"/>', 'pdx-mod-icon--alert'),
    shield: wrap('<path d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7l7-4z"/>', 'pdx-mod-icon--shield'),
    search: wrap('<circle cx="11" cy="11" r="6"/><path d="M20 20l-3-3"/>', 'pdx-mod-icon--search'),
    user: wrap('<circle cx="12" cy="8" r="4"/><path d="M5 20c0-4 3-6 7-6s7 2 7 6"/>', 'pdx-mod-icon--user'),
    layers: wrap('<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 12l9 5 9-5"/><path d="M3 17l9 5 9-5"/>', 'pdx-mod-icon--layers'),
    link: wrap('<path d="M10 13a4 4 0 0 1 0-6l2-2a4 4 0 1 1 6 6l-1 1"/><path d="M14 11a4 4 0 0 1 0 6l-2 2a4 4 0 1 1-6-6l1-1"/>', 'pdx-mod-icon--link'),
    plus: wrap('<path d="M12 5v14M5 12h14"/>', 'pdx-mod-icon--plus'),
    folder: wrap('<path d="M4 7h5l2 2h9v10H4V7z"/>', 'pdx-mod-icon--folder'),
  };

  var MODULE_ALIAS = {
    trust: 'trust',
    osint: 'osint',
    threat: 'threat',
    personas: 'personas',
    builder: 'builder',
    pipeline: 'pipeline',
    automation: 'automation',
    browser: 'automation',
    timeline: 'timeline',
    investigation: 'investigation',
    graph: 'graph',
    memory: 'memory',
    team: 'team',
  };

  function moduleIcon(name) {
    var key = MODULE_ALIAS[name] || name;
    return ICONS[key] || ICONS.shield;
  }

  function buildIntelActivity(moduleId, title) {
    var mod = MODULE_ALIAS[moduleId] || moduleId || 'trust';
    var label = title || 'Intelligence scan in progress';
    return (
      '<div class="pdx-intel-activity pdx-intel-activity--' + mod + '" role="status" aria-live="polite">' +
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
          '<span class="pdx-intel-activity__title">' + label + '</span>' +
        '</div>' +
      '</div>'
    );
  }

  global.PDX_MODULE_ICONS = ICONS;
  global.pdxModuleIcon = moduleIcon;
  global.pdxBuildIntelActivity = buildIntelActivity;
})(typeof window !== 'undefined' ? window : this);
