{{-- Tabs shared by the location pages. --}}
<nav class="tabs" aria-label="Locations">
    <a href="{{ route('locations.people', ['range' => request('range')]) }}" class="{{ request()->routeIs('locations.people', 'locations.person') ? 'on' : '' }}">People map</a>
    <a href="{{ route('locations') }}" class="{{ request()->routeIs('locations') ? 'on' : '' }}">Agents' check-ins</a>
</nav>
