/* Election Shield: service worker, offline banner, live refresh, offline action queue, install prompt. */
(function () {
  'use strict';

  var body = document.body;
  var loggedIn = body.hasAttribute('data-cache-pages');
  var meta = document.querySelector('meta[name="es-generated-at"]');
  var tz = (document.querySelector('meta[name="es-timezone"]') || {}).content || 'Africa/Lagos';
  var banner = document.querySelector('[data-offline-banner]');
  var LIVE_PAGES = [/^\/$/, /^\/spread$/, /^\/collation(\/.*)?$/, /^\/monitor(\/.*)?$/, /^\/incidents$/, /^\/corrections$/, /^\/manage\/townhall\/[^/]+$/];
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

  window.addEventListener('online', function () { showStatus(); flushQueue(); flushPhotos(); flushForms(); refresh(); });
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
   * (Read the URL with getAttribute: a field named "action" hides the form's own action property.)
   */
  function readQueue() { return safe(function () { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }) || []; }
  function writeQueue(items) { safe(function () { localStorage.setItem(QUEUE_KEY, JSON.stringify(items)); }); showQueue(); }

  function showQueue(message) {
    var el = document.querySelector('[data-queue-banner]');
    if (!el) { return; }
    var count = readQueue().length + queuedPhotos + queuedForms;
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
    var item = form.closest('.item') || form;
    var el = item.querySelector('[data-queue-state]');
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
        var item = { id: Date.now() + '-' + Math.random().toString(36).slice(2), url: form.getAttribute('action'), body: new URLSearchParams(new FormData(form)).toString(), label: form.getAttribute('data-queue') };
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

  // Slow forms (reading a sheet with AI) say so and can't be sent twice.
  function bindBusyForms() {
    document.querySelectorAll('form[data-busy]').forEach(function (form) {
      form.addEventListener('submit', function () {
        var button = form.querySelector('button[type=submit]');
        if (button) { button.disabled = true; button.textContent = form.getAttribute('data-busy'); }
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

  /*
   * EC8A photo uploads (forms marked data-photo). The photo is shrunk on the
   * phone (long side 2000px, JPEG) so it goes through on 3G. With no
   * connection it is kept in IndexedDB and sent when the connection
   * returns: by the service worker (Background Sync) where supported, and
   * by this page on load / on reconnect everywhere. The server stores the
   * same file only once, so both sending it is harmless.
   */
  var queuedPhotos = 0;
  var MAX_SIDE = 2000;

  function idb() {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('no indexedDB')); return; }
      var request = indexedDB.open('es-queue', 2);
      request.onupgradeneeded = function () {
        ['photos', 'forms'].forEach(function (name) {
          if (!request.result.objectStoreNames.contains(name)) { request.result.createObjectStore(name, { keyPath: 'id' }); }
        });
      };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
  }

  function photoStore(mode, fn, name) {
    return idb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(name || 'photos', mode);
        var result = fn(tx.objectStore(name || 'photos'));
        tx.oncomplete = function () { resolve(result && 'result' in result ? result.result : undefined); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  function countPhotos() {
    return photoStore('readonly', function (store) { return store.count(); })
      .then(function (count) { queuedPhotos = count || 0; showQueue(); }, function () {});
  }

  function shrink(file) {
    if (!window.createImageBitmap || !file.type || !/^image\//.test(file.type)) { return Promise.resolve(file); }
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
      var scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
      if (scale === 1 && file.size < 1500000 && file.type === 'image/jpeg') { return file; }
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
      });
    }).catch(function () { return file; });
  }

  function sendPhoto(item) {
    var data = new FormData();
    item.fields.forEach(function (pair) { data.append(pair[0], pair[1]); });
    data.append('photo', item.blob, item.filename);
    return fetch(item.url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: data });
  }

  function queuePhoto(item) {
    return photoStore('readwrite', function (store) { store.put(item); }).then(function () {
      countPhotos();
      if (navigator.serviceWorker && navigator.serviceWorker.ready) {
        navigator.serviceWorker.ready.then(function (reg) { if (reg.sync) { return reg.sync.register('es-photos'); } }).catch(function () {});
      }
    });
  }

  var sendingPhotos = false;
  function flushPhotos() {
    if (sendingPhotos || !navigator.onLine) { return; }
    sendingPhotos = true;
    photoStore('readonly', function (store) { return store.getAll(); }).then(function (items) {
      return (items || []).reduce(function (chain, item) {
        return chain.then(function () {
          return sendPhoto(item).then(function (response) {
            if (response.status >= 500) { throw new Error('server'); }
            if (!response.ok) { showQueue('Could not send the photo for ' + item.label + ' (' + response.status + ').'); }
            return photoStore('readwrite', function (store) { store.delete(item.id); });
          });
        });
      }, Promise.resolve());
    }).catch(function () {}).then(function () { sendingPhotos = false; countPhotos(); });
  }

  function bindPhotos() {
    document.querySelectorAll('form[data-photo]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        var input = form.querySelector('input[type=file]');
        var file = input && input.files && input.files[0];
        if (!file || !window.fetch || !window.FormData) { return; } // plain form post
        event.preventDefault();

        var button = form.querySelector('button[type=submit]');
        if (button) { button.disabled = true; }
        setState(form, 'Preparing photo…', 'queued');

        shrink(file).then(function (blob) {
          var fields = [];
          new FormData(form).forEach(function (value, key) { if (key !== 'photo') { fields.push([key, value]); } });
          var item = { id: Date.now() + '-' + Math.random().toString(36).slice(2), url: form.getAttribute('action'), fields: fields, blob: blob, filename: (file.name || 'ec8a').replace(/\.[^.]+$/, '') + '.jpg', label: form.getAttribute('data-photo') };

          var keep = function () {
            return queuePhoto(item).then(function () {
              setState(form, 'Saved on this phone: it will be sent when the network returns', 'queued');
            }, function () {
              setState(form, 'No connection, and this browser cannot keep the photo. Try again with signal.', 'failed');
              if (button) { button.disabled = false; }
            });
          };

          if (!navigator.onLine) { return keep(); }

          setState(form, 'Sending…', 'queued');
          return sendPhoto(item).then(function (response) {
            if (response.status >= 500) { return keep(); }
            return response.json().catch(function () { return {}; }).then(function (body) {
              if (response.ok) {
                setState(form, 'Photo sent ✓', 'sent');
                form.reset();
                if (body.url && !form.hasAttribute('data-stay')) { setTimeout(function () { location.href = body.url; }, 700); }
              } else {
                var errors = body.errors ? Object.keys(body.errors).map(function (k) { return body.errors[k][0]; }).join(' ') : (body.message || 'Could not send (' + response.status + ')');
                setState(form, errors, 'failed');
              }
              if (button) { button.disabled = false; }
            });
          }, keep);
        });
      });
    });
  }


  /*
   * Agent pages (forms marked data-field-form): results, incidents, check-in
   * and materials, with photos and videos. Photos are shrunk on the phone.
   * With no network (or the server unreachable) the whole form, files
   * included, is kept in IndexedDB ('es-queue' → 'forms') and sent when the
   * network returns: by this page and by the service worker ('es-forms').
   * Queued forms are re-sent with the current CSRF token.
   */
  var queuedForms = 0;

  function countForms() {
    return photoStore('readonly', function (store) { return store.count(); }, 'forms')
      .then(function (count) { queuedForms = count || 0; showQueue(); }, function () {});
  }

  function formData(item) {
    var data = new FormData();
    item.fields.forEach(function (pair) { data.append(pair[0], pair[0] === '_token' && csrf ? csrf : pair[1]); });
    item.files.forEach(function (file) { data.append(file.field, file.blob, file.filename); });
    return data;
  }

  function sendForm(item) {
    return fetch(item.url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf || '' }, body: formData(item) });
  }

  function keepForm(item) {
    return photoStore('readwrite', function (store) { store.put(item); }, 'forms').then(function () {
      countForms();
      if (navigator.serviceWorker && navigator.serviceWorker.ready) {
        navigator.serviceWorker.ready.then(function (reg) { if (reg.sync) { return reg.sync.register('es-forms'); } }).catch(function () {});
      }
    });
  }

  function errorText(body, status) {
    if (body && body.errors) { return Object.keys(body.errors).map(function (k) { return body.errors[k][0]; }).join(' '); }
    return (body && body.message) || 'Could not send (' + status + ')';
  }

  var sendingForms = false;
  function flushForms() {
    if (sendingForms || !navigator.onLine) { return; }
    sendingForms = true;
    var notes = [];
    photoStore('readonly', function (store) { return store.getAll(); }, 'forms').then(function (items) {
      return (items || []).reduce(function (chain, item) {
        return chain.then(function () {
          return sendForm(item).then(function (response) {
            if (response.status >= 500 || response.status === 419) { throw new Error('retry later'); }
            return response.json().catch(function () { return {}; }).then(function (body) {
              notes.push(item.label + ': ' + (response.ok ? (body.message || 'sent ✓') : errorText(body, response.status)));
              return photoStore('readwrite', function (store) { store.delete(item.id); }, 'forms');
            });
          });
        });
      }, Promise.resolve());
    }).catch(function () {}).then(function () {
      sendingForms = false;
      countForms().then(function () {
        if (notes.length) { showQueue('Sent from this phone: ' + notes.join(' · ')); setTimeout(function () { showQueue(); }, 12000); }
      });
    });
  }

  function bindFieldForms() {
    document.querySelectorAll('form[data-field-form]').forEach(function (form) {
      if (form.dataset.bound) { return; }
      form.dataset.bound = '1';
      // Choosing a file clears a "photo needed" message.
      form.addEventListener('change', function (event) {
        var state = form.querySelector('[data-queue-state]');
        if (event.target.type === 'file' && state && state.classList.contains('failed')) { setState(form, '', ''); }
      });
      form.addEventListener('submit', function (event) {
        if (!window.fetch || !window.FormData) { return; } // plain form post
        event.preventDefault();
        var submitter = event.submitter;
        var buttons = form.querySelectorAll('button[type=submit]');
        var tooBig = form.querySelector('.too-big');
        if (tooBig) { setState(form, 'A video is too large: remove it or record a shorter one.', 'failed'); return; }
        // Some answers need proof (materials "Arrived"): a photo or video must be chosen first.
        var needsMedia = submitter && submitter.getAttribute('data-needs-media');
        var hasMedia = Array.prototype.some.call(form.querySelectorAll('input[type=file]'), function (input) { return input.files && input.files.length; });
        if (needsMedia && !hasMedia) { setState(form, needsMedia, 'failed'); return; }
        buttons.forEach(function (b) { b.disabled = true; });
        setState(form, 'Preparing…', 'queued');

        var fields = [];
        var files = [];
        new FormData(form).forEach(function (value, key) { if (typeof value === 'string') { fields.push([key, value]); } });
        if (submitter && submitter.name) { fields.push([submitter.name, submitter.value]); }
        var inputs = Array.prototype.slice.call(form.querySelectorAll('input[type=file]'));
        var pending = [];
        inputs.forEach(function (input) {
          Array.prototype.forEach.call(input.files || [], function (file) {
            pending.push(shrink(file).then(function (blob) {
              var image = /^image\//.test(file.type) && blob !== file;
              files.push({ field: input.name, blob: blob, filename: image ? (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg' : (file.name || 'video.mp4') });
            }));
          });
        });

        Promise.all(pending).then(function () {
          var item = { id: Date.now() + '-' + Math.random().toString(36).slice(2), url: form.getAttribute('action'), fields: fields, files: files, label: form.getAttribute('data-field-form') };
          var done = function () { buttons.forEach(function (b) { b.disabled = false; }); };
          var keep = function () {
            return keepForm(item).then(function () {
              setState(form, 'No network: saved on this phone. It will be sent when the network returns.', 'queued');
              form.reset();
              var previews = form.querySelector('[data-media-previews]');
              if (previews) { previews.innerHTML = ''; }
              done();
            }, function () {
              setState(form, 'No network, and this browser cannot keep the report. Try again when you have signal, or use USSD.', 'failed');
              done();
            });
          };

          if (!navigator.onLine) { return keep(); }
          setState(form, files.length ? 'Sending (large files can take a while)…' : 'Sending…', 'queued');
          return sendForm(item).then(function (response) {
            if (response.status >= 500) { return keep(); }
            return response.json().catch(function () { return {}; }).then(function (body) {
              if (response.ok) {
                setState(form, body.message || 'Sent ✓', 'sent');
                setTimeout(function () { if (body.url && body.url !== location.href) { location.href = body.url; } else { location.reload(); } }, 1400);
              } else {
                setState(form, response.status === 419 ? 'Your session expired: sign in again.' : errorText(body, response.status), 'failed');
                done();
              }
            });
          }, keep);
        });
      });
    });
  }

  // Previews of the chosen photos and videos, with a size check for videos.
  function bindMediaInputs() {
    document.querySelectorAll('[data-media-input]').forEach(function (box) {
      var input = box.querySelector('input[type=file]');
      var previews = box.querySelector('[data-media-previews]');
      if (!input || !previews || input.dataset.bound) { return; }
      input.dataset.bound = '1';
      var maxBytes = parseInt(box.getAttribute('data-max-mb'), 10) * 1048576;
      var maxFiles = parseInt(box.getAttribute('data-max-files'), 10);
      input.addEventListener('change', function () {
        previews.innerHTML = '';
        Array.prototype.slice.call(input.files || [], 0, 12).forEach(function (file, index) {
          var figure = document.createElement('figure');
          var video = /^video\//.test(file.type);
          var media = document.createElement(video ? 'video' : 'img');
          safe(function () { media.src = URL.createObjectURL(file); });
          if (video) { media.muted = true; media.preload = 'metadata'; }
          media.alt = '';
          var caption = document.createElement('figcaption');
          var mb = file.size / 1048576;
          caption.textContent = (video ? '🎬 ' : '') + (mb >= 1 ? mb.toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB');
          if ((video && file.size > maxBytes) || index >= maxFiles) {
            figure.className = 'too-big';
            caption.textContent = index >= maxFiles ? 'Too many files' : 'Too large (max ' + (maxBytes / 1048576) + ' MB)';
          }
          figure.appendChild(media);
          figure.appendChild(caption);
          previews.appendChild(figure);
        });
      });
    });
  }

  // Result form: running totals, and a warning when votes exceed accredited.
  function bindResultForms() {
    document.querySelectorAll('[data-result-form]').forEach(function (form) {
      var out = form.querySelector('[data-totals]');
      if (!out || form.dataset.totalsBound) { return; }
      form.dataset.totalsBound = '1';
      var num = function (el) { return el && el.value !== '' ? parseInt(el.value, 10) || 0 : 0; };
      var update = function () {
        var valid = 0;
        form.querySelectorAll('[data-vote]').forEach(function (el) { valid += num(el); });
        var rejected = num(form.querySelector('[data-rejected]'));
        var accredited = form.querySelector('[data-accredited]');
        var cast = valid + rejected;
        out.textContent = 'Valid votes ' + valid.toLocaleString() + ' · votes cast ' + cast.toLocaleString() + (accredited && accredited.value !== '' ? ' · accredited ' + num(accredited).toLocaleString() : '');
        var over = accredited && accredited.value !== '' && cast > num(accredited);
        out.className = 'totals small' + (over ? ' bad' : '');
        if (over) { out.textContent += ': more votes than accredited voters, check the figures.'; }
      };
      form.addEventListener('input', update);
    });
  }

  /*
   * Web Push, opted in per device on the Notifications page. The endpoint
   * is also put in the logout form, so logging out stops this device's alerts.
   */
  var pushKeyMeta = document.querySelector('meta[name="es-push-key"]');
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
  var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  function keyBytes(base64) {
    var padded = (base64 + '===='.slice((base64.length % 4) || 4)).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(padded);
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i); }
    return bytes;
  }

  function currentSubscription() {
    if (!pushSupported) { return Promise.resolve(null); }
    return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).catch(function () { return null; });
  }

  function postJson(url, data) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf || '' }, body: JSON.stringify(data) });
  }

  function bindPush() {
    currentSubscription().then(function (sub) {
      document.querySelectorAll('[data-push-endpoint]').forEach(function (input) { input.value = sub ? sub.endpoint : ''; });
    });

    var panel = document.querySelector('[data-push-panel]');
    if (!panel) { return; }
    var status = panel.querySelector('[data-push-status]');
    var buttons = {
      enable: panel.querySelector('[data-push-enable]'), save: panel.querySelector('[data-push-save]'),
      test: panel.querySelector('[data-push-test]'), disable: panel.querySelector('[data-push-disable]')
    };
    var topics = function () { return Array.prototype.filter.call(panel.querySelectorAll('[data-push-topic]'), function (c) { return c.checked; }).map(function (c) { return c.value; }); };
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    var say = function (text) { status.textContent = text; };
    var show = function (on) {
      buttons.enable.hidden = on; buttons.save.hidden = !on; buttons.test.hidden = !on; buttons.disable.hidden = !on;
    };

    if (!pushSupported || !pushKeyMeta) {
      say(ios ? 'Add Election Shield to your Home Screen first, then open it from there.' : 'This browser cannot receive notifications. Try Chrome on Android or a desktop browser.');
      if (ios) { panel.querySelector('[data-push-ios]').classList.add('show'); }
      return;
    }

    var register = function (sub) {
      var body = sub.toJSON();
      body.topics = topics();
      body.contentEncoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];
      return postJson('/push/subscribe', body).then(function (r) { if (!r.ok) { throw new Error('server ' + r.status); } });
    };

    currentSubscription().then(function (sub) {
      if (Notification.permission === 'denied') { say('Notifications are blocked for this site. Allow them in the browser settings, then reload.'); return; }
      show(!!sub);
      say(sub ? 'On for this device.' : 'Off for this device.');
      if (sub) { register(sub).catch(function () {}); } // keep the server copy fresh
    });

    buttons.enable.addEventListener('click', function () {
      if (!topics().length) { say('Choose at least one kind of alert.'); return; }
      buttons.enable.disabled = true;
      Notification.requestPermission().then(function (permission) {
        if (permission !== 'granted') { throw new Error('Permission not given'); }
        return navigator.serviceWorker.ready;
      }).then(function (reg) {
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(pushKeyMeta.content) });
      }).then(function (sub) {
        return register(sub).then(function () { show(true); say('On for this device. Try "Send a test".'); bindPush(); });
      }).catch(function (error) {
        say('Could not turn notifications on: ' + error.message);
      }).then(function () { buttons.enable.disabled = false; });
    });

    buttons.save.addEventListener('click', function () {
      if (!topics().length) { say('Choose at least one kind of alert, or turn notifications off.'); return; }
      currentSubscription().then(function (sub) { return sub && register(sub); }).then(function () { say('Saved.'); }, function () { say('Could not save. Check your connection.'); });
    });

    buttons.test.addEventListener('click', function () {
      say('Sending a test…');
      postJson('/push/test', {}).then(function (r) { say(r.ok ? 'Test sent. It should appear in a few seconds.' : 'The test could not be delivered.'); }, function () { say('No connection.'); });
    });

    buttons.disable.addEventListener('click', function () {
      currentSubscription().then(function (sub) {
        if (!sub) { return; }
        return postJson('/push/unsubscribe', { endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); });
      }).then(function () { show(false); say('Off for this device.'); bindPush(); }, function () { say('Could not turn off. Check your connection.'); });
    });
  }

  // Broadcast form: SMS part counter (same rules as BroadcastController::segments)
  // and switching between the SMS and WhatsApp fields.
  var GSM = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€';

  function smsParts(text) {
    var chars = Array.from(text);
    var unicode = chars.some(function (c) { return GSM.indexOf(c) === -1; });
    var length = unicode ? chars.length : chars.reduce(function (n, c) { return n + ('^{}\\[~]|€'.indexOf(c) === -1 ? 1 : 2); }, 0);
    var single = unicode ? 70 : 160, multi = unicode ? 67 : 153;
    return { length: length, parts: length === 0 ? 0 : (length <= single ? 1 : Math.ceil(length / multi)), unicode: unicode };
  }

  function bindBroadcastForm() {
    var box = document.querySelector('[data-sms-counter]');
    var out = document.querySelector('[data-sms-count]');
    if (box && out) {
      var update = function () {
        var p = smsParts(box.value);
        out.textContent = p.length + ' characters · ' + p.parts + ' SMS part' + (p.parts === 1 ? '' : 's') + (p.unicode ? ' (Unicode: emoji or special characters)' : '');
      };
      box.addEventListener('input', update);
      update();
    }

    var radios = document.querySelectorAll('[data-channel]');
    radios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        document.querySelectorAll('[data-channel-section]').forEach(function (section) {
          var on = section.getAttribute('data-channel-section') === radio.value;
          section.hidden = !on;
          section.querySelectorAll('textarea[name=message]').forEach(function (t) { t.disabled = !on; });
        });
      });
    });
  }

  // Town hall: load the stream only when tapped, and keep the approved
  // questions fresh on the public page (this runs for visitors too).
  function bindTownHall() {
    document.querySelectorAll('[data-embed]').forEach(function (player) {
      var start = player.querySelector('.player-start');
      if (!start) { return; }
      start.addEventListener('click', function () {
        var frame = document.createElement('iframe');
        frame.src = player.getAttribute('data-embed');
        frame.title = player.getAttribute('data-title') || 'Live stream';
        frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        frame.allowFullscreen = true;
        player.innerHTML = '';
        player.appendChild(frame);
      });
    });

    var feed = document.querySelector('[data-townhall-feed]');
    if (!feed || feed.hasAttribute('data-bound')) { return; }
    feed.setAttribute('data-bound', '1');

    var render = function (questions) {
      feed.innerHTML = '';
      if (!questions.length) {
        var empty = document.createElement('li');
        empty.className = 'muted small';
        empty.textContent = 'No questions yet. Be the first to ask.';
        feed.appendChild(empty);
      }
      questions.forEach(function (q) {
        var li = document.createElement('li');
        li.className = 'item' + (q.on_air ? ' urgent' : '');
        if (q.on_air || q.answered) {
          var badge = document.createElement('span');
          badge.className = 'badge ' + (q.on_air ? 'bad' : 'good');
          badge.textContent = q.on_air ? '● Being answered now' : '✓ Answered';
          li.appendChild(badge);
        }
        var body = document.createElement('p');
        body.className = 'note';
        body.style.margin = '6px 0';
        body.textContent = q.body;
        var who = document.createElement('div');
        who.className = 'meta';
        who.textContent = q.name + (q.lga ? ', ' + q.lga : '');
        li.appendChild(body);
        li.appendChild(who);
        feed.appendChild(li);
      });
    };

    setInterval(function () {
      if (document.hidden || !navigator.onLine) { return; }
      fetch(feed.getAttribute('data-townhall-feed'), { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (data) { render(data.questions); } })
        .catch(function () {});
    }, 20000);
  }

  // LGA map: switch layers without reloading (the links are the no-JS path).
  function bindMaps() {
    document.querySelectorAll('[data-lga-map]').forEach(function (map) {
      map.querySelectorAll('[data-map-layer]').forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          var key = link.getAttribute('data-map-layer');
          map.setAttribute('data-active', key);
          map.querySelectorAll('[data-map-layer]').forEach(function (l) {
            var on = l === link;
            l.classList.toggle('on', on);
            l.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          map.querySelectorAll('.tile').forEach(function (tile) {
            var cell = (safe(function () { return JSON.parse(tile.getAttribute('data-cells')); }) || {})[key];
            if (!cell) { return; }
            tile.className = 'tile ' + cell['class'];
            tile.querySelector('[data-tile-value]').textContent = cell.value;
            tile.title = tile.querySelector('.tile-name').textContent + ': ' + cell.title;
          });
          map.querySelectorAll('[data-map-legend]').forEach(function (legend) { legend.hidden = legend.getAttribute('data-map-legend') !== key; });
        });
      });
    });
  }

  function bindMain() { bindRows(); bindGuide(); bindQueue(); bindFilters(); bindBusyForms(); bindPhotos(); bindLocationForms(); bindFieldForms(); bindMediaInputs(); bindResultForms(); bindPush(); bindBroadcastForm(); bindTownHall(); bindMaps(); }

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

  /*
   * Situation-room pop-ups: new results and incidents nobody has answered,
   * fetched every 20 seconds. Acknowledge / resolve answer for everyone;
   * "Remind me" and closing the pop-up snooze it for this person only, and
   * an unanswered report comes back after the repeat interval.
   */
  var alertBox = document.querySelector('[data-alert-pop]');
  var alertUrl = body.getAttribute('data-alerts');
  var snoozeUrl = body.getAttribute('data-snooze-url');
  var alertItems = [];
  var alertRepeat = 5;
  var alertHidden = {};
  var alertSeen = {};
  var baseTitle = document.title;

  function esc(text) { return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function postJson(url, data) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(data || {}) });
  }

  function chime(urgent) {
    // Browsers only allow sound and vibration after the person has tapped the page.
    if (navigator.userActivation && !navigator.userActivation.hasBeenActive) { return; }
    safe(function () { if (navigator.vibrate) { navigator.vibrate(urgent ? [300, 120, 300, 120, 300] : [150]); } });
    safe(function () {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) { return; }
      var ctx = new Ctx();
      [0, urgent ? 0.25 : null].forEach(function (at) {
        if (at === null) { return; }
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.frequency.value = urgent ? 880 : 660;
        gain.gain.setValueAtTime(0.0001, ctx.currentTime + at);
        gain.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.2);
        osc.connect(gain); gain.connect(ctx.destination);
        osc.start(ctx.currentTime + at); osc.stop(ctx.currentTime + at + 0.22);
      });
    });
  }

  function visibleAlerts() {
    var now = Date.now();
    return alertItems.filter(function (item) { return !(alertHidden[item.reference] > now); });
  }

  function renderAlert() {
    if (!alertBox) { return; }
    var items = visibleAlerts();
    document.title = items.length ? '(' + items.length + ') ' + baseTitle : baseTitle;
    if (!items.length) { alertBox.hidden = true; alertBox.innerHTML = ''; return; }
    var item = items[0];
    var fresh = !alertSeen[item.reference];
    alertSeen[item.reference] = true;

    var details = '';
    if (item.kind === 'result') {
      var votes = Object.keys(item.votes || {}).map(function (party) { return '<span><b>' + esc(party) + '</b> ' + Number(item.votes[party]).toLocaleString() + '</span>'; }).join('');
      details = '<div class="pop-votes">' + votes + '</div><p class="small muted">Accredited ' + Number(item.accredited).toLocaleString() + ' · rejected ' + Number(item.rejected).toLocaleString() + '</p>';
    } else if (item.note) {
      details = '<p class="pop-note">“' + esc(item.note) + '”</p>';
    }

    alertBox.className = 'alert-pop' + (item.urgent ? ' urgent' : '') + (item.kind === 'result' ? ' result' : '');
    alertBox.innerHTML =
      '<header><span class="pop-kind">' + esc(item.heading) + '</span><span class="pop-count">' + (items.length > 1 ? '1 of ' + items.length : '') + '</span>' +
      '<button type="button" class="pop-close" data-pop="close" aria-label="Close (comes back in ' + alertRepeat + ' minutes)">✕</button></header>' +
      '<h2 id="alert-pop-title">' + esc(item.title) + '</h2>' +
      '<p class="pop-place"><b>' + esc(item.place) + '</b><br><span class="small muted">' + esc([item.lga, item.ward].filter(Boolean).join(' › ')) + ' · PU ' + esc(item.code) + ' · ' + esc(item.reference) + '</span></p>' +
      details +
      '<p class="pop-agent small"><span class="badge ' + (item.channel === 'Web app' ? 'channel-web' : '') + '">via ' + esc(item.channel) + '</span> ' +
        esc(item.agent || 'Agent') + (item.phone ? ' · <a href="tel:' + esc(item.phone) + '">' + esc(item.phone) + '</a>' : '') + (item.when ? ' · ' + esc(item.when) : '') +
        (item.media ? ' · 📎 ' + item.media + ' file' + (item.media > 1 ? 's' : '') : '') + '</p>' +
      '<div class="pop-resolve" data-pop-resolve hidden><label for="pop-note">What was done (optional)</label><textarea id="pop-note" maxlength="1000" rows="2"></textarea></div>' +
      '<div class="pop-actions">' +
        '<button type="button" class="button" data-pop="ack">Acknowledge</button>' +
        (item.kind === 'incident' ? '<button type="button" class="button secondary" data-pop="resolve">Resolve…</button>' : '') +
        (item.review ? '<a class="button secondary" href="' + esc(item.review) + '">Review</a>' : '<a class="button secondary" href="' + esc(item.open) + '">Open</a>') +
        '<label class="pop-remind"><span class="sr-only">Remind me in</span><select data-pop="remind"><option value="">Remind me…</option><option value="5">in 5 min</option><option value="15">in 15 min</option><option value="30">in 30 min</option><option value="60">in 1 hour</option></select></label>' +
      '</div><p class="pop-state small" data-pop-state aria-live="polite"></p>';
    alertBox.hidden = false;
    if (fresh) { chime(item.urgent); }

    var state = alertBox.querySelector('[data-pop-state]');
    var done = function (message) {
      alertItems = alertItems.filter(function (i) { return i.reference !== item.reference; });
      if (message) { showQueue(message); setTimeout(function () { showQueue(); }, 3000); }
      renderAlert();
    };
    var fail = function () { state.textContent = navigator.onLine ? 'Could not save: try again.' : 'Offline: try again when you are back online.'; };
    var snooze = function (minutes) {
      alertHidden[item.reference] = Date.now() + minutes * 60000;
      postJson(snoozeUrl, { reference: item.reference, minutes: minutes }).catch(function () {});
      renderAlert();
    };

    alertBox.querySelector('[data-pop="close"]').addEventListener('click', function () { snooze(alertRepeat); });
    alertBox.querySelector('[data-pop="ack"]').addEventListener('click', function () {
      state.textContent = 'Saving…';
      postJson(item.acknowledge).then(function (r) { if (!r.ok) { throw new Error(); } done('Acknowledged ' + item.reference + ' ✓'); refresh(true); }).catch(fail);
    });
    var resolve = alertBox.querySelector('[data-pop="resolve"]');
    if (resolve) {
      resolve.addEventListener('click', function () {
        var box = alertBox.querySelector('[data-pop-resolve]');
        if (box.hidden) { box.hidden = false; resolve.textContent = 'Mark resolved'; box.querySelector('textarea').focus(); return; }
        state.textContent = 'Saving…';
        postJson(item.resolve, { resolution_note: box.querySelector('textarea').value }).then(function (r) { if (!r.ok) { throw new Error(); } done('Resolved ' + item.reference + ' ✓'); refresh(true); }).catch(fail);
      });
    }
    alertBox.querySelector('[data-pop="remind"]').addEventListener('change', function (event) {
      var minutes = parseInt(event.target.value, 10);
      if (minutes) { snooze(minutes); }
    });
  }

  function pollAlerts() {
    if (!alertUrl || !alertBox || !navigator.onLine) { return; }
    fetch(alertUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); })
      .then(function (data) {
        alertRepeat = data.repeat_minutes || 5;
        alertItems = data.items || [];
        // Don't swap the pop-up from under someone typing a resolution note.
        if (alertBox.contains(document.activeElement) && document.activeElement.tagName === 'TEXTAREA') { return; }
        renderAlert();
      })
      .catch(function () {});
  }

  if (alertUrl) {
    pollAlerts();
    setInterval(pollAlerts, 20000);
    setInterval(renderAlert, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { pollAlerts(); } });
  }

  /*
   * Location, for roles with "Location is recorded" (body[data-location]).
   * Asked for when the app opens and kept fresh while it is open. The last
   * position goes with every action (X-ES-Location header on fetch posts, a
   * _es_location field on plain form posts), and a ping is sent when the app
   * opens and every 10 minutes, or "denied" when location was refused.
   * Check-in (form[data-needs-location]) does not work without a position:
   * it uses the recent one, or asks again.
   */
  var locationUrl = body.getAttribute('data-location');
  var geo = navigator.geolocation;
  var lastFix = locationUrl ? safe(function () { return JSON.parse(sessionStorage.getItem('es-fix') || 'null'); }) || null : null;
  var locationState = '';
  var FIX_FRESH_MS = 120000;
  var PING_EVERY_MS = 600000;

  function locationValue() {
    if (lastFix) { return [lastFix.lat, lastFix.lng, Math.round(lastFix.acc || 0), lastFix.at].join(','); }
    return locationState;
  }

  function storeFix(position) {
    lastFix = { lat: position.coords.latitude, lng: position.coords.longitude, acc: position.coords.accuracy, at: position.timestamp || Date.now() };
    locationState = '';
    safe(function () { sessionStorage.setItem('es-fix', JSON.stringify(lastFix)); });
  }

  function locationFailed(error) {
    locationState = error && error.code === 1 ? 'denied' : 'unavailable';
    if (locationState === 'denied') { lastFix = null; safe(function () { sessionStorage.removeItem('es-fix'); }); }
  }

  function pingLocation(action) {
    var data = lastFix
      ? { status: 'ok', latitude: lastFix.lat, longitude: lastFix.lng, accuracy: Math.round(lastFix.acc || 0), located_at: Math.round(lastFix.at), action: action }
      : { status: locationState || 'unavailable', action: action };
    safe(function () { sessionStorage.setItem('es-ping-at', String(Date.now())); });
    postJson(locationUrl, data).catch(function () {});
  }

  function maybePing() {
    if (!navigator.onLine) { return; }
    if (!safe(function () { return sessionStorage.getItem('es-opened'); })) {
      safe(function () { sessionStorage.setItem('es-opened', '1'); });
      pingLocation('opened the app');
      return;
    }
    var last = parseInt(safe(function () { return sessionStorage.getItem('es-ping-at'); }) || '0', 10);
    if (Date.now() - last > PING_EVERY_MS) { pingLocation('using the app'); }
  }

  // A position for check-in: the recent one, or ask the phone (and the person, if they have not allowed it yet).
  function currentFix() {
    if (lastFix && Date.now() - lastFix.at < FIX_FRESH_MS) { return Promise.resolve(lastFix); }
    return new Promise(function (resolve, reject) {
      if (!geo) { reject(new Error('no geolocation')); return; }
      geo.getCurrentPosition(function (position) { storeFix(position); resolve(lastFix); }, function (error) { locationFailed(error); reject(error); }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 30000 });
    });
  }

  function hiddenField(form, name, value) {
    var input = form.querySelector('input[type=hidden][name="' + name + '"]');
    if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.appendChild(input); }
    input.value = value;
  }

  function bindLocationForms() {
    document.querySelectorAll('form[data-needs-location]').forEach(function (form) {
      if (form.dataset.locationBound) { return; }
      form.dataset.locationBound = '1';
      // Capture, so it runs before the form's own submit handler.
      form.addEventListener('submit', function (event) {
        if (form.dataset.located === '1') { form.dataset.located = ''; return; }
        event.preventDefault();
        event.stopImmediatePropagation();
        var submitter = event.submitter;
        setState(form, 'Checking in…', 'queued');
        currentFix().then(function (fix) {
          hiddenField(form, 'latitude', fix.lat);
          hiddenField(form, 'longitude', fix.lng);
          hiddenField(form, 'location_accuracy', Math.round(fix.acc || 0));
          hiddenField(form, 'located_at', Math.round(fix.at));
          form.dataset.located = '1';
          // After this submit event is over: the browser ignores a submit started during one.
          setTimeout(function () { if (form.requestSubmit) { form.requestSubmit(submitter || undefined); } else { form.submit(); } }, 0);
        }, function (error) {
          setState(form, error && error.code === 1
            ? 'Location is needed to check in. Allow location for this site (tap the lock or ⓘ next to the address, or your phone\'s settings), then try again.'
            : 'Could not get your location. Turn on location (GPS) on your phone and try again.', 'failed');
        });
      }, true);
    });
  }

  if (locationUrl) {
    if (window.fetch) {
      var plainFetch = window.fetch.bind(window);
      window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var value = locationValue();
        if (init && String(init.method || 'GET').toUpperCase() === 'POST' && value && new URL(url, location.href).origin === location.origin) {
          var headers = new Headers(init.headers || {});
          headers.set('X-ES-Location', value);
          init = Object.assign({}, init, { headers: headers });
        }
        return plainFetch(input, init);
      };
    }
    document.addEventListener('submit', function (event) {
      var form = event.target;
      var value = locationValue();
      if (value && form.method && form.method.toLowerCase() === 'post') { hiddenField(form, '_es_location', value); }
    }, true);

    if (geo) {
      var firstAnswer = true;
      var answered = function () { if (firstAnswer) { firstAnswer = false; maybePing(); } };
      geo.watchPosition(function (position) { storeFix(position); answered(); }, function (error) { locationFailed(error); answered(); }, { enableHighAccuracy: true, maximumAge: 60000 });
      // No answer at all (the prompt is still open, or GPS is slow): still note the visit.
      setTimeout(answered, 30000);
    } else {
      locationState = 'unavailable';
      maybePing();
    }
    setInterval(maybePing, 60000);
  }

  bindMain();
  showQueue();
  flushQueue();
  countPhotos();
  flushPhotos();
  countForms();
  flushForms();
})();
