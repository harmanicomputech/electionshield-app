/* Election Shield: service worker, offline banner, live refresh, offline action queue, install prompt. */
(function () {
  'use strict';

  var body = document.body;
  var loggedIn = body.hasAttribute('data-cache-pages');
  var meta = document.querySelector('meta[name="es-generated-at"]');
  var tz = (document.querySelector('meta[name="es-timezone"]') || {}).content || 'Africa/Lagos';
  var banner = document.querySelector('[data-offline-banner]');
  var LIVE_PAGES = [/^\/$/, /^\/spread$/, /^\/collation(\/.*)?$/, /^\/monitor(\/.*)?$/, /^\/incidents$/];
  var QUEUE_KEY = 'es-queue';
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

  function refresh(force) {
    if (!loggedIn || !isLivePage() || document.hidden || !navigator.onLine) { return; }
    // Don't pull the page from under someone who is typing or has a form open.
    if (!force && document.activeElement && /INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName)) { return; }
    if (!force && document.querySelector('#main details[open]')) { return; }

    fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'refresh' } })
      .then(function (response) {
        if (!response.ok || response.redirected) { throw new Error('refresh failed'); }
        return response.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var main = doc.getElementById('main');
        var newMeta = doc.querySelector('meta[name="es-generated-at"]');
        if (main) { document.getElementById('main').innerHTML = main.innerHTML; bindMain(); }
        // Navigation badges (e.g. urgent incidents) live outside #main.
        doc.querySelectorAll('[data-live-id]').forEach(function (fresh) {
          document.querySelectorAll('[data-live-id="' + fresh.getAttribute('data-live-id') + '"]').forEach(function (el) { el.innerHTML = fresh.innerHTML; });
        });
        if (newMeta && meta) { meta.content = newMeta.content; }
        showStatus();
      })
      .catch(showStatus);
  }

  window.addEventListener('online', function () { showStatus(); flushQueue(); refresh(); });
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

  /*
   * Offline action queue. Forms marked data-queue (acknowledge/resolve an
   * incident) are sent in the background; with no connection the action is
   * stored on this device and sent when the connection returns or the app is
   * opened again. The server treats every action as safe to repeat.
   */
  function readQueue() { return safe(function () { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }) || []; }
  function writeQueue(items) { safe(function () { localStorage.setItem(QUEUE_KEY, JSON.stringify(items)); }); showQueue(); }

  function showQueue(message) {
    var el = document.querySelector('[data-queue-banner]');
    if (!el) { return; }
    var count = readQueue().length;
    el.textContent = message || (count ? count + ' action' + (count > 1 ? 's' : '') + ' queued: will send when you are back online' : '');
    el.hidden = !el.textContent;
  }

  function send(item) {
    return fetch(item.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: item.body
    });
  }

  function setState(form, text, cls) {
    var item = form.closest('.item');
    var el = item && item.querySelector('[data-queue-state]');
    if (el) { el.textContent = text; el.className = 'queue-state ' + cls; }
  }

  var flushing = false;
  function flushQueue() {
    var items = readQueue();
    if (flushing || !items.length || !navigator.onLine) { return; }
    flushing = true;
    var failed = [];

    items.reduce(function (chain, item) {
      return chain.then(function () {
        return send(item).then(function (response) {
          // 419 (session expired) or 4xx will never succeed: drop and report.
          if (!response.ok && response.status < 500) { failed.push(item.label); }
          else if (!response.ok) { throw new Error('server'); }
          writeQueue(readQueue().filter(function (q) { return q.id !== item.id; }));
        });
      });
    }, Promise.resolve())
      .catch(function () {})
      .then(function () {
        flushing = false;
        showQueue(failed.length ? 'Could not send: ' + failed.join(', ') + '. Log in again and retry.' : (readQueue().length ? '' : 'Queued actions sent ✓'));
        if (!failed.length && !readQueue().length) { setTimeout(function () { showQueue(); }, 4000); }
        refresh(true);
      });
  }

  function bindQueue() {
    document.querySelectorAll('form[data-queue]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var item = { id: Date.now() + '-' + Math.random().toString(36).slice(2), url: form.action, body: new URLSearchParams(new FormData(form)).toString(), label: form.getAttribute('data-queue') };
        var button = form.querySelector('button');
        if (button) { button.disabled = true; }

        var queue = function () {
          writeQueue(readQueue().concat([item]));
          setState(form, 'Queued: will send when online', 'queued');
        };

        if (!navigator.onLine) { queue(); return; }

        send(item).then(function (response) {
          if (response.ok) { setState(form, 'Sent ✓', 'sent'); setTimeout(function () { refresh(true); }, 600); return; }
          if (response.status >= 500) { queue(); return; }
          setState(form, response.status === 419 ? 'Session expired: reload the page and log in again' : 'Could not send (' + response.status + ')', 'failed');
          if (button) { button.disabled = false; }
        }, queue);
      });
    });
  }

  // Filters apply as soon as they change (the Filter button is the no-JS path).
  function bindFilters() {
    document.querySelectorAll('[data-autosubmit]').forEach(function (input) {
      input.addEventListener('change', function () { input.form.submit(); });
    });
    document.querySelectorAll('[data-js-hide]').forEach(function (el) { el.hidden = true; });
  }

  function bindMain() { bindRows(); bindGuide(); bindQueue(); bindFilters(); }

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
  bindMain();
  showQueue();
  flushQueue();
})();
