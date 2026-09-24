@extends('layouts.app')

@section('title', $ward)

@php($parties = \App\Services\Collation::parties())
@php($photos = \App\Models\Ec8aPhoto::query()->whereIn('result_reference', collect($units)->pluck('result.reference')->filter())->get()->groupBy('result_reference'))

@section('content')
<div class="page-head">
    <div class="crumbs"><a href="{{ route('collation') }}">All LGAs</a> › <a href="{{ route('collation.lga', $lga) }}">{{ $lga }}</a></div>
    <h1>{{ $ward }}</h1>
    <p class="muted">{{ collect($units)->whereNotNull('result')->count() }} of {{ count($units) }} polling units reported.</p>
</div>

<div class="card flush">
    <div class="table-wrap">
        <table class="stack">
            <thead>
                <tr>
                    <th>Polling unit</th>
                    <th>Result</th>
                    @foreach ($parties as $party)<th class="num">{{ $party }}</th>@endforeach
                    <th class="num">Accredited</th>
                    <th class="num">Rejected</th>
                    <th>Agent</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($units as $row)
                    @php($result = $row['result'])
                    @php($votes = $result?->votesByParty() ?? [])
                    <tr>
                        <td class="key">
                            <b>{{ $row['unit']?->name ?? 'PU '.$row['code'] }}</b>
                            <span class="muted small" style="display:block">{{ $row['unit']?->inecCode() ?? $row['code'] }}@if ($row['unit']?->registered_voters) · {{ number_format($row['unit']->registered_voters) }} registered @endif</span>
                            @if ($result)<button type="button" class="more-btn" data-toggle-row aria-expanded="false">Details</button>@endif
                        </td>
                        <td data-label="Result">
                            @if ($result)
                                <span class="badge good">✓ {{ $result->reference }}</span>
                                <span class="muted small">{{ \App\Support\Time::local($result->submitted_at) }}</span>
                                @php($shots = $photos->get($result->reference))
                                @if ($shots)
                                    <a class="badge {{ $shots->contains('review_status', 'mismatch') ? 'bad' : '' }}" href="{{ route('photos.show', $shots->first()) }}">📷 {{ $shots->count() }} EC8A</a>
                                @else
                                    <a class="badge" href="{{ route('photos', ['reference' => $result->reference]) }}">Add EC8A photo</a>
                                @endif
                            @else
                                <span class="badge warn">Not reported</span>
                            @endif
                        </td>
                        @foreach ($parties as $party)
                            <td class="num detail" data-label="{{ $party }}">{{ $result ? number_format($votes[$party] ?? 0) : '—' }}</td>
                        @endforeach
                        <td class="num detail" data-label="Accredited">{{ $result ? number_format($result->accredited_voters) : '—' }}</td>
                        <td class="num detail" data-label="Rejected">{{ $result ? number_format($result->rejected_votes) : '—' }}</td>
                        <td class="detail" data-label="Agent">{{ $result?->agent_name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
