/**
 * PaxDesign Auth — login/register UI, session handling, account dashboard.
 */
(function () {
  'use strict';

  if (typeof PDX_CONFIG === 'undefined') return;

  var C = PDX_CONFIG;
  var user = {
    logged_in: !!C.isLoggedIn,
    verified: !!C.emailVerified,
    display_name: C.userName || '',
    email: C.userEmail || '',
    id: C.userId || 0,
  };
  var returnModule = null;
  var currentView = 'login';
  var dashboardData = null;

  var SVG_GRADIENT = '<defs><linearGradient id="pdx-gradient-stroke" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="black"></stop><stop offset="100%" stop-color="white"></stop></linearGradient></defs>';
  var SVG_EMAIL = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' + SVG_GRADIENT + '<g stroke="url(#pdx-gradient-stroke)" fill="none" stroke-width="1"><path d="M21.6365 5H3L12.2275 12.3636L21.6365 5Z"></path><path d="M16.5 11.5L22.5 6.5V17L16.5 11.5Z"></path><path d="M8 11.5L2 6.5V17L8 11.5Z"></path><path d="M9.5 12.5L2.81805 18.5002H21.6362L15 12.5L12 15L9.5 12.5Z"></path></g></svg>';
  var SVG_LOCK = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' + SVG_GRADIENT + '<g stroke="url(#pdx-gradient-stroke)" fill="none" stroke-width="1"><path d="M3.5 15.5503L9.20029 9.85L12.3503 13L11.6 13.7503H10.25L9.8 15.1003L8 16.0003L7.55 18.2503L5.5 19.6003H3.5V15.5503Z"></path><path d="M16 3.5H11L8.5 6L16 13.5L21 8.5L16 3.5Z"></path><path d="M16 10.5L18 8.5L15 5.5H13L12 6.5L16 10.5Z"></path></g></svg>';
  var SVG_USER = '<svg aria-hidden="true" width="18" height="18" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="m15.626 11.769a6 6 0 1 0 -7.252 0 9.008 9.008 0 0 0 -5.374 8.231 3 3 0 0 0 3 3h12a3 3 0 0 0 3-3 9.008 9.008 0 0 0 -5.374-8.231zm-7.626-4.769a4 4 0 1 1 4 4 4 4 0 0 1 -4-4zm10 14h-12a1 1 0 0 1 -1-1 7 7 0 0 1 14 0 1 1 0 0 1 -1 1z"></path></svg>';

  var publicModules = C.publicModules || ['trust', 'create', 'workspace'];
  var authMenuOpen = false;
  var profileOverlay = null;

  function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function isRestNonceError(data) {
    if (!data) return false;
    var code = data.code || data.error || '';
    return code === 'rest_cookie_invalid_nonce' || code === 'rest_invalid_nonce';
  }

  function applySession(data) {
    if (!data) return;
    if (data.nonce) C.nonce = data.nonce;
    var u = data.user || data;
    if (u.logged_in !== undefined) {
      user = u;
      C.isLoggedIn = !!u.logged_in;
      C.emailVerified = !!u.verified;
      C.userId = u.id || 0;
      C.userName = u.display_name || '';
      C.userEmail = u.email || '';
    }
    updateAuthBar();
  }

  function refreshSessionNonce() {
    var url = (C.ajaxUrl || '/wp-admin/admin-ajax.php') + '?action=pdx_rest_nonce&_=' + Date.now();
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (payload && payload.success && payload.data) {
          applySession(payload.data);
          return true;
        }
        return false;
      })
      .catch(function () { return false; });
  }

  function apiFetch(method, path, body, retried) {
    var opts = {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': C.nonce || '',
      },
    };
    if (body && method !== 'GET') opts.body = JSON.stringify(body);
    return fetch(C.restUrl + path, opts).then(function (r) {
      return r.json().then(function (data) {
        data._status = r.status;
        data._ok = r.ok;
        if (!retried && isRestNonceError(data)) {
          return refreshSessionNonce().then(function (ok) {
            if (ok) return apiFetch(method, path, body, true);
            data.message = 'Session expired. Please reload the page and try again.';
            return data;
          });
        }
        return data;
      });
    }).catch(function () {
      return { success: false, error: 'network', message: 'Network error. Please try again.' };
    });
  }

  function refreshUser() {
    return apiFetch('GET', '/auth/me').then(function (data) {
      applySession(data);
      return user;
    });
  }

  function moduleRequiresAuth(moduleId) {
    return publicModules.indexOf(moduleId) < 0;
  }

  function canAccessModule(moduleId) {
    if (!moduleRequiresAuth(moduleId)) return true;
    if (!user.logged_in) return false;
    if (!user.verified && !user.is_admin) return false;
    return true;
  }

  /* ─── Auth bar ─────────────────────────────────────────── */
  var authBar = null;
  var authBtn = null;
  var authMenu = null;

  function findHeaderMount() {
    var selectors = [
      'header .header-inner',
      'header .inside-header',
      'header .site-header-main',
      'header .elementor-container',
      '#masthead .inside-header',
      '#masthead',
      'header',
      '.site-header'
    ];
    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el) return el;
    }
    return null;
  }

  function createAuthBar() {
    authBar = document.createElement('div');
    authBar.id = 'pdx-auth-bar';
    authBar.innerHTML =
      '<div class="pdx-auth-bar-inner">' +
        '<button type="button" class="pdx-auth-trigger" aria-haspopup="true" aria-expanded="false">' +
          '<span class="pdx-auth-trigger-icon">' + SVG_USER + '</span>' +
          '<span class="pdx-auth-trigger-label">Log In</span>' +
        '</button>' +
        '<div class="pdx-auth-menu" hidden>' +
          '<div class="pdx-auth-menu-head"></div>' +
          '<div class="pdx-auth-menu-actions">' +
            '<button type="button" class="pdx-auth-menu-item" data-action="profile">My Profile</button>' +
            '<button type="button" class="pdx-auth-menu-item" data-action="account">My Account</button>' +
            '<button type="button" class="pdx-auth-menu-item pdx-auth-menu-item--logout" data-action="logout">Logout</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    authBtn = authBar.querySelector('.pdx-auth-trigger');
    authMenu = authBar.querySelector('.pdx-auth-menu');

    authBtn.addEventListener('click', onAuthBarClick);
    authBtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onAuthBarClick(); }
    });

    authMenu.querySelectorAll('.pdx-auth-menu-item').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.dataset.action;
        closeAuthMenu();
        if (action === 'profile') openProfileOverlay();
        else if (action === 'account') openAccountPanel();
        else if (action === 'logout') doLogout();
      });
    });

    document.addEventListener('click', function (e) {
      if (!authBar || !authMenuOpen) return;
      if (!authBar.contains(e.target)) closeAuthMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeAuthMenu();
    });

    var mount = findHeaderMount();
    if (mount) {
      if (window.getComputedStyle(mount).position === 'static') {
        mount.style.position = 'relative';
      }
      authBar.classList.add('pdx-auth-bar--header');
      mount.appendChild(authBar);
    } else {
      document.body.appendChild(authBar);
    }
    updateAuthBar();
  }

  function accountStatusLabel() {
    if (!user.logged_in) return 'Guest';
    if (user.is_admin) return 'Administrator';
    return user.verified ? 'Verified' : 'Pending verification';
  }

  function updateAuthBar() {
    if (!authBtn || !authMenu) return;
    var labelEl = authBtn.querySelector('.pdx-auth-trigger-label');
    var head = authMenu.querySelector('.pdx-auth-menu-head');
    var label = user.logged_in ? (user.display_name || 'Account') : 'Log In';

    if (labelEl) labelEl.textContent = label;
    authBtn.classList.toggle('pdx-auth-trigger--logged-in', user.logged_in);
    authBtn.classList.toggle('pdx-auth-trigger--verified', user.logged_in && user.verified);
    authBtn.setAttribute('aria-label', user.logged_in ? 'Account menu' : 'Log in');

    if (user.logged_in && head) {
      head.innerHTML =
        '<div class="pdx-auth-menu-name">' + escHtml(user.display_name || 'Account') + '</div>' +
        '<div class="pdx-auth-menu-email">' + escHtml(user.email || '') + '</div>' +
        '<div class="pdx-auth-menu-status">' + escHtml(accountStatusLabel()) + '</div>';
      authMenu.removeAttribute('hidden');
    } else {
      if (head) head.innerHTML = '';
      closeAuthMenu();
      authMenu.setAttribute('hidden', 'hidden');
    }
  }

  function openAuthMenu() {
    if (!user.logged_in || !authMenu) return;
    authMenu.hidden = false;
    authMenu.classList.add('is-open');
    authBtn.setAttribute('aria-expanded', 'true');
    authMenuOpen = true;
  }

  function closeAuthMenu() {
    if (!authMenu) return;
    authMenu.classList.remove('is-open');
    authBtn.setAttribute('aria-expanded', 'false');
    authMenuOpen = false;
  }

  function onAuthBarClick() {
    if (user.logged_in) {
      if (authMenuOpen) closeAuthMenu();
      else openAuthMenu();
    } else {
      openOverlay('login');
    }
  }

  function openAccountPanel() {
    if (window.PDXDock && window.PDXDock.openPanel) {
      window.PDXDock.openPanel('account');
    }
  }

  function openProfileOverlay() {
    if (!profileOverlay) {
      profileOverlay = document.createElement('div');
      profileOverlay.id = 'pdx-profile-overlay';
      profileOverlay.setAttribute('role', 'dialog');
      profileOverlay.setAttribute('aria-modal', 'true');
      profileOverlay.setAttribute('aria-label', 'My Profile');
      profileOverlay.innerHTML =
        '<div class="pdx-profile-card">' +
          '<button type="button" class="pdx-auth-close" aria-label="Close">&times;</button>' +
          '<div class="pdx-profile-card-title">My Profile</div>' +
          '<div class="pdx-profile-card-body"></div>' +
          '<div class="pdx-profile-card-actions">' +
            '<button type="button" class="pdx-account-btn pdx-profile-open-account">My Account</button>' +
            '<button type="button" class="pdx-account-btn pdx-account-btn--ghost pdx-profile-logout">Logout</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(profileOverlay);
      profileOverlay.querySelector('.pdx-auth-close').addEventListener('click', closeProfileOverlay);
      profileOverlay.addEventListener('click', function (e) {
        if (e.target === profileOverlay) closeProfileOverlay();
      });
      profileOverlay.querySelector('.pdx-profile-open-account').addEventListener('click', function () {
        closeProfileOverlay();
        openAccountPanel();
      });
      profileOverlay.querySelector('.pdx-profile-logout').addEventListener('click', function () {
        closeProfileOverlay();
        doLogout();
      });
    }
    var body = profileOverlay.querySelector('.pdx-profile-card-body');
    body.innerHTML =
      '<div class="pdx-profile-row"><span class="pdx-profile-label">Full Name</span><span class="pdx-profile-value">' + escHtml(user.display_name || '—') + '</span></div>' +
      '<div class="pdx-profile-row"><span class="pdx-profile-label">Email</span><span class="pdx-profile-value">' + escHtml(user.email || '—') + '</span></div>' +
      '<div class="pdx-profile-row"><span class="pdx-profile-label">Account Status</span><span class="pdx-profile-value">' + escHtml(accountStatusLabel()) + '</span></div>' +
      '<div class="pdx-profile-row"><span class="pdx-profile-label">Login Status</span><span class="pdx-profile-value">' + (user.logged_in ? 'Signed in' : 'Signed out') + '</span></div>';
    profileOverlay.classList.add('is-open');
    document.body.classList.add('pdx-no-scroll');
  }

  function closeProfileOverlay() {
    if (!profileOverlay) return;
    profileOverlay.classList.remove('is-open');
    document.body.classList.remove('pdx-no-scroll');
  }

  function doLogout() {
    apiFetch('POST', '/auth/logout').then(function (data) {
      if (data && data.nonce) {
        applySession({
          nonce: data.nonce,
          user: data.user || { logged_in: false, verified: false, display_name: '', email: '', id: 0 },
        });
      } else {
        user = { logged_in: false, verified: false };
        C.isLoggedIn = false;
        updateAuthBar();
      }
      notify('Logged out.', 'info');
      if (window.PDXDock && window.PDXDock.closePanel) window.PDXDock.closePanel();
    });
  }

  /* ─── Auth overlay ─────────────────────────────────────── */
  var overlay = null;
  var formEl = null;

  function createOverlay() {
    overlay = document.createElement('div');
    overlay.id = 'pdx-auth-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Authentication');
    overlay.innerHTML =
      '<div class="pdx-auth-wrapper">' +
        '<button type="button" class="pdx-auth-close" aria-label="Close">&times;</button>' +
        '<div class="pdx-auth-form-wrap"></div>' +
      '</div>';
    document.body.appendChild(overlay);
    overlay.querySelector('.pdx-auth-close').addEventListener('click', closeOverlay);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeOverlay();
    });
    formEl = overlay.querySelector('.pdx-auth-form-wrap');
  }

  function openOverlay(view, moduleId) {
    if (moduleId) returnModule = moduleId;
    currentView = view || 'login';
    renderAuthForm();
    overlay.classList.add('is-open');
    document.body.classList.add('pdx-no-scroll');
    var first = overlay.querySelector('input:not([type=submit])');
    if (first) setTimeout(function () { first.focus(); }, 100);
  }

  function closeOverlay() {
    overlay.classList.remove('is-open');
    document.body.classList.remove('pdx-no-scroll');
  }

  function renderAuthForm() {
    var titles = { login: 'Login', register: 'Register', forgot: 'Forgot', reset: 'Reset' };
    var html = '<form class="pdx-auth-form pdx-auth-form--' + currentView + '" novalidate>';
    html += '<span class="pdx-auth-title">' + (titles[currentView] || 'Login') + '</span>';
    html += '<div class="pdx-auth-msg-slot"></div>';
    html += '<div class="pdx-auth-fields">';

    if (currentView === 'login') {
      html += fieldInput('email', 'email', 'Email', SVG_EMAIL, 'email', true);
      html += fieldInput('password', 'password', 'Password', SVG_LOCK, 'current-password', true);
    } else if (currentView === 'register') {
      html += fieldInput('name', 'text', 'Full name', null, 'name', true);
      html += fieldInput('email', 'email', 'Email', SVG_EMAIL, 'email', true);
      html += fieldInput('password', 'password', 'Password (min 8 characters)', SVG_LOCK, 'new-password', true);
    } else if (currentView === 'forgot') {
      html += fieldInput('email', 'email', 'Email', SVG_EMAIL, 'email', true);
    } else if (currentView === 'reset') {
      html += fieldInput('password', 'password', 'New password', SVG_LOCK, 'new-password', true);
      html += fieldInput('password2', 'password', 'Confirm password', SVG_LOCK, 'new-password', true);
    }

    html += '</div>';

    if (currentView === 'login') {
      html += submitBtn('Login');
      html += links([
        { view: 'forgot', label: 'Forgot password?' },
        { view: 'register', label: 'Create account' },
      ]);
    } else if (currentView === 'register') {
      html += submitBtn('Register');
      html += links([{ view: 'login', label: 'Already have an account? Log in' }]);
    } else if (currentView === 'forgot') {
      html += submitBtn('Send Reset Link');
      html += links([{ view: 'login', label: 'Back to login' }]);
    } else if (currentView === 'reset') {
      html += submitBtn('Reset Password');
      html += links([{ view: 'login', label: 'Back to login' }]);
    }

    html += '<div class="pdx-auth-texture"></div></form>';
    formEl.innerHTML = html;

    var form = formEl.querySelector('.pdx-auth-form');
    form.addEventListener('submit', onAuthSubmit);
    formEl.querySelectorAll('.pdx-auth-link').forEach(function (btn) {
      btn.addEventListener('click', function () {
        currentView = btn.dataset.view;
        renderAuthForm();
      });
    });
  }

  function fieldInput(name, type, label, icon, autocomplete, required) {
    var id = 'pdx-auth-' + currentView + '-' + name;
    var html = '<div class="pdx-auth-field" data-field="' + name + '">';
    html += '<label class="pdx-auth-field-label" for="' + id + '">' + escHtml(label) + '</label>';
    html += '<div class="pdx-auth-input-container">';
    if (icon) html += icon;
    html += '<input class="pdx-auth-input" id="' + id + '" name="' + name + '" type="' + type + '"';
    html += ' placeholder="' + escHtml(label) + '"';
    if (autocomplete) html += ' autocomplete="' + autocomplete + '"';
    if (required) html += ' required aria-required="true"';
    html += ' /></div></div>';
    return html;
  }

  function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  }

  function validateAuthForm(view, fd) {
    var name;
    var email;
    var password;
    var password2;

    if (view === 'login') {
      email = String(fd.get('email') || '').trim();
      password = String(fd.get('password') || '');
      if (!email) return { message: 'Please enter your email address.', field: 'email' };
      if (!isValidEmail(email)) return { message: 'Please enter a valid email address.', field: 'email' };
      if (!password) return { message: 'Please enter your password.', field: 'password' };
      return null;
    }

    if (view === 'register') {
      name = String(fd.get('name') || '').trim();
      email = String(fd.get('email') || '').trim();
      password = String(fd.get('password') || '');
      if (!name) return { message: 'Please enter your name.', field: 'name' };
      if (!email) return { message: 'Please enter your email address.', field: 'email' };
      if (!isValidEmail(email)) return { message: 'Please enter a valid email address.', field: 'email' };
      if (password.length < 8) return { message: 'Password must be at least 8 characters.', field: 'password' };
      return null;
    }

    if (view === 'forgot') {
      email = String(fd.get('email') || '').trim();
      if (!email) return { message: 'Please enter your email address.', field: 'email' };
      if (!isValidEmail(email)) return { message: 'Please enter a valid email address.', field: 'email' };
      return null;
    }

    if (view === 'reset') {
      password = String(fd.get('password') || '');
      password2 = String(fd.get('password2') || '');
      if (password.length < 8) return { message: 'Password must be at least 8 characters.', field: 'password' };
      if (password !== password2) return { message: 'Passwords do not match.', field: 'password2' };
      return null;
    }

    return null;
  }

  function markFieldError(fieldName) {
    formEl.querySelectorAll('.pdx-auth-field').forEach(function (el) {
      el.classList.remove('pdx-auth-field--error');
    });
    if (!fieldName) return;
    var field = formEl.querySelector('.pdx-auth-field[data-field="' + fieldName + '"]');
    if (field) {
      field.classList.add('pdx-auth-field--error');
      var input = field.querySelector('input');
      if (input) input.focus();
    }
  }

  function submitBtn(label) {
    return '<div class="pdx-auth-submit-wrap"><input class="pdx-auth-submit" type="submit" value="' + escHtml(label) + '" /></div>';
  }

  function links(items) {
    var html = '<div class="pdx-auth-links">';
    items.forEach(function (item) {
      html += '<button type="button" class="pdx-auth-link" data-view="' + item.view + '">' + escHtml(item.label) + '</button>';
    });
    return html + '</div>';
  }

  function showFormMessage(msg, type) {
    var slot = formEl.querySelector('.pdx-auth-msg-slot');
    if (!slot) return;
    slot.innerHTML = msg ? '<div class="pdx-auth-message pdx-auth-message--' + type + '">' + escHtml(msg) + '</div>' : '';
  }

  function onAuthSubmit(e) {
    e.preventDefault();
    var form = e.target;
    var fd = new FormData(form);
    showFormMessage('', '');
    markFieldError(null);

    var validationError = validateAuthForm(currentView, fd);
    if (validationError) {
      showFormMessage(validationError.message, 'error');
      markFieldError(validationError.field);
      return;
    }

    if (currentView === 'login') {
      apiFetch('POST', '/auth/login', {
        email: fd.get('email'),
        password: fd.get('password'),
        remember: true,
      }).then(function (data) {
        if (!data.success) {
          showFormMessage(data.message || 'Login failed.', 'error');
          return;
        }
        applySession({ user: data.user || user, nonce: data.nonce });
        closeOverlay();
        notify(data.message || 'Logged in.', 'info');
        var mod = returnModule;
        returnModule = null;
        refreshUser().then(function () {
          if (mod && window.PDXDock && window.PDXDock.openPanel) {
            window.PDXDock.openPanel(mod);
          }
        });
      });
    } else if (currentView === 'register') {
      apiFetch('POST', '/auth/register', {
        name: fd.get('name'),
        email: fd.get('email'),
        password: fd.get('password'),
      }).then(function (data) {
        if (!data.success) {
          showFormMessage(data.message || 'Registration failed.', 'error');
          return;
        }
        showFormMessage(data.message, 'success');
        setTimeout(function () { currentView = 'login'; renderAuthForm(); showFormMessage('Account created. Log in after verifying your email.', 'success'); }, 2000);
      });
    } else if (currentView === 'forgot') {
      apiFetch('POST', '/auth/forgot-password', { email: fd.get('email') }).then(function (data) {
        showFormMessage(data.message || 'Check your email.', 'success');
      });
    } else if (currentView === 'reset') {
      var p1 = fd.get('password');
      var params = new URLSearchParams(window.location.search);
      apiFetch('POST', '/auth/reset-password', {
        token: params.get('token') || '',
        uid: parseInt(params.get('uid') || '0', 10),
        password: p1,
      }).then(function (data) {
        if (!data.success) { showFormMessage(data.message, 'error'); return; }
        showFormMessage(data.message, 'success');
        setTimeout(function () { currentView = 'login'; renderAuthForm(); }, 2000);
      });
    }
  }

  function notify(msg, type) {
    if (window.PDXDock && window.PDXDock.showNotif) {
      window.PDXDock.showNotif(msg, type);
    }
  }

  /* ─── URL handlers ─────────────────────────────────────── */
  function handleUrlParams() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('pdx_reset') === '1' && params.get('token')) {
      currentView = 'reset';
      openOverlay('reset');
    }
    if (params.get('pdx_auth') === 'verified') {
      notify(decodeURIComponent(params.get('pdx_msg') || 'Email verified!'), 'info');
      refreshUser();
      cleanUrl();
    }
    if (params.get('pdx_auth') === 'verify_failed') {
      notify(decodeURIComponent(params.get('pdx_msg') || 'Verification failed.'), 'warn');
      cleanUrl();
    }
    if (params.get('pdx_account') === '1') {
      if (user.logged_in) {
        openAccountPanel();
      } else {
        openOverlay('login');
      }
      cleanUrl();
    }
  }

  function cleanUrl() {
    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, '', window.location.pathname);
    }
  }

  /* ─── Access gate ──────────────────────────────────────── */
  function renderAuthGate(container, moduleId, reason) {
    var title = reason === 'verify' ? 'Verify your email' : 'Sign in required';
    var desc = reason === 'verify'
      ? 'Please verify your email address to continue using protected modules.'
      : 'Sign in to access protected modules. Premium features still require purchase or subscription.';
    container.innerHTML =
      '<div class="pdx-auth-gate">' +
        '<div class="pdx-auth-gate-title">' + title + '</div>' +
        '<div class="pdx-auth-gate-desc">' + desc + '</div>' +
        '<button type="button" class="pdx-account-btn pdx-auth-gate-login">' + (reason === 'verify' ? 'Resend verification' : 'Log In') + '</button>' +
        (reason !== 'verify' ? '<button type="button" class="pdx-account-btn pdx-account-btn--ghost pdx-auth-gate-register" style="margin-left:8px">Register</button>' : '') +
      '</div>';
    container.querySelector('.pdx-auth-gate-login').addEventListener('click', function () {
      if (reason === 'verify') {
        apiFetch('POST', '/auth/resend-verification').then(function (data) {
          notify(data.message || 'Verification email sent.', data.success ? 'info' : 'warn');
        });
      } else {
        openOverlay('login', moduleId);
      }
    });
    var regBtn = container.querySelector('.pdx-auth-gate-register');
    if (regBtn) {
      regBtn.addEventListener('click', function () { openOverlay('register', moduleId); });
    }
  }

  /* ─── Account dashboard ────────────────────────────────── */
  function renderAccountDashboard(container) {
    container.innerHTML = '<div class="pdx-loading">Loading account…</div>';
    apiFetch('GET', '/account/dashboard').then(function (data) {
      if (data.error || !data.profile) {
        container.innerHTML = '<div class="pdx-error">Could not load account. Please log in again.</div>';
        return;
      }
      dashboardData = data;
      renderDashboardUI(container, data);
    });
  }

  function renderDashboardUI(container, data) {
    var p = data.profile;
    var html =
      '<div class="pdx-account-dash">' +
        '<div class="pdx-account-nav">' +
          navBtn('profile', 'Profile', true) +
          navBtn('api-keys', 'API Keys') +
          navBtn('integrations', 'Integrations') +
          navBtn('license', 'License') +
        '</div>' +
        '<div class="pdx-ph-body" style="flex:1;overflow-y:auto">' +
          sectionProfile(p) +
          sectionApiKeys(data.api_keys || []) +
          sectionIntegrations(data.integrations || []) +
          sectionLicense(data.license || {}) +
        '</div>' +
      '</div>';
    container.innerHTML = html;

    container.querySelectorAll('.pdx-account-nav-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        container.querySelectorAll('.pdx-account-nav-btn').forEach(function (b) { b.classList.remove('is-active'); });
        container.querySelectorAll('.pdx-account-section').forEach(function (s) { s.classList.remove('is-active'); });
        btn.classList.add('is-active');
        var sec = container.querySelector('#pdx-acc-' + btn.dataset.section);
        if (sec) sec.classList.add('is-active');
      });
    });

    bindProfileForm(container);
    bindApiKeyForms(container);
    bindLogout(container);
  }

  function navBtn(id, label, active) {
    return '<button type="button" class="pdx-account-nav-btn' + (active ? ' is-active' : '') + '" data-section="' + id + '">' + escHtml(label) + '</button>';
  }

  function sectionProfile(p) {
    var statusCls = p.verified ? 'verified' : 'pending';
    var statusLabel = p.verified ? 'Verified' : 'Pending verification';
    return '<div id="pdx-acc-profile" class="pdx-account-section is-active">' +
      '<div class="pdx-account-card">' +
        '<div class="pdx-account-card-title">Account</div>' +
        '<span class="pdx-account-status pdx-account-status--' + statusCls + '">' + statusLabel + '</span>' +
        (!p.verified ? '<button type="button" class="pdx-account-btn pdx-resend-verify" style="margin-left:8px">Resend email</button>' : '') +
      '</div>' +
      '<div class="pdx-account-card">' +
        '<div class="pdx-account-card-title">Profile</div>' +
        '<form id="pdx-profile-form">' +
          field('display_name', 'Display name', p.display_name) +
          field('email', 'Email', p.email, 'email') +
          field('current_password', 'Current password (to change password)', '', 'password') +
          field('new_password', 'New password', '', 'password') +
          '<button type="submit" class="pdx-account-btn">Save changes</button>' +
        '</form>' +
      '</div>' +
      '<button type="button" class="pdx-account-btn pdx-account-btn--ghost pdx-logout-btn">Log out</button>' +
    '</div>';
  }

  function field(name, label, value, type) {
    type = type || 'text';
    return '<div class="pdx-account-field"><label>' + escHtml(label) + '</label>' +
      '<input name="' + name + '" type="' + type + '" value="' + escHtml(value || '') + '" autocomplete="' + (type === 'password' ? 'new-password' : 'off') + '" /></div>';
  }

  function sectionApiKeys(keys) {
    var html = '<div id="pdx-acc-api-keys" class="pdx-account-section"><div class="pdx-account-card"><div class="pdx-account-card-title">Your API Keys</div>';
    keys.forEach(function (k) {
      var st = k.status || 'disconnected';
      html += '<div class="pdx-api-key-row" data-provider="' + escHtml(k.provider) + '">' +
        '<div class="pdx-api-key-header">' +
          '<span class="pdx-api-key-label">' + escHtml(k.label) + '</span>' +
          '<span class="pdx-api-key-status pdx-api-key-status--' + st + '">' + escHtml(st) + '</span>' +
        '</div>' +
        (k.masked ? '<div style="font-size:11px;color:#555;margin-bottom:4px">' + escHtml(k.masked) + '</div>' : '') +
        '<div class="pdx-api-key-actions">' +
          '<input type="password" placeholder="Enter API key" autocomplete="off" />' +
          '<button type="button" class="pdx-account-btn pdx-save-key">Save</button>' +
          '<button type="button" class="pdx-account-btn pdx-account-btn--ghost pdx-validate-key">Validate</button>' +
          '<button type="button" class="pdx-account-btn pdx-account-btn--ghost pdx-clear-key">Clear</button>' +
        '</div>' +
      '</div>';
    });
    return html + '</div></div>';
  }

  function sectionIntegrations(items) {
    var html = '<div id="pdx-acc-integrations" class="pdx-account-section"><div class="pdx-account-card"><div class="pdx-account-card-title">Provider Integrations</div>';
    items.forEach(function (i) {
      html += '<div class="pdx-api-key-row">' +
        '<div class="pdx-api-key-header">' +
          '<span class="pdx-api-key-label">' + escHtml(i.label) + '</span>' +
          '<span class="pdx-api-key-status pdx-api-key-status--' + escHtml(i.status) + '">' + escHtml(i.status.replace('_', ' ')) + '</span>' +
        '</div>' +
        '<div style="font-size:11px;color:#555">Source: ' + escHtml(i.source) + '</div>' +
      '</div>';
    });
    return html + '</div></div>';
  }

  function sectionLicense(lic) {
    return '<div id="pdx-acc-license" class="pdx-account-section">' +
      '<div class="pdx-license-placeholder">' +
        '<strong>License & Subscription</strong>' +
        'Plan: ' + escHtml(lic.plan || 'free') + ' · Status: ' + escHtml(lic.status || 'inactive') + '<br><br>' +
        'Subscription management and license keys will be available here. Connect your billing plan to unlock premium modules across the platform.' +
      '</div></div>';
  }

  function bindProfileForm(container) {
    var form = container.querySelector('#pdx-profile-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      apiFetch('POST', '/account/profile', {
        display_name: fd.get('display_name'),
        email: fd.get('email'),
        current_password: fd.get('current_password'),
        new_password: fd.get('new_password'),
      }).then(function (data) {
        notify(data.message || 'Updated.', data.success ? 'info' : 'warn');
        if (data.success && data.user) { user = data.user; updateAuthBar(); refreshUser(); }
      });
    });
    var resend = container.querySelector('.pdx-resend-verify');
    if (resend) {
      resend.addEventListener('click', function () {
        apiFetch('POST', '/auth/resend-verification').then(function (data) {
          notify(data.message, data.success ? 'info' : 'warn');
        });
      });
    }
  }

  function bindApiKeyForms(container) {
    container.querySelectorAll('.pdx-api-key-row[data-provider]').forEach(function (row) {
      var provider = row.dataset.provider;
      row.querySelector('.pdx-save-key').addEventListener('click', function () {
        var key = row.querySelector('input').value;
        apiFetch('POST', '/account/api-keys', { provider: provider, key: key }).then(function (data) {
          notify(data.message, data.success ? 'info' : 'warn');
          if (data.success) renderAccountDashboard(container.closest('.pdx-ph-body') || container);
        });
      });
      row.querySelector('.pdx-validate-key').addEventListener('click', function () {
        apiFetch('POST', '/account/api-keys/validate', { provider: provider }).then(function (data) {
          notify(data.message, data.success ? 'info' : 'warn');
        });
      });
      row.querySelector('.pdx-clear-key').addEventListener('click', function () {
        apiFetch('POST', '/account/api-keys', { provider: provider, key: '' }).then(function (data) {
          notify('Key cleared.', 'info');
          renderAccountDashboard(container.closest('.pdx-ph-body') || container);
        });
      });
    });
  }

  function bindLogout(container) {
    var btn = container.querySelector('.pdx-logout-btn');
    if (!btn) return;
    btn.addEventListener('click', doLogout);
  }

  /* ─── Public API ───────────────────────────────────────── */
  window.PDXAuth = {
    init: function () {
      createAuthBar();
      createOverlay();
      handleUrlParams();
      refreshUser();
    },
    isLoggedIn: function () { return !!user.logged_in; },
    isVerified: function () { return !!user.verified || !!user.is_admin; },
    canAccessModule: canAccessModule,
    moduleRequiresAuth: moduleRequiresAuth,
    openLogin: function (moduleId) { openOverlay('login', moduleId); },
    renderAuthGate: renderAuthGate,
    renderAccountDashboard: renderAccountDashboard,
    refreshUser: refreshUser,
    refreshSessionNonce: refreshSessionNonce,
    applySession: applySession,
    getNonce: function () { return C.nonce || ''; },
    getUser: function () { return user; },
    isRestNonceError: isRestNonceError,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.PDXAuth.init);
  } else {
    window.PDXAuth.init();
  }
})();
