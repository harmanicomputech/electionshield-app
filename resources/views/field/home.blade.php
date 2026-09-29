@extends('layouts.app')

@section('title', 'Home')

@section('content')
<div class="page-head">
    <h1>Hello, {{ \Illuminate\Support\Str::before($agent?->name ?? auth()->user()->name, ' ') }}</h1>
    @if ($unit)
        <p class="muted">Your polling unit: <b>{{ $unit->name }}</b> · {{ $unit->ward }}, {{ $unit->lga }} · PU {{ $unit->code }}</p>
    @elseif ($agent?->polling_unit_code)
        <p class="muted">Your polling unit: PU {{ $agent->polling_unit_code }}</p>
    @elseif ($agent)
        <p class="muted">You don't have a polling unit assigned: you'll enter the PU code each time.</p>
    @endif
</div>

@unless ($agent)
    <div class="flash bad">Your agent record was not found. Ask your coordinator to check the phone number you were registered with.</div>
@else
    <div class="field-steps">
        <section class="card step {{ $presence ? 'done' : '' }}">
            <div class="step-head"><span class="step-no">1</span><h2>Check in</h2>@if ($presence)<span class="badge good">✓ {{ \App\Support\Time::local($presence->confirmed_at, 'g:i A') }}</span>@endif</div>
            <p class="small muted">Tell the situation room you are at your polling unit.</p>
            <form method="post" action="{{ route('field.presence') }}" data-field-form="Check-in" data-needs-location>
                @csrf
                @unless ($agent->polling_unit_code)<label for="presence-pu">PU code</label><input id="presence-pu" name="polling_unit" inputmode="numeric" required>@endunless
                <button class="button block" type="submit">{{ $presence ? 'Check in again' : "I'm at my polling unit" }}</button>
                <div class="queue-state" data-queue-state aria-live="polite"></div>
            </form>
        </section>

        <section class="card step {{ $materials ? 'done' : '' }}">
            <div class="step-head"><span class="step-no">2</span><h2>Election materials</h2>@if ($materials)<span class="badge {{ $statuses[$materials->status][1] ?? '' }}">{{ $statuses[$materials->status][0] ?? $materials->status_label }}</span>@endif</div>
            <p class="small muted">Report again whenever it changes. To report them as arrived, first add a photo or video of the materials.</p>
            <form method="post" action="{{ route('field.materials') }}" data-field-form="Materials report" enctype="multipart/form-data">
                @csrf
                @unless ($agent->polling_unit_code)<label for="materials-pu">PU code</label><input id="materials-pu" name="polling_unit" inputmode="numeric" required>@endunless
                @include('partials.media-input', ['id' => 'materials-media', 'label' => 'Photo or video of the materials (needed for “Arrived”)', 'maxMb' => $maxMb])
                <div class="choice-row">
                    @foreach ($statuses as $value => [$label, $class])
                        <button class="button secondary choice {{ $class }}" type="submit" name="status" value="{{ $value }}" @if (in_array($value, \App\Models\MaterialReport::NEEDS_EVIDENCE, true)) data-needs-media="Add a photo or video of the materials first (tap “Photo or video of the materials” above)." @endif>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="queue-state" data-queue-state aria-live="polite"></div>
            </form>
        </section>

        <section class="card step {{ $result ? 'done' : '' }}">
            <div class="step-head"><span class="step-no">3</span><h2>Result (EC8A)</h2>@if ($result)<span class="badge {{ $result->status === \App\Enums\ResultStatus::Pending ? 'warn' : 'good' }}">{{ $result->status === \App\Enums\ResultStatus::Pending ? 'Correction in review' : '✓ '.$result->reference }}</span>@endif</div>
            <p class="small muted">Enter the figures from the signed result sheet and add a clear photo of it.</p>
            <a class="button block" href="{{ route('field.result') }}">{{ $result ? 'Send a correction' : 'Submit result' }}</a>
        </section>

        <section class="card step">
            <div class="step-head"><span class="step-no">!</span><h2>Incidents</h2>@if ($incidents)<span class="badge">{{ $incidents }} sent</span>@endif</div>
            <p class="small muted">Violence, vote buying, missing officials, delays… Add a photo or video if it is safe to take one.</p>
            <a class="button block danger" href="{{ route('field.incident') }}">Report an incident</a>
        </section>
    </div>

    <p class="small muted" style="margin-top:16px">No network? Keep going: what you send is saved on this phone and goes out when the network returns. You can also still dial {{ config('services.ussd.service_code') ?: 'the USSD code' }} on any phone.</p>
@endunless
@endsection
