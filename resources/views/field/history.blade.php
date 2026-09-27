@extends('layouts.app')

@section('title', 'My reports')

@section('content')
<div class="page-head">
    <h1>My reports</h1>
    <p class="muted">What you have sent, by USSD or here. Add photos or videos to any of them.</p>
</div>

@if ($results->isEmpty() && $incidents->isEmpty())
    <div class="card"><p class="muted">Nothing sent yet.</p></div>
@endif

@if ($results->isNotEmpty())
    <h2>Results</h2>
    <ul class="list">
        @foreach ($results as $result)
            <li class="item">
                <div class="badges" style="margin-top:0">
                    <span class="badge {{ $result->status->value === 'accepted' ? 'good' : ($result->status->value === 'pending' ? 'warn' : '') }}">{{ $result->status->label() }}</span>
                    <span class="badge">{{ $result->channelLabel() }}</span>
                    @if ($result->corrects_reference)<span class="badge">Corrects {{ $result->corrects_reference }}</span>@endif
                </div>
                <h3>{{ $result->reference }} · PU {{ $result->polling_unit_code }}</h3>
                <div class="meta">{{ \App\Support\Time::local($result->submitted_at, 'j M, g:i A') }} · accredited {{ number_format($result->accredited_voters) }} · {{ $result->votes->map(fn ($v) => $v->party.' '.number_format($v->votes))->implode(', ') }}</div>
                @include('field.partials.media-list', ['reference' => $result->reference])
            </li>
        @endforeach
    </ul>
@endif

@if ($incidents->isNotEmpty())
    <h2>Incidents</h2>
    <ul class="list">
        @foreach ($incidents as $incident)
            <li class="item">
                <div class="badges" style="margin-top:0">
                    @if ($incident->urgent)<span class="badge bad">⚠ Urgent</span>@endif
                    <span class="badge">{{ $incident->label() }}</span>
                    <span class="badge">{{ $incident->channelLabel() }}</span>
                    <span class="badge {{ $incident->resolved_at ? 'good' : ($incident->acknowledged_at ? 'warn' : '') }}">{{ $incident->resolved_at ? '✓ Resolved' : ($incident->acknowledged_at ? 'Seen by the situation room' : 'Sent') }}</span>
                </div>
                <h3>{{ $incident->reference }} · PU {{ $incident->polling_unit_code }}</h3>
                <div class="meta">{{ \App\Support\Time::local($incident->reported_at, 'j M, g:i A') }}</div>
                @if ($incident->note)<p class="note">{{ $incident->note }}</p>@endif
                @include('field.partials.media-list', ['reference' => $incident->reference])
            </li>
        @endforeach
    </ul>
@endif
@endsection
