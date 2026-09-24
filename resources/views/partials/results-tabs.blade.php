<nav class="tabs" aria-label="Results">
    <a href="{{ route('collation') }}" class="{{ request()->routeIs('collation') ? 'on' : '' }}">Collation by LGA</a>
    <a href="{{ route('spread') }}" class="{{ request()->routeIs('spread') ? 'on' : '' }}">25% rule</a>
    <a href="{{ route('photos') }}" class="{{ request()->routeIs('photos', 'photos.*') ? 'on' : '' }}">EC8A photos</a>
    <a href="{{ route('compare') }}" class="{{ request()->routeIs('compare', 'compare.*', 'official', 'official.*') ? 'on' : '' }}">Official vs PVT</a>
</nav>
