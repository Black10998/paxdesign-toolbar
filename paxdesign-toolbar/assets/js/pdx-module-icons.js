/**
 * Per-module SVG identity — keys are module IDs (trust, osint, …).
 * Legacy generic names (shield, search) map only when no module icon exists.
 */
(function (global) {
  'use strict';

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
    connectors: wrap(
      '<circle cx="6" cy="12" r="2"/><circle cx="18" cy="12" r="2"/><path d="M8 12h8"/><path d="M6 10V6M18 10V6M6 14v4M18 14v4"/>',
      'pdx-mod-icon--connectors'
    ),
    create: wrap(
      '<path d="M12 5v14M5 12h14"/><rect x="4" y="4" width="16" height="16" rx="2" opacity="0.35"/>',
      'pdx-mod-icon--create'
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
    workspace: wrap(
      '<path d="M4 7h5l2 2h9v10H4V7z"/><path d="M9 13h6M9 17h4"/>',
      'pdx-mod-icon--workspace'
    ),
    check: wrap('<path d="M5 13l4 4L19 7"/>', 'pdx-mod-icon--check'),
  };

  /** Only for legacy svgIcon('shield') calls — never collapses distinct modules. */
  var LEGACY_ALIAS = {
    shield: 'trust',
    search: 'osint',
    alert: 'threat',
    user: 'personas',
    layers: 'builder',
    grid: 'automation',
    link: 'connectors',
    plus: 'create',
    folder: 'workspace',
    pipeline: 'pipeline',
  };

  function moduleIcon(name) {
    if (!name) return ICONS.trust;
    if (ICONS[name]) return ICONS[name];
    var legacy = LEGACY_ALIAS[name];
    if (legacy && ICONS[legacy]) return ICONS[legacy];
    return ICONS.trust;
  }

  function buildIntelActivity(moduleId, title) {
    var mod = ICONS[moduleId] ? moduleId : (LEGACY_ALIAS[moduleId] || moduleId || 'trust');
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
          '<div class="pdx-intel-activity__copy">' +
            '<span class="pdx-intel-activity__title">' + label + '</span>' +
            '<span class="pdx-intel-activity__status">Intelligence engine active</span>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  }

  global.PDX_MODULE_ICONS = ICONS;
  global.pdxModuleIcon = moduleIcon;
  global.pdxBuildIntelActivity = buildIntelActivity;
})(typeof window !== 'undefined' ? window : this);
