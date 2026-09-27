@extends('layouts.app')

@section('title', 'Report incident')

@section('content')
<div class="page-head">
    <p class="small"><a href="{{ route('field') }}">← Home</a></p>
    <h1>Report an incident</h1>
    <p class="muted">Violence and other urgent incidents alert the coordinators at once. Stay safe: only film if you can do it without risk.</p>
</div>

<form method="post" action="{{ route('field.incident.store') }}" enctype="multipart/form-data" class="card" data-field-form="Incident report">
    @csrf
    @unless ($agent?->polling_unit_code)
        <label for="polling_unit">PU code</label>
        <input id="polling_unit" name="polling_unit" inputmode="numeric" value="{{ old('polling_unit') }}" required>
    @endunless

    <fieldset class="type-grid">
        <legend>What happened?</legend>
        @foreach ($types as $value => $typeLabel)
            <label class="type-option">
                <input type="radio" name="type" value="{{ $value }}" @checked(old('type') === $value) required>
                <span>{{ $typeLabel }}</span>
            </label>
        @endforeach
    </fieldset>
    @error('type')<div class="field-error">{{ $message }}</div>@enderror

    <label for="note">Describe it (who, what, how many people)</label>
    <textarea id="note" name="note" maxlength="1000" rows="4" required>{{ old('note') }}</textarea>
    @error('note')<div class="field-error">{{ $message }}</div>@enderror

    @include('partials.media-input', ['maxMb' => $maxMb])

    <button class="button block danger" type="submit">Send report</button>
    <div class="queue-state" data-queue-state aria-live="polite"></div>
</form>
@endsection
