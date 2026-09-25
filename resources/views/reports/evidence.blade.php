@extends('layouts.app')

@section('title', 'Evidence · '.$unit->name)

@php
    $parties = \App\Services\Collation::parties();
    $ours = $counted?->votesByParty() ?? [];
    $irev = $official?->uploaded() ? $official->votesByParty() : null;
    $fmt = fn ($n) => $n === null ? '—' : number_format($n);
@endphp

@section('content')
<div class="report">
    <div class="report-actions no-print">
        <button type="button" class="button" onclick="window.print()">Print / Save as PDF</button>
        <a class="button secondary" href="{{ url()->previous() }}">Back</a>
    </div>

    <header class="report-head">
        <p class="report-kicker">Election Shield · evidence pack{{ $rehearsal ? ' · REHEARSAL DATA' : '' }}</p>
        <h1>{{ $unit->name }}</h1>
        <p>{{ $unit->inecCode() }} · {{ $unit->ward }}, {{ $unit->lga }} LGA · {{ $unit->registered_voters ? number_format($unit->registered_voters).' registered voters' : 'registered voters not known' }}</p>
        <p class="small muted">{{ config('election.name') }}, {{ \Illuminate\Support\Carbon::parse(config('election.date'))->format('j F Y') }}. Prepared {{ \App\Support\Time::local(now(), 'j F Y, g:i A') }} by {{ auth()->user()->name }}.</p>
        @if (in_array('discrepancy', $flags, true))<p class="report-flag">✗ Our agent's EC8A and IReV differ by up to {{ max(array_map('abs', $diff)) }} votes.</p>@endif
        @if (in_array('no_upload', $flags, true))<p class="report-flag">✗ No result sheet was uploaded to IReV for this PU.</p>@endif
    </header>

    <section class="report-section">
        <h2>1. Figures</h2>
        <div class="report-scroll"><table class="compact report-table">
            <thead><tr><th></th><th class="num">Our agent's EC8A{{ $counted ? ' ('.$counted->reference.')' : '' }}</th><th class="num">IReV</th><th class="num">Difference</th></tr></thead>
            <tbody>
                @foreach ($parties as $party)
                    @php($d = $diff[$party] ?? null)
                    <tr><td>{{ $party }}</td><td class="num">{{ $counted ? $fmt($ours[$party] ?? 0) : '—' }}</td><td class="num">{{ $irev ? $fmt($irev[$party]) : '—' }}</td><td class="num">{{ $d === null ? '—' : ($d > 0 ? '+' : '').number_format($d) }}</td></tr>
                @endforeach
                <tr><td>Total valid</td><td class="num">{{ $counted ? $fmt($counted->total_valid_votes) : '—' }}</td><td class="num">{{ $irev ? $fmt($official->totalValidVotes()) : '—' }}</td><td></td></tr>
                <tr><td>Rejected</td><td class="num">{{ $counted ? $fmt($counted->rejected_votes) : '—' }}</td><td class="num">{{ $irev ? $fmt($official->rejected_votes) : '—' }}</td><td></td></tr>
                <tr><td>Accredited</td><td class="num">{{ $counted ? $fmt($counted->accredited_voters) : '—' }}</td><td class="num">{{ $irev ? $fmt($official->accredited_voters) : '—' }}</td><td></td></tr>
            </tbody>
        </table></div>
        <p class="small">
            @if ($counted) Our figures: submitted by agent {{ $counted->agent_name }} by USSD at {{ \App\Support\Time::local($counted->submitted_at, 'j M Y, g:i A') }}.@else No accepted result from our agent.@endif
            @if ($official) IReV figures: {{ $official->uploaded() ? 'entered' : 'marked "no upload"' }} by {{ $official->entered_by }} ({{ $official->source }}) at {{ \App\Support\Time::local($official->updated_at, 'j M Y, g:i A') }}.@if ($official->note) Note: “{{ $official->note }}”.@endif @else No IReV entry yet.@endif
        </p>
        @if ($ward || $lga)
            <p class="small">Declared collations: @if ($ward) EC8B {{ $unit->ward }}: {{ collect($ward->votesByParty())->map(fn ($v, $p) => "{$p} ".number_format($v))->implode(', ') }}.@endif @if ($lga) EC8C {{ $unit->lga }}: {{ collect($lga->votesByParty())->map(fn ($v, $p) => "{$p} ".number_format($v))->implode(', ') }}.@endif</p>
        @endif
    </section>

    <section class="report-section">
        <h2>2. Result history</h2>
        @if ($history->isEmpty())
            <p class="small muted">No result received for this PU.</p>
        @else
            <div class="report-scroll"><table class="compact report-table">
                <thead><tr><th>Reference</th><th>Status</th><th>Submitted</th><th>Reviewed</th>@foreach ($parties as $party)<th class="num">{{ $party }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($history as $row)
                        @php($v = $row->votesByParty())
                        <tr>
                            <td>{{ $row->reference }}{{ $row->corrects_reference ? ' (corrects '.$row->corrects_reference.')' : '' }}</td>
                            <td>{{ $row->status->label() }}</td>
                            <td>{{ \App\Support\Time::local($row->submitted_at, 'j M, g:i A') }}</td>
                            <td>{{ $row->reviewed_at ? \App\Support\Time::local($row->reviewed_at, 'j M, g:i A').' by '.$row->reviewed_by : '—' }}{{ $row->review_note ? ': '.$row->review_note : '' }}</td>
                            @foreach ($parties as $party)<td class="num">{{ number_format($v[$party] ?? 0) }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    <section class="report-section">
        <h2>3. EC8A photographs</h2>
        @forelse ($photos as $photo)
            <figure class="report-photo">
                <img src="{{ route('photos.image', $photo) }}" alt="EC8A for {{ $photo->result_reference }}" loading="eager">
                <figcaption class="small">
                    Result {{ $photo->result_reference }} · {{ $photo->uploaded_via === 'agent' ? 'sent by the agent' : 'uploaded by '.$photo->uploaded_by }} · {{ \App\Support\Time::local($photo->created_at, 'j M Y, g:i A') }} · {{ $photo->reviewLabel() }}{{ $photo->reviewed_by ? ' ('.$photo->reviewed_by.')' : '' }}{{ $photo->review_note ? ': '.$photo->review_note : '' }}<br>
                    SHA-256 of the file as received: <code>{{ $photo->sha256 }}</code>
                </figcaption>
            </figure>
        @empty
            <p class="small muted">No EC8A photo for this PU.</p>
        @endforelse
    </section>

    <section class="report-section">
        <h2>4. Field reports</h2>
        <ul class="small">
            <li>Agent check-in: {{ $presence ? \App\Support\Time::local($presence->confirmed_at, 'j M, g:i A').' ('.$presence->agent_name.')' : 'none' }}</li>
            @foreach ($materials as $report)<li>Materials {{ $report->status_label ?? str_replace('_', ' ', $report->status) }}: {{ \App\Support\Time::local($report->reported_at, 'j M, g:i A') }}</li>@endforeach
            @foreach ($incidents as $incident)<li>Incident {{ $incident->reference }}, {{ $incident->label() }}{{ $incident->urgent ? ' (urgent)' : '' }}, {{ \App\Support\Time::local($incident->reported_at, 'j M, g:i A') }}{{ $incident->note ? ': “'.$incident->note.'”' : '' }}{{ $incident->resolved_at ? ' · resolved by '.$incident->resolved_by.($incident->resolution_note ? ': '.$incident->resolution_note : '') : '' }}</li>@endforeach
            @if ($materials->isEmpty() && $incidents->isEmpty())<li>No materials reports or incidents.</li>@endif
        </ul>
    </section>

    <p class="small muted report-foot">Source: Election Shield. Our figures come from the party's polling agent by USSD, each with its reference; IReV figures were copied from INEC's results portal. A photo's SHA-256 lets anyone confirm the file is the one received.</p>
</div>
@endsection
