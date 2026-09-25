@php
    $user = auth()->user();
    // [route, label, icon path, routes it covers]
    $tabs = [
        ['dashboard', 'Dashboard', 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z', ['dashboard']],
        ['incidents', 'Incidents', 'M12 3 2 20h20L12 3Zm0 6v5m0 3h.01', ['incidents']],
        ['monitor', 'PUs', 'M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Zm0-9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z', ['monitor', 'monitor.*']],
        ['collation', 'Results', 'M4 20V10m6 10V4m6 16v-7m4 7H2', ['collation', 'collation.*', 'spread']],
    ];
    $active = fn (string|array $names) => request()->routeIs(...(array) $names) ? 'on' : '';

    // Desktop sidebar: [section, [[route, label, icon path, routes it covers], ...]]
    $icon = [
        'collation' => 'M4 6h16M4 12h16M4 18h10',
        'spread' => 'M4 20V10m6 10V4m6 16v-7m4 7H2',
        'compare' => 'M12 3v18M5 7h14M5 7l-3 7a3 3 0 0 0 6 0L5 7Zm14 0-3 7a3 3 0 0 0 6 0l-3-7Z',
        'photos' => 'M4 8h3l2-3h6l2 3h3v11H4V8Zm8 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
        'townhall' => 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Zm-7 9a7 7 0 0 0 14 0M12 19v3',
        'broadcasts' => 'M3 11v2a1 1 0 0 0 1 1h3l6 5V5L7 10H4a1 1 0 0 0-1 1Zm14-3a5 5 0 0 1 0 8',
        'system' => 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4',
        'users' => 'M16 20v-2a4 4 0 0 0-8 0v2M12 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
        'audit' => 'M9 4h6l1 2h3v14H5V6h3l1-2Zm-1 8h8m-8 4h5',
        'push' => 'M6 16v-5a6 6 0 1 1 12 0v5l2 2H4l2-2Zm4 4h4',
        'install' => 'M12 3v12m-5-5 5 5 5-5M5 21h14',
        'logout' => 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11',
    ];
    $sections = $user ? array_filter([
        ['Election day', [
            [$tabs[0][0], $tabs[0][1], $tabs[0][2], $tabs[0][3]],
            [$tabs[1][0], $tabs[1][1], $tabs[1][2], $tabs[1][3]],
            ['monitor', 'Polling units', $tabs[2][2], $tabs[2][3]],
        ]],
        ['Results', [
            ['collation', 'Collation', $icon['collation'], ['collation', 'collation.*']],
            ['spread', '25% rule', $icon['spread'], ['spread']],
            ['compare', 'Official vs PVT', $icon['compare'], ['compare', 'compare.*', 'official', 'official.*']],
            ['photos', 'EC8A photos', $icon['photos'], ['photos', 'photos.*']],
        ]],
        ['Engage', array_values(array_filter([
            ['townhall.manage', 'Town hall', $icon['townhall'], ['townhall.manage', 'townhall.moderate', 'townhall.create', 'townhall.edit']],
            $user->isAdmin() ? ['broadcasts', 'Broadcasts', $icon['broadcasts'], ['broadcasts', 'broadcasts.*', 'contacts']] : null,
        ]))],
        $user->isAdmin() ? ['Admin', [
            ['system', 'System & sync', $icon['system'], ['system']],
            ['users', 'Users', $icon['users'], ['users']],
            ['audit', 'Audit log', $icon['audit'], ['audit']],
        ]] : null,
    ]) : [];
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
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($user && ($pushKey = app(\App\Services\PushNotifier::class)->publicKey()))<meta name="es-push-key" content="{{ $pushKey }}">@endif
    <title>@yield('title') · Election Shield</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon-32.png" sizes="32x32">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Election Shield">
    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
    <script src="/js/app.js?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</head>
<body @class(['has-sidebar' => $user]) @if ($user) data-cache-pages="1" @endif>
    @if ($user)
        <aside class="sidebar" aria-label="Main">
            <a class="side-brand" href="{{ route('dashboard') }}"><img src="/icons/icon-192.png" alt="" width="36" height="36"><span>Election Shield<small>Ebonyi {{ \Illuminate\Support\Carbon::parse(config('election.date'))->format('Y') }}</small></span></a>
            <nav class="side-nav">
                @foreach ($sections as [$heading, $items])
                    <p class="side-heading">{{ $heading }}</p>
                    @foreach ($items as [$name, $label, $path, $covers])
                        <a href="{{ route($name) }}" class="{{ $active($covers) }}" @if ($active($covers)) aria-current="page" @endif @if ($name === 'incidents') data-live-id="side-incidents" @endif>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}"/></svg>
                            <span>{{ $label }}</span>
                            @if ($name === 'incidents' && $urgentOpen)<span class="count" aria-label="{{ $urgentOpen }} urgent open">{{ $urgentOpen }}</span>@endif
                        </a>
                    @endforeach
                @endforeach
            </nav>
            <div class="side-foot">
                <a href="{{ route('push') }}" class="{{ $active('push') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon['push'] }}"/></svg><span>Notifications</span></a>
                <button type="button" data-install hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon['install'] }}"/></svg><span>Install app</span></button>
                <div class="side-user">
                    <span class="avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span>
                    <span class="who">{{ $user->name }}<small>{{ $user->role->label() }}</small></span>
                    <form method="post" action="{{ route('logout') }}" data-logout>@csrf<input type="hidden" name="push_endpoint" data-push-endpoint><button type="submit" title="Log out" aria-label="Log out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon['logout'] }}"/></svg></button></form>
                </div>
            </div>
        </aside>
    @endif
    <header class="topbar">
        <div class="wrap">
            <a class="brand" href="{{ $user ? route('dashboard') : route('login') }}"><img src="/icons/icon-192.png" alt="">Election Shield</a>
            @if ($user)
                <nav class="topnav" aria-label="Main">
                    @foreach ($tabs as [$name, $label, $path, $covers])
                        <a href="{{ route($name) }}" class="{{ $active($covers) }}" data-live-id="top-{{ $name }}">{{ $label }}@if ($name === 'incidents' && $urgentOpen)<span class="count" aria-label="{{ $urgentOpen }} urgent open">{{ $urgentOpen }}</span>@endif</a>
                    @endforeach
                    <a href="{{ route('townhall.manage') }}" class="{{ $active(['townhall.manage', 'townhall.moderate', 'townhall.create', 'townhall.edit']) }}">Town hall</a>
                    <span class="spacer"></span>
                    <details class="top-more">
                        <summary>More ▾</summary>
                        <div class="menu">
                            @if ($user->isAdmin())
                                <a href="{{ route('system') }}">System &amp; sync</a>
                                <a href="{{ route('users') }}">Users</a>
                                <a href="{{ route('audit') }}">Audit log</a>
                                <a href="{{ route('broadcasts') }}">Broadcasts</a>
                                <hr>
                            @endif
                            <a href="{{ route('push') }}">Notifications</a>
                            <button type="button" data-install hidden>Install app</button>
                            <form method="post" action="{{ route('logout') }}" data-logout>@csrf<input type="hidden" name="push_endpoint" data-push-endpoint><button type="submit">Log out ({{ $user->name }})</button></form>
                        </div>
                    </details>
                </nav>
            @endif
        </div>
    </header>

    @if ($showingRehearsal && $user)
        <div class="banner rehearsal" role="status">Rehearsal data: these are not real results.</div>
    @endif
    <div class="banner offline" data-offline-banner hidden role="status"></div>
    <div class="banner queue" data-queue-banner hidden role="status"></div>

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
            @foreach ($tabs as [$name, $label, $path, $covers])
                <a href="{{ route($name) }}" class="{{ $active($covers) }}" data-live-id="tab-{{ $name }}" @if ($active($covers)) aria-current="page" @endif>
                    <span class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}"/></svg>
                        @if ($name === 'incidents' && $urgentOpen)<span class="count" aria-label="{{ $urgentOpen }} urgent open">{{ $urgentOpen }}</span>@endif
                    </span>
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
                        <a href="{{ route('broadcasts') }}">Broadcasts</a>
                        <hr>
                    @endif
                    <a href="{{ route('townhall.manage') }}">Town hall</a>
                    <a href="{{ route('push') }}">Notifications</a>
                    <button type="button" data-install hidden>Install app</button>
                    <form method="post" action="{{ route('logout') }}" data-logout>@csrf<input type="hidden" name="push_endpoint" data-push-endpoint><button type="submit">Log out ({{ $user->name }})</button></form>
                </div>
            </details>
        </nav>
    @endif
</body>
</html>
