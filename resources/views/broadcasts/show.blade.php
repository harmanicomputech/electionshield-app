@extends('layouts.app')

@section('title', $broadcast->title)

@php
    $total = array_sum($counts);
    $groups = \App\Services\Broadcasting\Audience::GROUPS;
    $areas = collect($broadcast->audience['lgas'] ?? [])->merge(collect($broadcast->audience['wards'] ?? [])->map(fn ($w) => str_replace('|', ' › ', $w)));
@endphp

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('broadcasts') }}">← Broadcasts</a></div>
    <h1>{{ $broadcast->title }}</h1>
    <p class="muted">{{ $broadcast->channel === 'sms' ? 'SMS' : 'WhatsApp template '.$broadcast->template }} · {{ ucfirst($broadcast->status) }}@if ($broadcast->status === 'scheduled') for {{ \App\Support\Time::local($broadcast->scheduled_at, 'l j M, g:i A') }}@endif · by {{ $broadcast->created_by }}</p>
</div>

<div class="grid two">
    <section class="card">
        <h2>Message</h2>
        @if ($broadcast->channel === 'sms')
            <p class="note" style="white-space:pre-line;background:var(--surface-2);padding:12px;border-radius:8px">{{ $broadcast->message }}</p>
            <p class="small muted">{{ $segments['characters'] }} characters · {{ $segments['segments'] }} SMS part{{ $segments['segments'] === 1 ? '' : 's' }} each{{ $segments['unicode'] ? ' (Unicode)' : '' }}</p>
        @else
            <p>Template <code>{{ $broadcast->template }}</code> ({{ $broadcast->template_language }})</p>
            @if ($broadcast->message)<p class="small">Values: {{ implode(' · ', preg_split('/\R/', $broadcast->message)) }}</p>@endif
        @endif
        <p class="small"><b>To:</b> {{ collect($broadcast->audience['groups'] ?? [])->map(fn ($g) => $groups[$g] ?? $g)->implode(', ') }}{{ $areas->isNotEmpty() ? ' in '.$areas->implode(', ') : ', everywhere' }}</p>
        @if ($broadcast->editable())<a class="button secondary" href="{{ route('broadcasts.edit', $broadcast) }}">Edit</a>@endif
    </section>

    @if ($broadcast->editable())
        <section class="card">
            <h2>Send</h2>
            @unless ($ready)
                <p class="flash bad small">{{ $broadcast->channel === 'sms' ? 'SMS is not set up: add AFRICASTALKING_API_KEY to .env.' : 'WhatsApp is not set up: add WHATSAPP_PHONE_NUMBER_ID and WHATSAPP_TOKEN to .env.' }}</p>
            @endunless
            @php($parts = $recipients->count() * $segments['segments'])
            <p><b>{{ number_format($recipients->count()) }}</b> {{ \Illuminate\Support\Str::plural('recipient', $recipients->count()) }} right now (opted-out and duplicate numbers left out)@if ($broadcast->channel === 'sms'), about {{ number_format($parts) }} SMS {{ \Illuminate\Support\Str::plural('part', $parts) }}@endif.</p>

            <form method="post" action="{{ route('broadcasts.test', $broadcast) }}" class="filters" style="margin-bottom:8px">
                @csrf
                <div class="wide"><label for="test-phone">Send a test to one number first</label><input id="test-phone" type="text" name="phone" inputmode="tel" placeholder="0803 123 4567" required></div>
                <div class="wide"><button class="button secondary" type="submit" @disabled(! $ready)>Send test</button></div>
            </form>

            <form method="post" action="{{ route('broadcasts.send', $broadcast) }}">
                @csrf
                <label for="confirm">To send now, type the number of recipients ({{ $recipients->count() }})</label>
                <input id="confirm" type="text" inputmode="numeric" name="confirm" autocomplete="off" required>
                @error('confirm')<div class="field-error">{{ $message }}</div>@enderror
                <p></p>
                <button class="button" type="submit" @disabled(! $ready || $recipients->isEmpty())>Send to {{ number_format($recipients->count()) }} now</button>
            </form>

            <form method="post" action="{{ route('broadcasts.schedule', $broadcast) }}" style="margin-top:16px">
                @csrf
                <label for="scheduled_at">…or schedule it (Nigeria time). The audience is worked out when it goes.</label>
                <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ $broadcast->scheduled_at ? \App\Support\Time::local($broadcast->scheduled_at, 'Y-m-d\TH:i') : '' }}" required style="width:100%;min-height:44px">
                <p></p>
                <button class="button secondary" type="submit" @disabled(! $ready)>{{ $broadcast->status === 'scheduled' ? 'Reschedule' : 'Schedule' }}</button>
            </form>

            <form method="post" action="{{ route('broadcasts.cancel', $broadcast) }}" style="margin-top:16px" onsubmit="return confirm('Cancel this broadcast?')">@csrf<button class="button danger" type="submit">Cancel broadcast</button></form>
        </section>
    @else
        <section class="card">
            <h2>Delivery</h2>
            @php($delivered = $counts['delivered'] ?? 0)
            <div class="stats" style="grid-template-columns:repeat(2,minmax(0,1fr))">
                <div class="stat"><b>{{ number_format($delivered) }}</b><span>Delivered ({{ $total ? round(100 * $delivered / $total) : 0 }}%)</span></div>
                <div class="stat"><b>{{ number_format($counts['sent'] ?? 0) }}</b><span>Sent, awaiting report</span></div>
                <div class="stat"><b>{{ number_format($counts['queued'] ?? 0) }}</b><span>Waiting to send</span></div>
                <div class="stat"><b>{{ number_format($counts['failed'] ?? 0) }}</b><span>Failed</span></div>
            </div>
            @if (($counts['skipped'] ?? 0) > 0)<p class="small muted">{{ $counts['skipped'] }} skipped (cancelled).</p>@endif
            @if ($failures->isNotEmpty())
                <p class="small"><b>Why messages failed:</b></p>
                <ul class="small">@foreach ($failures as $row)<li>{{ $row->failure_reason ?? 'Unknown' }}: {{ $row->total }}</li>@endforeach</ul>
            @endif
            @if ($broadcast->status === 'sending')
                <p class="small muted">Batches go out each minute from the scheduler cron.</p>
                <form method="post" action="{{ route('broadcasts.cancel', $broadcast) }}" onsubmit="return confirm('Stop the messages not yet sent?')">@csrf<button class="button danger" type="submit">Stop sending</button></form>
            @endif
        </section>
    @endif
</div>
@endsection
