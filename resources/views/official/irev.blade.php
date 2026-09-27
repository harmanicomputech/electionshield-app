@extends('layouts.app')

@section('title', 'IReV (automatic)')

@section('content')
<div class="page-head">
    <h1>INEC IReV, fetched automatically</h1>
    <p class="muted">The app follows the election on INEC's result viewing portal, fetches each polling unit's EC8A sheet as soon as it is uploaded, reads the figures with AI and saves them as the PU's official result, marked <b>not yet checked</b> until a person compares them with the sheet. Results a person has entered are never overwritten.</p>
</div>

@include('partials.results-tabs')
@include('partials.official-tabs')

<div class="grid two">
    <section class="card">
        <h2>Election followed</h2>
        @if ($configured)
            <p><b>{{ $election }}</b></p>
            <dl class="kv small">
                <dt>Automatic fetching</dt><dd>{!! $automatic ? '<span class="badge good">✓ On</span>' : '<span class="badge">Off</span>' !!} <span class="muted">every 2 minutes while the pinger runs</span></dd>
                <dt>Last looked</dt><dd>{{ $lastSweep ? \App\Support\Time::local(\Illuminate\Support\Carbon::parse($lastSweep), 'j M, g:i A') : 'not yet' }}@if ($lastFullSweep) <span class="muted">(whole state last covered {{ \App\Support\Time::local(\Illuminate\Support\Carbon::parse($lastFullSweep), 'g:i A') }})</span>@endif</dd>
                <dt>Reading with AI</dt><dd>{!! $reader ? '<span class="badge good">✓ On</span>' : '<span class="badge warn">Off</span> <span class="muted">sheets are downloaded for you to enter by hand; add ANTHROPIC_API_KEY to .env to read them automatically</span>' !!}</dd>
            </dl>
            @if ($lastError)<div class="flash bad small">Last problem: {{ $lastError }}</div>@endif
            <div class="actions">
                <form method="post" action="{{ route('official.irev.check') }}" data-busy="Checking IReV…">@csrf<button class="button" type="submit">Check now</button></form>
                <form method="post" action="{{ route('official.irev.automatic') }}">@csrf<input type="hidden" name="on" value="{{ $automatic ? 0 : 1 }}"><button class="button secondary" type="submit">{{ $automatic ? 'Turn automatic off' : 'Turn automatic on' }}</button></form>
            </div>
        @else
            <p class="small">Not following an election yet. INEC lists the governorship election on IReV shortly before election day; for a rehearsal you can follow a past Ebonyi election.</p>
        @endif

        <form method="post" action="{{ route('official.irev.elections') }}" style="margin-top:12px">@csrf<button class="button secondary" type="submit">{{ $configured ? 'Change election' : 'Find the election on IReV' }}</button></form>
        @if ($choices)
            <form method="post" action="{{ route('official.irev.follow') }}" style="margin-top:12px">
                @csrf
                <label for="irev-election">Elections on IReV for {{ config('election.state', 'Ebonyi') }}</label>
                <select id="irev-election" name="election" required>
                    @foreach ($choices as $choice)
                        <option value="{{ $choice['oid'] }}|{{ $choice['id'] }}|{{ $choice['name'] }}">{{ $choice['name'] }}</option>
                    @endforeach
                </select>
                <p></p>
                <button class="button" type="submit">Follow this election</button>
            </form>
        @endif
    </section>

    <section class="card">
        <h2>Polling units on IReV</h2>
        @if ($unchecked)
            <p><span class="badge warn">{{ number_format($unchecked) }} official results not yet checked</span> <a href="{{ route('official', ['unchecked' => 1]) }}">check them</a></p>
        @endif
        <ul class="irev-counts small">
            @foreach (\App\Models\IrevDocument::LABELS as $key => $label)
                <li><a href="{{ route('official.irev', ['status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}"><span>{{ $label }}</span><b>{{ number_format($counts[$key] ?? 0) }}</b></a></li>
            @endforeach
        </ul>
        <p class="small muted">IReV publishes scanned sheets, not figures, so every figure here comes from reading the sheet. The comparison with our agents' figures is on the Comparison tab.</p>
    </section>
</div>

@if ($rows)
    <section class="card">
        <h2>{{ \App\Models\IrevDocument::LABELS[$status] }} <span class="muted">({{ $rows->total() }})</span></h2>
        <div class="table-wrap">
            <table class="stack">
                <thead><tr><th>IReV PU</th><th>Our PU</th><th>Sheet</th><th></th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="key"><b>{{ $row->irev_pu_code }}</b> {{ $row->pu_name }}<span class="muted small" style="display:block">{{ $row->lga_name }} › {{ $row->ward_name }}</span></td>
                            <td data-label="Our PU">
                                @if ($row->polling_unit_code)
                                    <a href="{{ route('official.pu', $row->polling_unit_code) }}">{{ $row->polling_unit_code }}</a>@if ($row->matched_by_hand) <span class="muted small">(by hand)</span>@endif
                                @else
                                    <form method="post" action="{{ route('official.irev.match', $row) }}" class="inline-form">
                                        @csrf
                                        <label class="sr-only" for="m-{{ $row->id }}">Our PU code</label>
                                        <input id="m-{{ $row->id }}" name="code" inputmode="numeric" placeholder="Our PU code" required>
                                        <button class="button secondary" type="submit">Match</button>
                                    </form>
                                @endif
                            </td>
                            <td data-label="Sheet">
                                @if ($row->document_url)<a href="{{ $row->document_url }}" target="_blank" rel="noopener noreferrer">On IReV</a>@else<span class="muted">not uploaded</span>@endif
                                @if ($row->document_updated_at)<span class="muted small" style="display:block">{{ \App\Support\Time::local($row->document_updated_at, 'j M, g:i A') }}</span>@endif
                            </td>
                            <td data-label="">@if ($row->last_error)<span class="small muted">{{ \Illuminate\Support\Str::limit($row->last_error, 140) }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    </section>
@endif
@endsection
