@php
    $user = auth()->user();
    // The agent pages need an agent's account (a phone number), not just the permission.
    $can = fn (string $permission) => ($user?->hasPermission($permission) ?? false) && ($permission !== \App\Support\Permission::SUBMIT_FIELD_REPORTS || filled($user->phone));
    $active = fn (string|array $names) => request()->routeIs(...(array) $names) ? 'on' : '';
    $icon = [
        'dashboard' => 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z',
        'incidents' => 'M12 3 2 20h20L12 3Zm0 6v5m0 3h.01',
        'monitor' => 'M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Zm0-9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
        'results' => 'M4 20V10m6 10V4m6 16v-7m4 7H2',
        'collation' => 'M4 6h16M4 12h16M4 18h10',
        'spread' => 'M4 20V10m6 10V4m6 16v-7m4 7H2',
        'compare' => 'M12 3v18M5 7h14M5 7l-3 7a3 3 0 0 0 6 0L5 7Zm14 0-3 7a3 3 0 0 0 6 0l-3-7Z',
        'photos' => 'M4 8h3l2-3h6l2 3h3v11H4V8Zm8 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
        'townhall' => 'M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Zm-7 9a7 7 0 0 0 14 0M12 19v3',
        'broadcasts' => 'M3 11v2a1 1 0 0 0 1 1h3l6 5V5L7 10H4a1 1 0 0 0-1 1Zm14-3a5 5 0 0 1 0 8',
        'system' => 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4',
        'users' => 'M16 20v-2a4 4 0 0 0-8 0v2M12 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
        'roles' => 'M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6l-8-3Zm-3 9 2 2 4-4',
        'audit' => 'M9 4h6l1 2h3v14H5V6h3l1-2Zm-1 8h8m-8 4h5',
        'corrections' => 'M4 20h4L19 9l-4-4L4 16v4Zm9-13 4 4',
        'agents' => 'M17 20v-2a4 4 0 0 0-3-3.9M7 20v-2a4 4 0 0 1 4-4h2M12 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm6-1a2.5 2.5 0 1 0 0-5',
        'sitrep' => 'M6 3h9l4 4v14H6V3Zm9 0v4h4M9 12h7M9 16h7M9 8h3',
        'home' => 'M3 11 12 4l9 7v9h-6v-6H9v6H3v-9Z',
        'history' => 'M12 7v5l3 2M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5',
        'push' => 'M6 16v-5a6 6 0 1 1 12 0v5l2 2H4l2-2Zm4 4h4',
        'account' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0',
        'volunteers' => 'M12 21s-7-4.4-9.3-9A5.3 5.3 0 0 1 12 6.4a5.3 5.3 0 0 1 9.3 5.6C19 16.6 12 21 12 21Z',
        'intelligence' => 'M3 21V10m6 11V4m6 17v-8m6 8V7M2 21h20',
        'locations' => 'M12 2v3m0 14v3M2 12h3m14 0h3M12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12Zm0-3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
        'install' => 'M12 3v12m-5-5 5 5 5-5M5 21h14',
        'logout' => 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11',
    ];

    // Everything in the menus: [route, label, icon, routes it covers, permission]
    $menu = [
        'Agent' => [
            ['field', 'Home', $icon['home'], ['field'], \App\Support\Permission::SUBMIT_FIELD_REPORTS],
            ['field.result', 'Submit result', $icon['results'], ['field.result'], \App\Support\Permission::SUBMIT_FIELD_REPORTS],
            ['field.incident', 'Report incident', $icon['incidents'], ['field.incident'], \App\Support\Permission::SUBMIT_FIELD_REPORTS],
            ['field.history', 'My reports', $icon['history'], ['field.history'], \App\Support\Permission::SUBMIT_FIELD_REPORTS],
        ],
        'Election day' => [
            ['dashboard', 'Dashboard', $icon['dashboard'], ['dashboard'], \App\Support\Permission::VIEW_DASHBOARDS],
            ['incidents', 'Incidents', $icon['incidents'], ['incidents'], \App\Support\Permission::VIEW_INCIDENTS],
            ['monitor', 'Polling units', $icon['monitor'], ['monitor', 'monitor.*'], \App\Support\Permission::VIEW_DASHBOARDS],
            ['agents', 'Agents', $icon['agents'], ['agents', 'agents.*'], \App\Support\Permission::VIEW_AGENTS],
            ['corrections', 'Corrections', $icon['corrections'], ['corrections'], \App\Support\Permission::VIEW_DASHBOARDS],
            ['locations.people', 'People map', $icon['locations'], ['locations', 'locations.*'], \App\Support\Permission::VIEW_LOCATIONS],
        ],
        'Results' => [
            ['collation', 'Collation', $icon['collation'], ['collation', 'collation.*'], \App\Support\Permission::VIEW_DASHBOARDS],
            ['spread', '25% rule', $icon['spread'], ['spread'], \App\Support\Permission::VIEW_DASHBOARDS],
            ['compare', 'Official vs PVT', $icon['compare'], ['compare', 'compare.*', 'official', 'official.*'], \App\Support\Permission::VIEW_DASHBOARDS],
            ['photos', 'Photos & videos', $icon['photos'], ['photos', 'photos.*', 'media'], \App\Support\Permission::VIEW_DASHBOARDS],
            ['sitrep', 'Situation report', $icon['sitrep'], ['sitrep', 'evidence'], \App\Support\Permission::VIEW_DASHBOARDS],
        ],
        'Engage' => [
            ['townhall.manage', 'Town hall', $icon['townhall'], ['townhall.manage', 'townhall.moderate', 'townhall.create', 'townhall.edit'], \App\Support\Permission::MODERATE_TOWNHALL],
            ['broadcasts', 'Broadcasts', $icon['broadcasts'], ['broadcasts', 'broadcasts.*', 'contacts'], \App\Support\Permission::MANAGE_BROADCASTS],
            ['volunteers', 'Volunteers', $icon['volunteers'], ['volunteers', 'volunteers.*'], \App\Support\Permission::VIEW_VOLUNTEERS],
            ['intelligence', 'Voter intelligence', $icon['intelligence'], ['intelligence', 'intelligence.*'], \App\Support\Permission::VIEW_VOTER_INTELLIGENCE],
        ],
        'Admin' => [
            ['system', 'System & sync', $icon['system'], ['system'], \App\Support\Permission::MANAGE_SYSTEM],
            ['users', 'Users', $icon['users'], ['users'], \App\Support\Permission::MANAGE_USERS],
            ['roles', 'Roles', $icon['roles'], ['roles', 'roles.*'], \App\Support\Permission::MANAGE_USERS],
            ['audit', 'Audit log', $icon['audit'], ['audit'], \App\Support\Permission::VIEW_AUDIT],
        ],
    ];
    $sections = [];
    foreach ($menu as $heading => $items) {
        $allowed = array_values(array_filter($items, fn ($item) => $can($item[4])));
        if ($allowed) {
            $sections[] = [$heading, $allowed];
        }
    }

    // The phone tab bar and tablet top bar: four main places for this person.
    $all = array_merge(...array_map(fn ($section) => $section[1], $sections ?: [[null, []]]));
    $tabs = array_slice(array_values(array_filter($all, fn ($item) => in_array($item[0], ['field', 'field.result', 'field.incident', 'field.history', 'dashboard', 'incidents', 'monitor', 'collation'], true))), 0, 4);
    $tabNames = array_column($tabs, 0);
    $more = array_values(array_filter($all, fn ($item) => ! in_array($item[0], $tabNames, true)));
    $short = ['monitor' => 'PUs', 'collation' => 'Results', 'field.result' => 'Result', 'field.incident' => 'Incident', 'field.history' => 'History'];
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
    <link rel="stylesheet" href="/css/app.css?v={{ @filemtime(public_path('css/app.css')) ?: 0 }}">
    <script src="/js/app.js?v={{ @filemtime(public_path('js/app.js')) ?: 0 }}" defer></script>
    @yield('head')
    @if ($user)
    {{-- Chrome/Android: start loading a page as soon as a finger touches its link. --}}
    <script type="speculationrules">{"prefetch": [{"source": "document", "eagerness": "conservative", "where": {"and": [{"href_matches": "/*"}, {"not": {"href_matches": "/logout"}}, {"not": {"href_matches": "/*.csv"}}, {"not": {"href_matches": "/system/backup"}}, {"not": {"href_matches": "/media/*"}}, {"not": {"href_matches": "/cron/*"}}, {"not": {"selector_matches": "[download], [data-no-progress], [target]"}}]}}]}</script>
    @endif
</head>
<body @class(['has-sidebar' => $user]) @if ($user) data-cache-pages="1" @endif @if ($user?->sharesLocation()) data-location="{{ route('location.ping') }}" @endif @if ($user && ($user->can('respond_incidents') || $user->can('acknowledge_results') || $user->can('view_locations'))) data-alerts="{{ route('alerts') }}" data-snooze-url="{{ route('alerts.snooze') }}" @endif>
    @if ($user)
        <aside class="sidebar" aria-label="Main">
            <a class="side-brand" href="{{ route($user->homeRoute()) }}"><img src="/icons/icon-192.png" alt="" width="36" height="36"><span>Election Shield<small>Ebonyi {{ \Illuminate\Support\Carbon::parse(config('election.date'))->format('Y') }}</small></span></a>
            <nav class="side-nav">
                @foreach ($sections as [$heading, $items])
                    <p class="side-heading">{{ $heading }}</p>
                    @foreach ($items as [$name, $label, $path, $covers, $permission])
                        <a href="{{ route($name) }}" class="{{ $active($covers) }}" @if ($active($covers)) aria-current="page" @endif @if (in_array($name, ['incidents', 'corrections'], true)) data-live-id="side-{{ $name }}" @endif>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}"/></svg>
                            <span>{{ $label }}</span>
                            @if ($name === 'incidents' && $urgentOpen)<span class="count" aria-label="{{ $urgentOpen }} urgent open">{{ $urgentOpen }}</span>@endif
                            @if ($name === 'corrections' && $pendingCorrections)<span class="count pending" aria-label="{{ $pendingCorrections }} waiting">{{ $pendingCorrections }}</span>@endif
                        </a>
                    @endforeach
                @endforeach
            </nav>
            <div class="side-foot">
                <a href="{{ route('account') }}" class="{{ $active('account') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon['account'] }}"/></svg><span>My account</span></a>
                <a href="{{ route('push') }}" class="{{ $active('push') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon['push'] }}"/></svg><span>Notifications</span></a>
                <a href="{{ route('install') }}" class="{{ $active('install') }}" data-get-app><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon['install'] }}"/></svg><span>Get the app</span></a>
                <div class="side-user">
                    <span class="avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span>
                    <span class="who">{{ $user->name }}<small>{{ $user->roleName() }}{{ $user->lga ? ' · '.$user->lga : '' }}</small></span>
                    <form method="post" action="{{ route('logout') }}" data-logout>@csrf<input type="hidden" name="push_endpoint" data-push-endpoint><button type="submit" title="Log out" aria-label="Log out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon['logout'] }}"/></svg></button></form>
                </div>
            </div>
        </aside>
    @endif
    <header class="topbar">
        <div class="wrap">
            <a class="brand" href="{{ $user ? route($user->homeRoute()) : route('login') }}"><img src="/icons/icon-192.png" alt="">Election Shield</a>
            @if ($user)
                <nav class="topnav" aria-label="Main">
                    @foreach ($tabs as [$name, $label, $path, $covers])
                        <a href="{{ route($name) }}" class="{{ $active($covers) }}" data-live-id="top-{{ $name }}">{{ $short[$name] ?? $label }}@if ($name === 'incidents' && $urgentOpen)<span class="count" aria-label="{{ $urgentOpen }} urgent open">{{ $urgentOpen }}</span>@endif</a>
                    @endforeach
                    <span class="spacer"></span>
                    <details class="top-more">
                        <summary>More ▾</summary>
                        <div class="menu">
                            @include('partials.more-menu')
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
                <h2>📲 Get the Election Shield app</h2>
                <p class="small">Put it on your home screen: it opens full screen, works with poor network and shows alerts.</p>
                <div class="row-actions">
                    <a class="button" href="{{ route('install') }}">Get the app</a>
                    <button type="button" class="button secondary" data-dismiss>Not now</button>
                </div>
            </div>
            <p class="footer">Page loaded {{ \App\Support\Time::local(now()) }} · data last received from USSD {{ $lastData ? \App\Support\Time::local($lastData, 'j M, g:i A') : 'never' }}</p>
        @endif
    </main>

    @if ($user)
        <section class="alert-pop" data-alert-pop hidden role="alertdialog" aria-live="assertive" aria-labelledby="alert-pop-title"></section>
        <nav class="tabbar" aria-label="Main">
            @foreach ($tabs as [$name, $label, $path, $covers])
                <a href="{{ route($name) }}" class="{{ $active($covers) }}" data-live-id="tab-{{ $name }}" @if ($active($covers)) aria-current="page" @endif>
                    <span class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}"/></svg>
                        @if ($name === 'incidents' && $urgentOpen)<span class="count" aria-label="{{ $urgentOpen }} urgent open">{{ $urgentOpen }}</span>@endif
                    </span>
                    {{ $short[$name] ?? $label }}
                </a>
            @endforeach
            <details>
                <summary>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h.01M12 12h.01M19 12h.01"/></svg>
                    More
                </summary>
                <div class="menu">
                    @include('partials.more-menu')
                </div>
            </details>
        </nav>
    @endif
</body>
</html>
