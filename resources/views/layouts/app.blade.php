@php
    $user = auth()->user();
    $tabs = [
        ['dashboard', 'Dashboard', 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z'],
        ['spread', '25% rule', 'M4 20V10m6 10V4m6 16v-7m4 7H2'],
        ['collation', 'Collation', 'M4 6h16M4 12h16M4 18h10'],
    ];
    $active = fn (string $name) => request()->routeIs($name, $name.'.*') ? 'on' : '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0f6e4f">
    <meta name="es-generated-at" content="{{ now()->toIso8601String() }}">
    <meta name="es-timezone" content="{{ config('election.timezone') }}">
    <title>@yield('title') · Election Shield</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon-32.png" sizes="32x32">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Election Shield">
    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
    <script src="/js/app.js?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</head>
<body @if ($user) data-cache-pages="1" @endif>
    <header class="topbar">
        <div class="wrap">
            <a class="brand" href="{{ $user ? route('dashboard') : route('login') }}"><img src="/icons/icon-192.png" alt="">Election Shield</a>
            @if ($user)
                <nav class="topnav" aria-label="Main">
                    @foreach ($tabs as [$name, $label])
                        <a href="{{ route($name) }}" class="{{ $active($name) }}">{{ $label }}</a>
                    @endforeach
                    @if ($user->isAdmin())
                        <a href="{{ route('system') }}" class="{{ $active('system') }}">System</a>
                        <a href="{{ route('users') }}" class="{{ $active('users') }}">Users</a>
                        <a href="{{ route('audit') }}" class="{{ $active('audit') }}">Audit log</a>
                    @endif
                    <span class="spacer"></span>
                    <button type="button" data-install hidden>Install app</button>
                    <form method="post" action="{{ route('logout') }}" data-logout>@csrf<button type="submit">Log out</button></form>
                </nav>
            @endif
        </div>
    </header>

    @if ($showingRehearsal && $user)
        <div class="banner rehearsal" role="status">Rehearsal data: these are not real results.</div>
    @endif
    <div class="banner offline" data-offline-banner hidden role="status"></div>

    <main class="wrap" id="main">
        @if (session('status'))<div class="flash ok" role="status">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="flash bad" role="alert">{{ session('error') }}</div>@endif

        @yield('content')

        @if ($user)
            <div class="card install-guide" data-ios-guide>
                <h2>Add Election Shield to your Home Screen</h2>
                <p class="small">In Safari, tap the Share button <span aria-hidden="true">⬆︎</span>, then <b>Add to Home Screen</b>. Open it from there for full-screen use and alerts.</p>
                <button type="button" class="button secondary" data-dismiss>Got it</button>
            </div>
            <p class="footer">Page loaded {{ \App\Support\Time::local(now()) }} · data last received from USSD {{ $lastData ? \App\Support\Time::local($lastData, 'j M, g:i A') : 'never' }}</p>
        @endif
    </main>

    @if ($user)
        <nav class="tabbar" aria-label="Main">
            @foreach ($tabs as [$name, $label, $path])
                <a href="{{ route($name) }}" class="{{ $active($name) }}" @if ($active($name)) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}"/></svg>
                    {{ $label }}
                </a>
            @endforeach
            <details>
                <summary>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h.01M12 12h.01M19 12h.01"/></svg>
                    More
                </summary>
                <div class="menu">
                    @if ($user->isAdmin())
                        <a href="{{ route('system') }}">System &amp; sync</a>
                        <a href="{{ route('users') }}">Users</a>
                        <a href="{{ route('audit') }}">Audit log</a>
                        <hr>
                    @endif
                    <button type="button" data-install hidden>Install app</button>
                    <form method="post" action="{{ route('logout') }}" data-logout>@csrf<button type="submit">Log out ({{ $user->name }})</button></form>
                </div>
            </details>
        </nav>
    @endif
</body>
</html>
