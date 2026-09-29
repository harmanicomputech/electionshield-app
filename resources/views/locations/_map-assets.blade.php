{{-- The map library (public/vendor/leaflet) and the map script. A missing file must not break the page: the list still works. --}}
@php($mapScript = public_path('js/locations-map.js'))
<link rel="stylesheet" href="/vendor/leaflet/leaflet.css?v=1.9.4">
<script src="/vendor/leaflet/leaflet.js?v=1.9.4" defer></script>
<script src="/js/locations-map.js?v={{ is_file($mapScript) ? filemtime($mapScript) : 0 }}" defer></script>
