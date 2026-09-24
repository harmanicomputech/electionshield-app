{{-- Party vote shares for one area, with direct labels. Expects $tally. --}}
@php($total = $tally->totalVotes())
<ul class="bars">
    @foreach ($tally->votes as $party => $votes)
        @php($share = $tally->share($party))
        <li class="bar-row" title="{{ $party }}: {{ number_format($votes) }} votes ({{ $share }}%)">
            <span class="who"><span class="sw {{ \App\Support\Party::slot($party) }}"></span>{{ $party }} <small>{{ \App\Support\Party::candidate($party) ?? ($party === 'OTHERS' ? 'all other parties' : '') }}</small></span>
            <span class="val">{{ $share }}% <small>{{ number_format($votes) }}</small></span>
            <span class="track" aria-hidden="true"><span class="fill {{ \App\Support\Party::slot($party) }}" style="width: {{ $total ? $share : 0 }}%"></span></span>
        </li>
    @endforeach
</ul>
