<nav class="tabs" aria-label="Official results">
    <a href="{{ route('compare') }}" class="{{ request()->routeIs('compare', 'compare.*') ? 'on' : '' }}">Comparison</a>
    <a href="{{ route('official') }}" class="{{ request()->routeIs('official', 'official.pu') ? 'on' : '' }}">Enter IReV result</a>
    <a href="{{ route('official.collations') }}" class="{{ request()->routeIs('official.collations', 'official.collation') ? 'on' : '' }}">Enter EC8B / EC8C</a>
    @if (auth()->user()->isAdmin())
        <a href="{{ route('official.import') }}" class="{{ request()->routeIs('official.import') ? 'on' : '' }}">Import CSV</a>
    @endif
</nav>
