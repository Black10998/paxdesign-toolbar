/**
 * PaxDesign Admin — v6.0.1
 */
(function () {
  'use strict';

  var wrap = document.querySelector('.pdx-admin-wrap');
  var sidebar = document.getElementById('pdx-sidebar');
  var menuBtn = document.getElementById('pdx-sidebar-toggle');
  var backdrop = document.getElementById('pdx-sidebar-backdrop');
  var themeBtn = document.getElementById('pdx-theme-toggle');

  function setSidebarOpen(open) {
    if (!wrap || !menuBtn) return;
    wrap.classList.toggle('is-sidebar-open', open);
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('pdx-admin-sidebar-open', open);
    if (backdrop) {
      backdrop.hidden = !open;
    }
  }

  /* ── Sidebar (mobile) ─────────────────────────────────── */
  if (wrap && menuBtn) {
    menuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setSidebarOpen(!wrap.classList.contains('is-sidebar-open'));
    });

    if (backdrop) {
      backdrop.addEventListener('click', function () {
        setSidebarOpen(false);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && wrap.classList.contains('is-sidebar-open')) {
        setSidebarOpen(false);
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 960 && wrap.classList.contains('is-sidebar-open')) {
        setSidebarOpen(false);
      }
    });
  }

  /* ── Theme toggle ─────────────────────────────────────── */
  var stored = null;
  try { stored = localStorage.getItem('pdx_admin_theme'); } catch (e) {}

  function applyTheme(mode) {
    if (!wrap) return;
    wrap.setAttribute('data-pdx-theme', mode);
    if (themeBtn) themeBtn.textContent = mode === 'light' ? 'Dark' : 'Light';
    try { localStorage.setItem('pdx_admin_theme', mode); } catch (e) {}
  }

  if (stored === 'light' || stored === 'dark') {
    applyTheme(stored);
  }

  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var next = wrap.getAttribute('data-pdx-theme') === 'light' ? 'dark' : 'light';
      applyTheme(next);
    });
  }

  /* ── Color picker sync ──────────────────────────────────── */
  var colorInput = document.getElementById('accent_color');
  var hexInput = document.getElementById('accent_color_hex');

  if (colorInput && hexInput) {
    colorInput.addEventListener('input', function () {
      hexInput.value = colorInput.value;
    });
    hexInput.addEventListener('input', function () {
      var val = hexInput.value.trim();
      if (/^#[0-9a-f]{6}$/i.test(val)) colorInput.value = val;
    });
  }

  /* ── Password reveal ──────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.pdx-input-reveal');
    if (!btn) return;
    var field = document.getElementById(btn.getAttribute('data-target'));
    if (!field) return;
    field.type = field.type === 'password' ? 'text' : 'password';
  });

  /* ── Radio / checkbox cards ───────────────────────────── */
  document.addEventListener('change', function (e) {
    var radio = e.target;
    if (radio.type === 'radio') {
      var group = radio.closest('.pdx-radio-group');
      if (group) {
        group.querySelectorAll('.pdx-radio').forEach(function (l) {
          l.classList.remove('is-selected');
        });
        var parent = radio.closest('.pdx-radio');
        if (parent) parent.classList.add('is-selected');
      }
    }
    if (radio.type === 'checkbox') {
      var roleCard = radio.closest('.pdx-role-card');
      if (roleCard) roleCard.classList.toggle('is-selected', radio.checked);
      var moduleCard = radio.closest('.pdx-module-card');
      if (moduleCard) moduleCard.classList.toggle('is-enabled', radio.checked);
    }
  });

  document.addEventListener('submit', function (e) {
    if (e.target.querySelector('[name="action"][value="pdx_clear_log"]')) {
      if (!confirm('Clear all event logs? This cannot be undone.')) e.preventDefault();
    }
  });
})();
