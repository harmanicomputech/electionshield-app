@extends('layouts.app')

@section('title', 'Voter intelligence · '.$title)

@php
    $fmt = fn (?int $n) => $n === null ? '—' : number_format($n);
    $pct = fn (?float $p) => $p === null ? '' : rtrim(rtrim(number_format($p, 1), '0'), '.').'%';
    $voters = $official?->count ?? $registerVoters;
    $anyPvc = $children->contains(fn ($child) => $child['pvc_uncollected'] !== null);
    $anyFirst = $children->contains(fn ($child) => $child['first_time'] !== null);
    $showWards = $level === 'state';
    $showVolunteers = $level !== 'ward' && $level !== 'pu';
    $state = config('election.state', 'Ebonyi');
    $fromBadge = fn (array $dimension) => match ($dimension['level']) {
        'national' => 'warn',
        default => $dimension['inherited'] ? '' : 'good',
    };
    $missingHint = [
        'pvc' => 'INEC publishes PVC collection by state and LGA, and PU by PU before governorship elections (inecnigeria.org/statistics/pvc).',
        'first_time' => 'INEC publishes new registrations from continuous voter registration (CVR) by state and LGA.',
        'gender' => 'INEC publishes gender only nationally for 2023; add an Ebonyi or local figure if you get one.',
        'age' => 'INEC publishes age groups only nationally for 2023; add an Ebonyi or local figure if you get one.',
        'occupation' => 'INEC publishes occupations only nationally for 2023; add an Ebonyi or local figure if you get one.',
        'disability' => 'INEC records voters with disability by type; add figures if you get them.',
    ];
@endphp

@section('content')
<div class="page-head">
    @if ($crumbs)
        <div class="crumbs">@foreach ($crumbs as [$url, $label])<a href="{{ $url }}">{{ $label }}</a> › @endforeach<span class="muted">{{ $level === 'pu' ? 'Polling unit' : $title }}</span></div>
    @endif
    <h1>{{ $title }}</h1>
    @isset($subtitle)<p class="muted">{{ $subtitle }}</p>@endisset
    @if ($level === 'state')
        <p class="muted">Voters in each LGA, ward and polling unit, and who they are, to decide where and to whom to campaign. Every figure shows where it comes from; where an area has no figures of its own, the nearest larger area's are shown and labelled. Nothing is estimated.</p>
    @endif
</div>

<div class="stats vi-stats {{ $level === 'state' ? 'five' : '' }}">
    <div class="stat">
        <b>{{ $fmt($voters) }}</b>
        <span>Registered voters @if ($official) <small class="muted">· {{ $official->source }}@if ($official->as_of), {{ $official->as_of->format('M Y') }}@endif</small>@elseif ($registerVoters !== null) <small class="muted">· sum of the PU register</small>@else <small class="muted">· not loaded yet</small>@endif</span>
    </div>
    @if ($level !== 'pu')
        <div class="stat"><b>{{ number_format($units) }}</b><span>Polling units @if ($unitsWithVoters && $unitsWithVoters < $units) <small class="muted">· {{ number_format($unitsWithVoters) }} with voter numbers</small>@endif</span></div>
        @if ($level !== 'ward')
            <div class="stat"><b>{{ number_format($level === 'state' ? $children->sum('wards') : $children->count()) }}</b><span>Wards</span></div>
        @endif
        @if ($showVolunteers)
            <div class="stat"><b>{{ number_format($children->sum('volunteers')) }}</b><span>Volunteers signed up</span></div>
        @endif
        <div class="stat"><b>{{ number_format($children->sum('agents')) }}</b><span>Agents assigned</span></div>
    @else
        <div class="stat"><b>{{ $unit->registered_voters === null ? '—' : number_format($unit->registered_voters) }}</b><span>On the PU register</span></div>
    @endif
</div>

@if ($level !== 'pu' && $unitsWithVoters === 0 && ($level === 'state' || ! $official))
    <div class="card vi-note">
        <p><b>Registered voters {{ $level === 'state' ? 'per LGA, ward and polling unit' : 'for '.$title }} aren't loaded yet</b>, so they show “—”. Nothing is estimated. They appear as soon as INEC's figures are loaded: registered voters per polling unit fill in every level, or an LGA or ward total can be loaded on its own.@if ($canManage && $level === 'state') See <a href="#data">Load figures</a> below.@elseif ($canManage) See <a href="{{ route('intelligence') }}#data">Load figures</a> on the Ebonyi page.@endif</p>
    </div>
@endif

@if ($insights)
    <section class="card vi-target">
        <h2>Who to target here</h2>
        <ul class="vi-insights">
            @foreach ($insights as $insight)
                <li>{{ $insight['text'] }}@if ($insight['from']) <span class="badge {{ str_starts_with($insight['from'], 'Nigeria') ? 'warn' : '' }}">{{ $insight['from'] }}</span>@endif</li>
            @endforeach
        </ul>
        @if (collect($insights)->contains(fn ($i) => $i['from'] && str_starts_with($i['from'], 'Nigeria')))
            <p class="small muted">Figures marked “Nigeria (national)” are INEC's national profile: INEC hasn't published these for {{ $state }} or below, so they show the general picture, not this area's.</p>
        @endif
    </section>
@endif

<h2 class="vi-h2">Who the voters are</h2>
<div class="grid vi-profile">
    @foreach (\App\Models\VoterStat::DIMENSIONS as $key => [$label])
        @continue($key === 'registered')
        @php $dimension = $profile[$key] ?? null; @endphp
        <section class="card">
            <h3>{{ $label }}</h3>
            @if ($dimension)
                <p class="small"><span class="badge {{ $fromBadge($dimension) }}">{{ $dimension['inherited'] ? 'No local figures · showing '.$dimension['from'] : 'Figures for '.$dimension['from'] }}</span></p>
                <ul class="bars">
                    @php $top = max(1, collect($dimension['rows'])->max(fn ($row) => $row['percent'] ?? 0)); @endphp
                    @foreach ($dimension['rows'] as $row)
                        <li class="bar-row">
                            <span class="who">{{ $row['label'] }}</span>
                            <span class="val">{{ $pct($row['percent']) }}@if ($row['count'] !== null) <small>{{ number_format($row['count']) }}</small>@endif</span>
                            @if ($row['percent'] !== null)<span class="track slim"><span class="fill slot1" style="width: {{ min(100, $row['percent'] / $top * 100) }}%"></span></span>@endif
                        </li>
                    @endforeach
                </ul>
                <p class="small muted vi-source">Source: @foreach ($dimension['sources'] as $source)@if ($source['url'])<a href="{{ $source['url'] }}" target="_blank" rel="noopener">{{ $source['source'] }}</a>@else{{ $source['source'] }}@endif{{ $source['as_of'] ? ', '.$source['as_of']->format('j M Y') : '' }}{{ $loop->last ? '' : '; ' }}@endforeach</p>
            @else
                <p class="muted small">No figures loaded yet. {{ $missingHint[$key] ?? '' }}</p>
            @endif
        </section>
    @endforeach
</div>

@if ($children->isNotEmpty())
    <h2 class="vi-h2">{{ ucfirst($childLabel) }} <small class="muted">· most voters first</small></h2>
    <div class="card flush">
        <div class="table-wrap">
            <table class="stack vi-table">
                <thead>
                    <tr>
                        <th>{{ $level === 'ward' ? 'Polling unit' : ucfirst(\Illuminate\Support\Str::singular($childLabel)) }}</th>
                        <th class="num">Registered voters</th>
                        <th class="num">Share</th>
                        @if ($level !== 'ward')<th class="num">PUs</th>@endif
                        @if ($showWards)<th class="num">Wards</th>@endif
                        @if ($anyPvc)<th class="num">PVCs not collected</th>@endif
                        @if ($anyFirst)<th class="num">First-time voters</th>@endif
                        @if ($showVolunteers)<th class="num">Volunteers</th>@endif
                        <th class="num">Agents</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($children as $child)
                        <tr>
                            <td class="key"><a class="rowlink" href="{{ $childLink($child) }}">{{ $child['name'] }}</a>@if ($child['inec_code'])<small class="muted vi-code">{{ $child['inec_code'] }}</small>@endif</td>
                            <td class="num vi-voters" data-label="Registered voters">
                                {{ $fmt($child['voters']) }}
                                @if ($child['voters'] !== null)<span class="track slim"><span class="fill slot1" style="width: {{ $child['voters'] / $maxVoters * 100 }}%"></span></span>@endif
                                @if ($child['voters'] === null && $child['units_with_voters'] > 0)<small class="muted">{{ $child['units_with_voters'] }}/{{ $child['units'] }} PUs known</small>@endif
                            </td>
                            <td class="num" data-label="Share">{{ $child['voters'] !== null && $voters ? $pct($child['voters'] / $voters * 100) : '—' }}</td>
                            @if ($level !== 'ward')<td class="num" data-label="PUs">{{ number_format($child['units']) }}</td>@endif
                            @if ($showWards)<td class="num" data-label="Wards">{{ $child['wards'] }}</td>@endif
                            @if ($anyPvc)<td class="num" data-label="PVCs not collected">{{ $fmt($child['pvc_uncollected']) }}</td>@endif
                            @if ($anyFirst)<td class="num" data-label="First-time voters">{{ $fmt($child['first_time']) }}</td>@endif
                            @if ($showVolunteers)<td class="num" data-label="Volunteers">{{ $child['volunteers'] ?: '—' }}</td>@endif
                            <td class="num" data-label="Agents">@if ($child['agents']){{ $child['agents'] }}@elseif ($level === 'ward')<span class="badge warn">None</span>@else — @endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($sources->isNotEmpty())
    <section class="card vi-sources">
        <h2>Sources on this page</h2>
        <ul>
            @foreach ($sources as $source)
                <li>@if ($source['url'])<a href="{{ $source['url'] }}" target="_blank" rel="noopener">{{ $source['source'] }}</a>@else{{ $source['source'] }}@endif{{ $source['as_of'] ? ' ('.$source['as_of']->format('j M Y').')' : '' }}</li>
            @endforeach
            <li>Polling units, wards and LGAs: INEC's polling unit register.</li>
        </ul>
    </section>
@endif

@if ($level === 'state' && ($canManage || $canExport))
    <section class="card" id="data">
        <h2>Load figures</h2>
        @if ($canManage)
            <form method="post" action="{{ route('intelligence.voters') }}" enctype="multipart/form-data" class="vi-form">
                @csrf
                <h3>Registered voters per polling unit</h3>
                <p class="small muted">CSV with <code>code,registered_voters</code> (codes like 11/01/01/001), from INEC's register. Updates the PU register; nothing is saved if a row is wrong.</p>
                <input type="file" name="file" accept=".csv,text/csv" required>
                <button class="button secondary" type="submit">Upload voters per PU</button>
            </form>
            <form method="post" action="{{ route('intelligence.figures') }}" enctype="multipart/form-data" class="vi-form">
                @csrf
                <h3>Other figures (PVCs, first-time voters, gender, age, occupation…)</h3>
                <p class="small muted">CSV with <code>{{ implode(',', \App\Services\VoterStatImporter::COLUMNS) }}</code>. Each row is one figure for Nigeria, the state, an LGA, a ward or a PU, with a count or a percent and its source. A figure for the same place and category is replaced. <a href="{{ route('intelligence.template') }}" data-no-progress>Download the template</a> (its example rows can't be uploaded as they are).</p>
                <p class="small muted">Dimensions: @foreach (\App\Models\VoterStat::DIMENSIONS as $key => [$label, $categories])<code>{{ $key }}</code>@if ($categories) ({{ implode(', ', array_keys($categories)) }})@endif{{ $loop->last ? '.' : '; ' }}@endforeach</p>
                <input type="file" name="file" accept=".csv,text/csv" required>
                <button class="button secondary" type="submit">Upload figures</button>
            </form>
            @if ($loaded->isNotEmpty())
                <h3>Loaded</h3>
                <ul class="vi-loaded">
                    @foreach ($loaded as $set)
                        <li>
                            <span><b>{{ $set->source }}</b> <span class="muted small">· {{ $set->figures }} {{ $set->figures == 1 ? 'figure' : 'figures' }}</span></span>
                            <form method="post" action="{{ route('intelligence.remove') }}" onsubmit="return confirm(@js('Remove all '.$set->figures.' figures from “'.$set->source.'”?'))">
                                @csrf
                                <input type="hidden" name="source" value="{{ $set->source }}">
                                <button class="button secondary" type="submit">Remove</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
        @if ($canExport)
            <p class="small"><a class="button secondary" href="{{ route('intelligence.register') }}" data-no-progress>Download the PU register (CSV)</a> <span class="muted">With voter numbers, in the format both this app and the USSD service import.</span></p>
        @endif
    </section>
@endif
@endsection
