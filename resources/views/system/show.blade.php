@extends('layouts.app')

@section('title', 'System')

@php
    $ok = fn (bool $value, string $yes = 'Set', string $no = 'Not set') => $value ? '<span class="badge good">✓ '.$yes.'</span>' : '<span class="badge bad">✗ '.$no.'</span>';
@endphp

@section('content')
<div class="page-head">
    <h1>System &amp; sync</h1>
    <p class="muted">The connection to the Election Shield USSD service.</p>
</div>

<div class="grid two">
    <section class="card">
        <h2>1. Webhook (real time)</h2>
        <p class="small">In the USSD server's <code>.env</code>, set:</p>
        <dl class="kv small">
            <dt>DASHBOARD_WEBHOOK_URL</dt><dd><code>{{ $webhookUrl }}</code></dd>
            <dt>DASHBOARD_API_TOKEN</dt><dd>same value as USSD_WEBHOOK_TOKEN here {!! $ok(filled(config('services.ussd.webhook_token'))) !!}</dd>
            <dt>DASHBOARD_WEBHOOK_SECRET</dt><dd>same value as USSD_WEBHOOK_SECRET here {!! $ok(filled(config('services.ussd.webhook_secret'))) !!}</dd>
        </dl>
        <p></p>
        <p class="small">Last event:
            @if ($lastEvent)
                <b>{{ $lastEvent->event }}</b> at {{ \App\Support\Time::local($lastEvent->received_at, 'j M, g:i:s A') }}
            @else
                <span class="muted">none received yet</span>
            @endif
        </p>
        @unless ($webhookConfigured)
            <p class="flash bad small">Events are refused until both values are set.</p>
        @endunless
        <p class="small muted">Then press <b>Settings → Send all existing data to the dashboard</b> in the USSD console to catch up.</p>
    </section>

    <section class="card">
        <h2>2. Read API (safety net)</h2>
        <dl class="kv small">
            <dt>USSD_API_URL</dt><dd><code>{{ $apiUrl }}</code></dd>
            <dt>USSD_API_TOKEN</dt><dd>ELECTION_API_TOKEN from the USSD server {!! $ok($apiConfigured) !!}</dd>
            <dt>Background work</dt><dd>{!! $heartbeat && $heartbeat->gt(now()->subMinutes(5)) ? '<span class="badge good">✓ Running</span>' : '<span class="badge bad">✗ Not running</span>' !!} <span class="muted">{{ $heartbeat ? 'last '.\App\Support\Time::local($heartbeat, 'j M, g:i A').($runnerSource ? " ({$runnerSource})" : '') : '' }}</span></dd>
        </dl>
        <p></p>
        <p class="small muted">Every 3 minutes the background work pulls whatever changed since the last sync. Your host forbids per-minute cron jobs, so set a free pinger (cron-job.org) to open this URL <b>every minute</b>, and keep it secret:</p>
        <p><code>{{ $runnerUrl }}</code></p>
        <div class="actions">
            <form method="post" action="{{ route('system.sync') }}">@csrf<button class="button" type="submit" @disabled(! $apiConfigured)>Sync now</button></form>
            <form method="post" action="{{ route('system.sync') }}">@csrf<input type="hidden" name="full" value="1"><button class="button secondary" type="submit" @disabled(! $apiConfigured)>Full import</button></form>
        </div>
    </section>
</div>

<section class="card" style="margin-top:16px">
    <h2>Sync status</h2>
    <div class="table-wrap">
        <table class="stack">
            <thead><tr><th>Resource</th><th>Last success</th><th class="num">Records last run</th><th>Error</th></tr></thead>
            <tbody>
                @foreach ($resources as $resource)
                    @php($state = $states->get($resource))
                    <tr>
                        <td class="key">{{ $resource }}</td>
                        <td data-label="Last success">{{ $state?->last_success_at ? \App\Support\Time::local($state->last_success_at, 'j M, g:i A') : 'never' }}</td>
                        <td class="num" data-label="Records">{{ $state?->last_count ?? 0 }}</td>
                        <td data-label="Error">@if ($state?->last_error)<span class="badge bad">Failed</span> <span class="small">{{ $state->last_error }}</span>@else<span class="muted">—</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

@if ($failedEvents->isNotEmpty())
    <section class="card">
        <h2>Webhook events that failed</h2>
        <p class="small muted">The USSD service retries these on its own. After a fix you can apply them now.</p>
        <ul class="small">
            @foreach ($failedEvents as $event)
                <li><b>{{ $event->idempotency_key }}</b> · {{ \App\Support\Time::local($event->received_at, 'j M, g:i A') }} · {{ $event->error }}</li>
            @endforeach
        </ul>
        <form method="post" action="{{ route('system.reprocess') }}">@csrf<button class="button secondary" type="submit">Apply failed events again</button></form>
    </section>
@endif

<div class="grid two">
    <section class="card">
        <h2>Data shown</h2>
        <p class="small">Dashboards show either real results or rehearsal data, never both. Currently: <b>{{ $showingRehearsal ? 'rehearsal data' : 'real results' }}</b>.</p>
        <form method="post" action="{{ route('system.data-view') }}">
            @csrf
            <input type="hidden" name="view" value="{{ $showingRehearsal ? 'real' : 'rehearsal' }}">
            <button class="button secondary" type="submit">{{ $showingRehearsal ? 'Show real results' : 'Show rehearsal data' }}</button>
        </form>
    </section>

    <section class="card">
        <h2>Backup</h2>
        <p class="small">Every table as CSV in one zip: the register, agents, results, incidents, official figures, broadcasts, town hall, users (no passwords) and the audit log. Download one after each rehearsal and on election night, and keep copies off the server.</p>
        <a class="button secondary" href="{{ route('system.backup') }}">Download full backup</a>
        <p class="small muted" style="margin-top:8px">EC8A photo files aren't in the zip (size); copy <code>storage/app/private/ec8a/</code> with the File Manager. Their fingerprints are in the zip.</p>
    </section>

    <section class="card">
        <h2>Clear rehearsal data</h2>
        <p class="small">Before the real election, remove what the rehearsals left here: {{ number_format($rehearsalCounts['results']) }} rehearsal results, {{ number_format($rehearsalCounts['incidents']) }} incidents, their check-ins, materials reports and EC8A photos. Real data is never touched. Clear the USSD service's test data too (its Settings page).</p>
        <form method="post" action="{{ route('system.clear-rehearsal') }}" onsubmit="return confirm('Delete all rehearsal data from the web app?')">
            @csrf
            <div class="filters">
                <div><label for="c-confirm">Type CLEAR</label><input id="c-confirm" type="text" name="confirm" autocomplete="off" required></div>
                <div><label for="c-password">Your password</label><input id="c-password" type="password" name="password" autocomplete="current-password" required></div>
            </div>
            @error('confirm')<div class="field-error">{{ $message }}</div>@enderror
            @error('password')<div class="field-error">{{ $message }}</div>@enderror
            <button class="button danger" type="submit">Clear rehearsal data</button>
        </form>
    </section>

    <section class="card">
        <h2>Polling unit register</h2>
        <p class="small">{{ number_format($register['units']) }} polling units in {{ $register['lgas'] }} LGAs and {{ number_format($register['wards']) }} wards · {{ number_format($register['registered']) }} registered voters.</p>
        <form method="post" action="{{ route('system.polling-units') }}" enctype="multipart/form-data">
            @csrf
            <label for="pu-file">Newer register (optional CSV: code,name,ward,lga,registered_voters)</label>
            <input id="pu-file" type="file" name="file" accept=".csv,text/csv">
            <p class="small muted">With no file, the bundled Ebonyi register (3,308 PUs) is loaded. Existing PUs are updated by code; nothing is deleted.</p>
            <button class="button secondary" type="submit">Import polling units</button>
        </form>
    </section>

    <section class="card">
        <h2>Notifications (Web Push)</h2>
        @if ($pushConfigured)
            <p class="small"><span class="badge good">✓ Set up</span> {{ $pushDevices }} device{{ $pushDevices === 1 ? '' : 's' }} opted in. Each person turns them on under More → Notifications.</p>
        @else
            <p class="small">Alerts for urgent incidents and corrections on coordinators' phones. Set up once; the keys are kept in the database.</p>
            <form method="post" action="{{ route('system.push-keys') }}">@csrf<button class="button secondary" type="submit">Set up notifications</button></form>
        @endif
    </section>

    <section class="card">
        <h2>After uploading a new version</h2>
        <p class="small">Bring the database up to date. It is safe to press at any time.</p>
        <form method="post" action="{{ route('system.migrate') }}">@csrf<button class="button secondary" type="submit">Update database</button></form>
    </section>

    <section class="card">
        <h2>Stored here</h2>
        <dl class="kv small">
            @foreach ($counts as $label => $count)
                <dt>{{ $label }}</dt><dd class="num" style="text-align:left">{{ number_format($count) }}</dd>
            @endforeach
        </dl>
    </section>
</div>
@endsection
