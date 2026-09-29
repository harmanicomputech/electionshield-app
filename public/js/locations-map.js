/* Election Shield: the People map and a person's movement map (Leaflet, /vendor/leaflet). */
(function () {
  'use strict';

  var TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  var ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';

  function esc(text) {
    return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  function cssVar(name, fallback) {
    var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
  }

  function colours() {
    return {
      agent: cssVar('--slot1', '#2a78d6'),
      coordinator: cssVar('--slot2', '#eb6834'),
      other: cssVar('--slot4', '#eda100'),
      outside: cssVar('--bad', '#b42318'),
      start: cssVar('--good', '#067647'),
      end: cssVar('--text', '#0b0b0b'),
      ring: cssVar('--surface', '#ffffff'),
      line: cssVar('--text-2', '#52514e')
    };
  }

  function baseMap(el, data) {
    var b = data.bounds || [5.4, 7.0, 7.3, 8.6];
    var state = L.latLngBounds([b[0], b[2]], [b[1], b[3]]);
    var map = L.map(el, { scrollWheelZoom: false, preferCanvas: true, zoomSnap: 0.5 });
    L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION, crossOrigin: false }).addTo(map);
    // Zoom with the wheel only after the map is clicked, so the page still scrolls.
    map.on('click', function () { map.scrollWheelZoom.enable(); });
    map.on('mouseout', function () { map.scrollWheelZoom.disable(); });
    map.fitBounds(state);
    return { map: map, state: state };
  }

  function peopleMap(el, data) {
    var c = colours();
    var base = baseMap(el, data);
    var map = base.map;
    var markers = {};
    var points = [];

    (data.markers || []).forEach(function (m) {
      var colour = m.outside ? c.outside : (c[m.group] || c.other);
      var marker = L.circleMarker([m.lat, m.lng], { radius: 8, color: c.ring, weight: 2, fillColor: colour, fillOpacity: 0.95 }).addTo(map);
      marker.bindPopup(
        '<div class="map-pop"><b>' + esc(m.name) + '</b><br><span class="muted">' + esc(m.role) + (m.lga ? ' · ' + esc(m.lga) : '') + '</span>' +
        '<p>' + esc(m.when) + ' (' + esc(m.ago) + ')<br>' + esc(m.action) + (m.acc != null ? ' · ±' + esc(m.acc) + ' m' : '') + '</p>' +
        (m.outside ? '<p class="pop-flag">Outside the state</p>' : '') +
        (m.phone ? '<a href="tel:' + esc(m.phone) + '">Call ' + esc(m.phone) + '</a><br>' : '') +
        '<a href="' + esc(m.url) + '">Location history →</a></div>'
      );
      marker.bindTooltip(esc(m.name), { direction: 'top', offset: [0, -8] });
      markers[m.id] = marker;
      points.push([m.lat, m.lng]);
    });

    if (points.length) { map.fitBounds(L.latLngBounds(points).pad(0.15), { maxZoom: 15 }); }

    document.querySelectorAll('[data-focus-person]').forEach(function (button) {
      button.addEventListener('click', function () {
        var marker = markers[button.getAttribute('data-focus-person')];
        if (!marker) { return; }
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 15));
        marker.openPopup();
      });
    });
  }

  function personMap(el, data) {
    var c = colours();
    var base = baseMap(el, data);
    var map = base.map;
    var path = data.path || [];
    var colour = c[data.group] || c.other;
    var byId = {};
    var bounds = [];

    if (data.unit) {
      var pu = L.marker([data.unit.lat, data.unit.lng], {
        icon: L.divIcon({ className: 'pu-pin', html: '<span></span>', iconSize: [18, 18], iconAnchor: [9, 9] }),
        title: 'PU ' + data.unit.name
      }).addTo(map);
      pu.bindPopup('<div class="map-pop"><b>Their polling unit</b><br>' + esc(data.unit.name) + '<br><span class="muted">' + esc(data.unit.code) + '</span></div>');
      bounds.push([data.unit.lat, data.unit.lng]);
    }

    if (path.length > 1) {
      L.polyline(path.map(function (p) { return [p.lat, p.lng]; }), { color: colour, weight: 3, opacity: 0.55 }).addTo(map);
    }

    path.forEach(function (p, i) {
      var first = i === 0;
      var last = i === path.length - 1;
      var marker = L.circleMarker([p.lat, p.lng], {
        radius: first || last ? 9 : 5,
        color: c.ring,
        weight: 2,
        fillColor: last ? c.end : (first ? c.start : colour),
        fillOpacity: 0.95
      }).addTo(map);
      marker.bindPopup('<div class="map-pop"><b>' + (last ? 'Latest · ' : (first ? 'First · ' : '')) + esc(p.when) + '</b><br>' + esc(p.action) +
        (p.acc != null ? '<br><span class="muted">GPS within ' + esc(p.acc) + ' m</span>' : '') +
        '<br><a href="https://www.google.com/maps?q=' + p.lat + ',' + p.lng + '" target="_blank" rel="noopener">Google Maps</a></div>');
      byId[p.id] = marker;
      bounds.push([p.lat, p.lng]);
      if (last && p.acc) { L.circle([p.lat, p.lng], { radius: p.acc, color: c.end, weight: 1, fillOpacity: 0.08 }).addTo(map); }
      if (last) { marker.bringToFront(); }
    });

    if (bounds.length) { map.fitBounds(L.latLngBounds(bounds).pad(0.2), { maxZoom: 16 }); }

    var focus = function (item) {
      var marker = byId[item.getAttribute('data-point')];
      if (!marker) { return; }
      document.querySelectorAll('.timeline-item.on').forEach(function (other) { other.classList.remove('on'); });
      item.classList.add('on');
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      map.setView(marker.getLatLng(), Math.max(map.getZoom(), 16));
      marker.openPopup();
    };
    document.querySelectorAll('[data-point]').forEach(function (item) {
      item.addEventListener('click', function (event) { if (event.target.closest('a')) { return; } focus(item); });
      item.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); focus(item); } });
    });
  }

  function init() {
    document.querySelectorAll('[data-people-map]').forEach(function (el) {
      var source = document.getElementById(el.getAttribute('data-source'));
      if (!source) { return; }
      if (!window.L) {
        el.classList.add('map-failed');
        el.textContent = 'The map could not load. The list below still works.';
        return;
      }
      var data = JSON.parse(source.textContent || '{}');
      if (data.path) { personMap(el, data); } else { peopleMap(el, data); }
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
