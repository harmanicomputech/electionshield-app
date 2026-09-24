<nav class="tabs" aria-label="Broadcasts">
    <a href="{{ route('broadcasts') }}" class="{{ request()->routeIs('broadcasts', 'broadcasts.*') ? 'on' : '' }}">Broadcasts</a>
    <a href="{{ route('contacts') }}" class="{{ request()->routeIs('contacts') ? 'on' : '' }}">Contacts &amp; consent</a>
    <a href="{{ route('join') }}">Public sign-up page ↗</a>
</nav>
