/**
 * PAXDesign Verified Badge — shared frontend renderer (server-gated via verified flag).
 */
(function (global) {
  'use strict';

  var TIPS = {
    account: 'Verified Account',
    email: 'Email Verified',
  };

  function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function svgMarkup(size) {
    return '<svg class="pdx-vb" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">' +
      '<circle class="pdx-vb__bg" cx="12" cy="12" r="10"/>' +
      '<ellipse class="pdx-vb__shine" cx="12" cy="9" rx="5.5" ry="3.75"/>' +
      '<path class="pdx-vb__check" d="M7.75 12.25l2.65 2.65 5.85-6.1"/>' +
    '</svg>';
  }

  function tooltipForContext(context) {
    return TIPS[context] || TIPS.email;
  }

  function render(verified, opts) {
    opts = opts || {};
    if (!verified) return '';

    var size = Math.max(12, Math.min(24, opts.size || 16));
    var context = opts.context || 'email';
    var tip = opts.tooltip || tooltipForContext(context);
    var cls = 'pdx-verified-badge' + (opts.inline ? ' pdx-verified-badge--inline' : '');
    if (opts.className) cls += ' ' + opts.className;

    return '<span class="' + cls + '" role="img" tabindex="0" aria-label="' + escHtml(tip) + '" data-pdx-tip="' + escHtml(tip) + '">' +
      svgMarkup(size) +
    '</span>';
  }

  function nameWithBadge(name, verified, opts) {
    opts = opts || {};
    opts.context = opts.context || 'account';
    return '<span class="pdx-name-with-badge">' +
      escHtml(name || 'Account') +
      render(verified, opts) +
    '</span>';
  }

  global.PDXVerifiedBadge = {
    render: render,
    nameWithBadge: nameWithBadge,
    tooltipForContext: tooltipForContext,
  };
})(typeof window !== 'undefined' ? window : this);
