/**
 * PaxDesign Admin Panel — v5.0.1
 */
(function () {
  'use strict';

  /* ── Color picker sync ──────────────────────────────────── */
  var colorInput = document.getElementById('accent_color');
  var hexInput   = document.getElementById('accent_color_hex');

  if (colorInput && hexInput) {
    colorInput.addEventListener('input', function () {
      hexInput.value = colorInput.value;
    });
    hexInput.addEventListener('input', function () {
      var val = hexInput.value.trim();
      if (/^#[0-9a-f]{6}$/i.test(val)) {
        colorInput.value = val;
      }
    });
  }

  /* ── Password reveal toggles ────────────────────────────── */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.pdx-input-reveal');
    if (!btn) return;
    var targetId = btn.getAttribute('data-target');
    var field    = document.getElementById(targetId);
    if (!field) return;
    field.type = field.type === 'password' ? 'text' : 'password';
  });

  /* ── Radio group visual state ───────────────────────────── */
  document.addEventListener('change', function (e) {
    var radio = e.target;
    if (radio.type !== 'radio') return;
    var group = radio.closest('.pdx-radio-group');
    if (!group) return;
    var labels = group.querySelectorAll('.pdx-radio');
    for (var i = 0; i < labels.length; i++) {
      labels[i].classList.remove('is-selected');
    }
    var parent = radio.closest('.pdx-radio');
    if (parent) parent.classList.add('is-selected');
  });

  /* ── Checkbox card visual state (roles) ─────────────────── */
  document.addEventListener('change', function (e) {
    var cb = e.target;
    if (cb.type !== 'checkbox') return;

    /* Role cards */
    var roleCard = cb.closest('.pdx-role-card');
    if (roleCard) {
      roleCard.classList.toggle('is-selected', cb.checked);
    }

    /* Module cards */
    var moduleCard = cb.closest('.pdx-module-card');
    if (moduleCard) {
      moduleCard.classList.toggle('is-enabled', cb.checked);
    }
  });

  /* ── Confirm dangerous actions ──────────────────────────── */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.querySelector('[name="action"][value="pdx_clear_log"]')) {
      if (!confirm('Clear all event logs? This cannot be undone.')) {
        e.preventDefault();
      }
    }
  });

  /* ── Auto-dismiss notices ───────────────────────────────── */
  var notices = document.querySelectorAll('.notice.is-dismissible');
  notices.forEach(function (n) {
    setTimeout(function () {
      n.style.transition = 'opacity 0.4s';
      n.style.opacity    = '0';
      setTimeout(function () { n.remove(); }, 400);
    }, 3500);
  });

}());
