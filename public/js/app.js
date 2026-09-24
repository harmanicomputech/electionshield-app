/* Election Shield: service worker, offline banner, live refresh, offline action queue, install prompt. */
(function () {
  'use strict';

  var body = document.body;
  var loggedIn = body.hasAttribute('data-cache-pages');
  var meta = document.querySelector('meta[name="es-generated-at"]');
  var tz = (document.querySelector('meta[name="es-timezone"]') || {}).content || 'Africa/Lagos';
  var banner = document.querySelector('[data-offline-banner]');
  var LIVE_PAGES = [/^\/$/, /^\/spread$/, /^\/collation(\/.*)?$/, /^\/monitor(\/.*)?$/, /^\/incidents$/, /^\/manage\/townhall\/[^/]+$/];
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

  window.addEventListener('online', function () { showStatus(); flushQueue(); flushPhotos(); refresh(); });
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
   * (Read the URL with getAttribute: a field named "action" hides form.action.)
   */
  function readQueue() { return safe(function () { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }) || []; }
  function writeQueue(items) { safe(function () { localStorage.setItem(QUEUE_KEY, JSON.stringify(items)); }); showQueue(); }

  function showQueue(message) {
    var el = document.querySelector('[data-queue-banner]');
    if (!el) { return; }
    var count = readQueue().length + queuedPhotos;
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
      var request = indexedDB.open('es-queue', 1);
      request.onupgradeneeded = function () { request.result.createObjectStore('photos', { keyPath: 'id' }); };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
  }

  function photoStore(mode, fn) {
    return idb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction('photos', mode);
        var result = fn(tx.objectStore('photos'));
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

  function bindMain() { bindRows(); bindGuide(); bindQueue(); bindFilters(); bindPhotos(); bindPush(); bindBroadcastForm(); bindTownHall(); }

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
  countPhotos();
  flushPhotos();
})();
