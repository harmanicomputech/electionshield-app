{{-- PVT against declared collations. Expects $collations, $label, $form (EC8B/EC8C), $link (fn(name): url), $enter (fn(name): url). --}}
@php($candidates = \App\Services\Collation::candidates())
<div class="table-wrap">
    <table class="stack">
        <thead>
            <tr>
                <th>{{ $label }}</th>
                <th>PVT coverage</th>
                @foreach ($candidates as $party)<th class="num">{{ $party }} PVT → {{ $form }}</th>@endforeach
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($collations as $row)
                @php($pvt = $row['pvt'])
                @php($declared = $row['declared'])
                <tr>
                    <td class="key"><a class="rowlink" href="{{ $link($row['name']) }}">{{ $row['name'] }}</a></td>
                    <td data-label="PVT coverage">{{ $pvt->reported }} / {{ $pvt->units }} PUs ({{ $pvt->coverage() }}%)</td>
                    @foreach ($candidates as $party)
                        <td class="num" data-label="{{ $party }} PVT → {{ $form }}">
                            {{ $pvt->totalVotes() ? $pvt->share($party).'%' : '—' }} →
                            @if ($declared)
                                {{ $declared->totalValidVotes() ? round(100 * $declared->votesByParty()[$party] / $declared->totalValidVotes(), 2).'%' : '—' }}
                                @isset($row['share_diff'][$party])<span class="muted">({{ $row['share_diff'][$party] > 0 ? '+' : '' }}{{ $row['share_diff'][$party] }})</span>@endisset
                            @else
                                <span class="muted">not entered</span>
                            @endif
                        </td>
                    @endforeach
                    <td data-label="Status">
                        @if (! $declared)
                            <a class="badge" href="{{ $enter($row['name']) }}">Enter {{ $form }}</a>
                        @elseif ($row['flagged'])
                            <span class="badge bad">✗ Differs {{ $row['max_share_diff'] }} pts</span>
                        @elseif ($row['share_diff'] === [])
                            <span class="badge">No PVT figures yet</span>
                        @else
                            <span class="badge good">✓ Consistent</span>
                        @endif
                        @if ($declared && ! $row['complete'])<span class="badge warn">PVT partial</span>@endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<p class="small muted">Shares of valid votes, PVT → declared, with the difference in percentage points. Flagged at {{ rtrim(rtrim(number_format(config('election.discrepancy_share_points'), 1), '0'), '.') }} points or more (or {{ config('election.discrepancy_votes') }}+ votes once every PU has reported). With partial coverage the PVT share is an estimate.</p>
