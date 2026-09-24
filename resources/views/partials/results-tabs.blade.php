<nav class="tabs" aria-label="Results">
    <a href="{{ route('collation') }}" class="{{ request()->routeIs('collation') ? 'on' : '' }}">Collation by LGA</a>
    <a href="{{ route('spread') }}" class="{{ request()->routeIs('spread') ? 'on' : '' }}">25% rule</a>
</nav>
