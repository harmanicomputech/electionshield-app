/* Election Shield: service worker, offline banner, live refresh, install prompt. */
(function () {
  'use strict';

  var body = document.body;
  var loggedIn = body.hasAttribute('data-cache-pages');
  var meta = document.querySelector('meta[name="es-generated-at"]');
  var tz = (document.querySelector('meta[name="es-timezone"]') || {}).content || 'Africa/Lagos';
  var banner = document.querySelector('[data-offline-banner]');
  var LIVE_PAGES = [/^\/$/, /^\/spread$/, /^\/collation(\/.*)?$/];
  var REFRESH_MS = 60000;

  function safe(fn) { try { return fn(); } catch (e) { return undefined; } }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
  }

  function clearData() {
    if (window.caches) { caches.delete('es-pages'); }
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage('clear-data');
    }
  }

  // Logging out (or landing on login after it) clears cached data pages.
  document.querySelectorAll('[data-logout]').forEach(function (form) { form.addEventListener('submit', clearData); });
  if (document.querySelector('[data-clear-cache]')) { clearData(); }

  // How old the data on screen is.
  function generatedAt() { return meta ? new Date(meta.content) : null; }

  function clock(date) {
    return safe(function () { return date.toLocaleTimeString('en-NG', { hour: 'numeric', minute: '2-digit', timeZone: tz }); }) || date.toLocaleTimeString();
  }

  function showStatus() {
    if (!banner || !loggedIn) { return; }
    var at = generatedAt();
    var stale = at && Date.now() - at.getTime() > 2 * REFRESH_MS;

    if (!navigator.onLine || stale) {
      banner.textContent = (navigator.onLine ? 'Not live: ' : 'Offline: ') + 'showing data from ' + (at ? clock(at) : 'earlier');
      banner.hidden = false;
    } else {
      banner.hidden = true;
    }
  }

  // Live pages refresh their content every minute while visible and online.
  function isLivePage() { return LIVE_PAGES.some(function (p) { return p.test(location.pathname); }); }

  function refresh() {
    if (!loggedIn || !isLivePage() || document.hidden || !navigator.onLine) { return; }
    if (document.activeElement && /INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName)) { return; }

    fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'refresh' } })
      .then(function (response) {
        if (!response.ok || response.redirected) { throw new Error('refresh failed'); }
        return response.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var main = doc.getElementById('main');
        var newMeta = doc.querySelector('meta[name="es-generated-at"]');
        if (main) { document.getElementById('main').innerHTML = main.innerHTML; bindRows(); bindGuide(); }
        if (newMeta && meta) { meta.content = newMeta.content; }
        showStatus();
      })
      .catch(showStatus);
  }

  window.addEventListener('online', function () { showStatus(); refresh(); });
  window.addEventListener('offline', showStatus);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
  setInterval(refresh, REFRESH_MS);
  setInterval(showStatus, 15000);
  showStatus();

  // Stacked table rows on phones: details behind a tap.
  function bindRows() {
    document.querySelectorAll('[data-toggle-row]').forEach(function (button) {
      button.addEventListener('click', function () {
        var row = button.closest('tr');
        var open = row.classList.toggle('open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? 'Hide details' : 'Details';
      });
    });
  }
  bindRows();

  // Install: Android/desktop Chrome prompt, and a Home Screen guide on iPhone.
  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferred = event;
    document.querySelectorAll('[data-install]').forEach(function (b) { b.hidden = false; });
  });
  document.querySelectorAll('[data-install]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!deferred) { return; }
      deferred.prompt();
      deferred.userChoice.finally(function () {
        deferred = null;
        document.querySelectorAll('[data-install]').forEach(function (b) { b.hidden = true; });
      });
    });
  });

  function bindGuide() {
    var guide = document.querySelector('[data-ios-guide]');
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var standalone = window.navigator.standalone === true || safe(function () { return matchMedia('(display-mode: standalone)').matches; });
    var dismissed = safe(function () { return localStorage.getItem('es-ios-guide') === 'hidden'; });

    if (!guide || !ios || standalone || dismissed) { return; }
    guide.classList.add('show');
    var close = guide.querySelector('[data-dismiss]');
    if (close) {
      close.addEventListener('click', function () {
        guide.classList.remove('show');
        safe(function () { localStorage.setItem('es-ios-guide', 'hidden'); });
      });
    }
  }
  bindGuide();
})();
