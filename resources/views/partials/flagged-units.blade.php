{{-- Flagged PUs. Expects $flagged. --}}
@php($parties = \App\Services\Collation::parties())
@if ($flagged->isEmpty())
    <p class="muted">No PU differs from IReV by {{ config('election.discrepancy_votes') }} votes or more, and none is missing from IReV.</p>
@else
    <ul class="list two">
        @foreach ($flagged->take(100) as $row)
            <li class="item {{ in_array('discrepancy', $row['flags'], true) ? 'urgent' : '' }}">
                <div class="badges" style="margin-top:0">
                    @if (in_array('discrepancy', $row['flags'], true))<span class="badge bad">✗ Differs by up to {{ $row['max_diff'] }} votes</span>@endif
                    @if (in_array('no_upload', $row['flags'], true))<span class="badge warn">No IReV upload</span>@endif
                </div>
                <h3><a class="rowlink" href="{{ route('official.pu', $row['code']) }}">{{ $row['unit']?->name ?? 'PU '.$row['code'] }}</a></h3>
                <div class="meta">{{ $row['unit']?->inecCode() ?? $row['code'] }} · {{ $row['lga'] }} › {{ $row['ward'] }} · <a href="{{ route('evidence', $row['code']) }}">Evidence pack</a></div>
                @if ($row['diff'] !== [])
                    @php($ours = $row['pvt']->votesByParty())
                    <dl class="kv small" style="margin-top:8px">
                        @foreach ($parties as $party)
                            <dt>{{ $party }}</dt>
                            <dd>PVT {{ number_format($ours[$party] ?? 0) }} → IReV {{ number_format($row['official']->votesByParty()[$party]) }}
                                @if ($row['diff'][$party] !== 0)<b>({{ $row['diff'][$party] > 0 ? '+' : '' }}{{ $row['diff'][$party] }})</b>@endif
                            </dd>
                        @endforeach
                    </dl>
                @endif
            </li>
        @endforeach
    </ul>
    @if ($flagged->count() > 100)<p class="small muted">Showing 100 of {{ $flagged->count() }}. The export has them all.</p>@endif
@endif
