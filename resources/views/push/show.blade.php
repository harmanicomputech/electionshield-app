@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
<div class="page-head">
    <h1>Notifications</h1>
    <p class="muted">Get an alert on this phone or computer, even when the app is closed.</p>
</div>

@if (! $configured)
    <div class="card"><p>Notifications aren't set up yet. An admin can set them up on the System page.</p></div>
@else
    <section class="card" data-push-panel>
        <h2>This device</h2>
        <p data-push-status class="small">Checking…</p>
        <fieldset style="border:0;padding:0;margin:0">
            <legend class="small muted">Alert me about</legend>
            @foreach ($topics as $key => $label)
                <label class="inline"><input type="checkbox" name="topics[]" value="{{ $key }}" checked data-push-topic> {{ $label }}</label>
            @endforeach
        </fieldset>
        <div class="actions" style="margin-top:12px">
            <button type="button" class="button" data-push-enable hidden>Turn on notifications</button>
            <button type="button" class="button secondary" data-push-save hidden>Save choices</button>
            <button type="button" class="button secondary" data-push-test hidden>Send a test</button>
            <button type="button" class="button danger" data-push-disable hidden>Turn off on this device</button>
        </div>
        <div class="card install-guide" data-push-ios style="margin-top:12px">
            <p class="small"><b>On iPhone:</b> notifications need iOS 16.4 or later, and the app must be added to the Home Screen first (Share → Add to Home Screen). Then open Election Shield from the Home Screen and turn them on here.</p>
        </div>
    </section>

    <section class="card">
        <h2>Your devices</h2>
        @if ($devices->isEmpty())
            <p class="muted small">None yet.</p>
        @else
            <ul class="small">
                @foreach ($devices as $device)
                    <li>{{ \Illuminate\Support\Str::limit($device->device ?? 'Unknown device', 80) }} · {{ implode(', ', $device->topics) }} · last alert {{ $device->last_sent_at ? \App\Support\Time::local($device->last_sent_at, 'j M, g:i A') : 'never' }}</li>
                @endforeach
            </ul>
        @endif
        <p class="small muted">Logging out on a device turns its notifications off.</p>
    </section>
@endif
@endsection
