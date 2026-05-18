/**
 * PaxDesign — cinematic AI analysis loader (chip + data-flow traces).
 * Used by TrustCheck, OSINT, Investigation, Infrastructure Graph, Threat Intel.
 */
(function (global) {
  'use strict';

  var uid = 0;

  var MODULE_NAMES = {
    trust: 'TrustCheck',
    osint: 'OSINT Agents',
    threat: 'Threat Intel',
    investigation: 'Investigation',
    graph: 'Infrastructure Graph',
  };

  var MODULE_ACCENTS = {
    trust: '#c2ff00',
    osint: '#5ecbff',
    threat: '#ff6b6b',
    investigation: '#67e8f9',
    graph: '#a78bfa',
  };

  /** Canonical rotating labels — shown on every intel-module analysis loader. */
  var DEFAULT_AI_STAGES = [
    'Initializing analysis…',
    'Scanning components…',
    'Processing logic…',
    'Optimizing results…',
    'Finalizing response…',
  ];

  function getDefaultAiStages() {
    return DEFAULT_AI_STAGES.slice();
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function chipSvg(id, accent) {
    var g = 'pdxchip' + id;
    var c = accent || '#399fff';
    var c2 = accent || '#399fff';
    return (
      '<div class="pdx-ai-chip-loader" aria-hidden="true">' +
      '<svg viewBox="0 0 800 500" xmlns="http://www.w3.org/2000/svg" class="pdx-ai-chip-svg">' +
      '<defs>' +
      '<linearGradient id="' +
      g +
      '-chip" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="#2d2d2d"></stop><stop offset="100%" stop-color="#0f0f0f"></stop>' +
      '</linearGradient>' +
      '<linearGradient id="' +
      g +
      '-text" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="#eeeeee"></stop><stop offset="100%" stop-color="#888888"></stop>' +
      '</linearGradient>' +
      '<linearGradient id="' +
      g +
      '-pin" x1="1" y1="0" x2="0" y2="0">' +
      '<stop offset="0%" stop-color="#bbbbbb"></stop><stop offset="50%" stop-color="#888888"></stop><stop offset="100%" stop-color="#555555"></stop>' +
      '</linearGradient>' +
      '<filter id="' +
      g +
      '-glow"><feGaussianBlur stdDeviation="2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>' +
      '</defs>' +
      '<g class="pdx-ai-traces">' +
      '<path d="M100 100 H200 V210 H326" class="pdx-ai-trace-bg"></path>' +
      '<path d="M100 100 H200 V210 H326" class="pdx-ai-trace-flow pdx-ai-trace--a" style="stroke:' +
      c2 +
      ';color:' +
      c2 +
      '"></path>' +
      '<path d="M80 180 H180 V230 H326" class="pdx-ai-trace-bg"></path>' +
      '<path d="M80 180 H180 V230 H326" class="pdx-ai-trace-flow pdx-ai-trace--b" style="stroke:' +
      c +
      ';color:' +
      c +
      '"></path>' +
      '<path d="M60 260 H150 V250 H326" class="pdx-ai-trace-bg"></path>' +
      '<path d="M60 260 H150 V250 H326" class="pdx-ai-trace-flow pdx-ai-trace--a" style="stroke:' +
      c2 +
      ';color:' +
      c2 +
      '"></path>' +
      '<path d="M100 350 H200 V270 H326" class="pdx-ai-trace-bg"></path>' +
      '<path d="M100 350 H200 V270 H326" class="pdx-ai-trace-flow pdx-ai-trace--b" style="stroke:' +
      c +
      ';color:' +
      c +
      '"></path>' +
      '<path d="M700 90 H560 V210 H474" class="pdx-ai-trace-bg"></path>' +
      '<path d="M700 90 H560 V210 H474" class="pdx-ai-trace-flow pdx-ai-trace--b" style="stroke:' +
      c +
      ';color:' +
      c +
      '"></path>' +
      '<path d="M740 160 H580 V230 H474" class="pdx-ai-trace-bg"></path>' +
      '<path d="M740 160 H580 V230 H474" class="pdx-ai-trace-flow pdx-ai-trace--a" style="stroke:' +
      c2 +
      ';color:' +
      c2 +
      '"></path>' +
      '<path d="M720 250 H590 V250 H474" class="pdx-ai-trace-bg"></path>' +
      '<path d="M720 250 H590 V250 H474" class="pdx-ai-trace-flow pdx-ai-trace--b" style="stroke:' +
      c +
      ';color:' +
      c +
      '"></path>' +
      '<path d="M680 340 H570 V270 H474" class="pdx-ai-trace-bg"></path>' +
      '<path d="M680 340 H570 V270 H474" class="pdx-ai-trace-flow pdx-ai-trace--a" style="stroke:' +
      c2 +
      ';color:' +
      c2 +
      '"></path>' +
      '</g>' +
      '<rect x="330" y="190" width="140" height="100" rx="20" ry="20" fill="url(#' +
      g +
      '-chip)" stroke="#222" stroke-width="3" filter="url(#' +
      g +
      '-glow)"></rect>' +
      '<g><rect x="322" y="205" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect>' +
      '<rect x="322" y="225" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect>' +
      '<rect x="322" y="245" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect>' +
      '<rect x="322" y="265" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect></g>' +
      '<g><rect x="470" y="205" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect>' +
      '<rect x="470" y="225" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect>' +
      '<rect x="470" y="245" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect>' +
      '<rect x="470" y="265" width="8" height="10" fill="url(#' +
      g +
      '-pin)" rx="2"></rect></g>' +
      '<text x="400" y="240" font-family="ui-monospace,Consolas,monospace" font-size="20" fill="url(#' +
      g +
      '-text)" text-anchor="middle" alignment-baseline="middle" class="pdx-ai-chip-label">AI</text>' +
      '<circle cx="100" cy="100" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '<circle cx="80" cy="180" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '<circle cx="60" cy="260" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '<circle cx="100" cy="350" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '<circle cx="700" cy="90" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '<circle cx="740" cy="160" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '<circle cx="720" cy="250" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '<circle cx="680" cy="340" r="5" fill="currentColor" class="pdx-ai-node"></circle>' +
      '</svg></div>'
    );
  }

  /**
   * @param {string} moduleId
   * @param {{ title?: string, stage?: string, stages?: string[] }} opts
   */
  function buildAiAnalysisLoader(moduleId, opts) {
    opts = opts || {};
    var id = ++uid;
    var mod = MODULE_NAMES[moduleId] ? moduleId : 'trust';
    var accent = MODULE_ACCENTS[mod] || '#399fff';
    var title = opts.title || 'Intelligence analysis in progress';
    var stages =
      opts.stages && opts.stages.length ? opts.stages : getDefaultAiStages();
    var stage = opts.stage || stages[0];
    var modLabel = MODULE_NAMES[mod] || 'Analysis';

    return (
      '<div class="pdx-ai-analysis pdx-ai-analysis--' +
      mod +
      '" data-pdx-ai-id="' +
      id +
      '" data-pdx-ai-stages="' +
      esc(stages.join('|')) +
      '" role="status" aria-live="polite" aria-busy="true">' +
      '<div class="pdx-ai-analysis__glass">' +
      '<div class="pdx-ai-analysis__scanline" aria-hidden="true"></div>' +
      chipSvg(id, accent) +
      '<div class="pdx-ai-analysis__copy">' +
      (typeof global.pdxModuleIcon === 'function'
        ? '<span class="pdx-ai-analysis__icon">' + global.pdxModuleIcon(mod) + '</span>'
        : '') +
      '<span class="pdx-ai-analysis__module">' +
      esc(modLabel) +
      '</span>' +
      '<span class="pdx-ai-analysis__title">' +
      esc(title) +
      '</span>' +
      '<span class="pdx-ai-analysis__stage" data-pdx-ai-stage>' +
      esc(stage) +
      '</span>' +
      '<span class="pdx-ai-analysis__hint">Neural correlation engine · staged deep scan</span>' +
      '<div class="pdx-ai-analysis__progress" aria-hidden="true"><span class="pdx-ai-analysis__progress-bar"></span></div>' +
      '</div></div></div>'
    );
  }

  var rotators = {};

  function stopRotator(key) {
    if (rotators[key]) {
      clearInterval(rotators[key]);
      delete rotators[key];
    }
  }

  /**
   * Rotate stage label inside a pipeline or standalone loader.
   * @param {string} rootSelector - e.g. '#pdx-osint-pipeline' or '[data-pdx-ai-id="3"]'
   * @param {string[]} stages
   * @param {number} intervalMs
   */
  /**
   * Start rotator on a mounted loader element (or container holding one).
   * @param {Element|string} root
   * @param {number} [intervalMs]
   */
  function startAiAnalysisRotator(root, intervalMs) {
    var el =
      typeof root === 'string' ? document.querySelector(root) : root;
    if (!el) return;
    var loader = el.classList && el.classList.contains('pdx-ai-analysis')
      ? el
      : el.querySelector('.pdx-ai-analysis');
    if (!loader) return;
    var stages = getDefaultAiStages();
    var encoded = loader.getAttribute('data-pdx-ai-stages');
    if (encoded) {
      stages = encoded.split('|').filter(Boolean);
    }
    wireAiStageRotator(loader, stages, intervalMs || 1850);
  }

  function wireAiStageRotator(rootSelector, stages, intervalMs) {
    if (!stages || !stages.length) {
      stages = getDefaultAiStages();
    }
    stopRotator(rootSelector);
    var root =
      typeof rootSelector === 'string'
        ? document.querySelector(rootSelector)
        : rootSelector;
    if (!root) return;
    var el = root.querySelector('[data-pdx-ai-stage]');
    if (!el) return;
    var i = 0;
    el.textContent = stages[0];
    rotators[rootSelector] = setInterval(function () {
      if (!document.body.contains(root)) {
        stopRotator(rootSelector);
        return;
      }
      i = (i + 1) % stages.length;
      el.textContent = stages[i];
      el.classList.remove('pdx-ai-stage-flash');
      void el.offsetWidth;
      el.classList.add('pdx-ai-stage-flash');
    }, intervalMs || 2400);
  }

  function wireAiStageRotatorInPipeline(pipelineId, stages) {
    var container = document.getElementById(pipelineId);
    if (!container) return;
    var slot = container.querySelector('.pdx-dp-intel-slot .pdx-ai-analysis');
    if (slot) {
      wireAiStageRotator(slot, stages && stages.length ? stages : getDefaultAiStages(), 1850);
    }
  }

  global.pdxDefaultAiStages = getDefaultAiStages;
  global.pdxBuildAiAnalysisLoader = buildAiAnalysisLoader;
  global.pdxStartAiAnalysisRotator = startAiAnalysisRotator;
  global.pdxWireAiStageRotator = wireAiStageRotator;
  global.pdxWireAiStageRotatorInPipeline = wireAiStageRotatorInPipeline;
  global.pdxStopAiStageRotator = stopRotator;
})(typeof window !== 'undefined' ? window : this);
