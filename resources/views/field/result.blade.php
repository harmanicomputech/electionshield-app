@extends('layouts.app')

@section('title', 'Submit result')

@section('content')
<div class="page-head">
    <p class="small"><a href="{{ route('field') }}">← Home</a></p>
    <h1>{{ $existing ? 'Send a correction' : 'Submit result' }}</h1>
    @if ($unit)<p class="muted">{{ $unit->name }} · {{ $unit->ward }}, {{ $unit->lga }} · PU {{ $unit->code }}@if ($unit->registered_voters) · {{ number_format($unit->registered_voters) }} registered @endif</p>@endif
</div>

@if ($existing)
    <div class="flash warn">A result was already submitted for this PU ({{ $existing->reference }}, by {{ $existing->agent_name ?? 'an agent' }} via {{ $existing->channelLabel() }}). What you send now is a <b>correction</b>: a coordinator reviews it before it replaces the first one.</div>
@endif

<form method="post" action="{{ route('field.result.store') }}" enctype="multipart/form-data" class="card" data-field-form="Result" data-result-form>
    @csrf
    @if ($existing)<input type="hidden" name="correction" value="1">@endif
    @unless ($agent?->polling_unit_code)
        <label for="polling_unit">PU code (digits only, e.g. 21202633007)</label>
        <input id="polling_unit" name="polling_unit" inputmode="numeric" value="{{ old('polling_unit') }}" required>
    @endunless

    <label for="accredited_voters">Accredited voters</label>
    <input id="accredited_voters" name="accredited_voters" type="number" inputmode="numeric" min="0" value="{{ old('accredited_voters') }}" required data-accredited>
    @error('accredited_voters')<div class="field-error">{{ $message }}</div>@enderror

    <fieldset class="votes">
        <legend>Votes for each party</legend>
        @foreach ($parties as $party)
            <label class="vote-row" for="votes-{{ $party }}">
                <span class="party-chip party-{{ strtolower($party) }}">{{ $party }}</span>
                <input id="votes-{{ $party }}" name="votes[{{ $party }}]" type="number" inputmode="numeric" min="0" value="{{ old('votes.'.$party) }}" required data-vote>
            </label>
            @error('votes.'.$party)<div class="field-error">{{ $message }}</div>@enderror
        @endforeach
    </fieldset>

    <label for="rejected_votes">Rejected votes</label>
    <input id="rejected_votes" name="rejected_votes" type="number" inputmode="numeric" min="0" value="{{ old('rejected_votes') }}" required data-rejected>

    <p class="totals small" data-totals aria-live="polite"></p>

    @include('partials.media-input', ['label' => 'Photo of the EC8A sheet (and any video)', 'maxMb' => $maxMb])

    <label class="inline confirm"><input type="checkbox" required> I have checked these figures against the signed EC8A.</label>
    <button class="button block" type="submit">{{ $existing ? 'Send correction' : 'Submit result' }}</button>
    <div class="queue-state" data-queue-state aria-live="polite"></div>
</form>
@endsection
