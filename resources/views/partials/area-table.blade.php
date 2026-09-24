{{-- Collation table for a list of areas. Expects $areas (Tally by name), $link (fn(name): url), $label, and optional $total. --}}
@php($parties = \App\Services\Collation::parties())
@php($candidates = \App\Services\Collation::candidates())
<div class="table-wrap">
    <table class="stack">
        <thead>
            <tr>
                <th>{{ $label }}</th>
                <th>Leading</th>
                <th class="num">PUs reported</th>
                @foreach ($parties as $party)<th class="num">{{ $party }}</th>@endforeach
                <th class="num">Valid</th>
                <th class="num">Rejected</th>
                <th class="num">Turnout</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($areas as $name => $area)
                @php($leader = $area->leader($candidates))
                <tr>
                    <td class="key">
                        <a class="rowlink" href="{{ $link($name) }}">{{ $name }}</a>
                        <button type="button" class="more-btn" data-toggle-row aria-expanded="false">Details</button>
                    </td>
                    <td data-label="Leading">@if ($leader)<span class="sw {{ \App\Support\Party::slot($leader) }}"></span> {{ $leader }} {{ $area->share($leader) }}%@else<span class="muted">—</span>@endif</td>
                    <td class="num" data-label="PUs reported">{{ $area->reported }} / {{ $area->units }} <span class="muted">({{ $area->coverage() }}%)</span></td>
                    @foreach ($parties as $party)
                        <td class="num detail" data-label="{{ $party }}">{{ number_format($area->votes[$party] ?? 0) }} <span class="muted">{{ $area->share($party) }}%</span></td>
                    @endforeach
                    <td class="num detail" data-label="Valid votes">{{ number_format($area->totalVotes()) }}</td>
                    <td class="num detail" data-label="Rejected">{{ number_format($area->rejected) }}</td>
                    <td class="num detail" data-label="Turnout">{{ $area->turnout() === null ? '—' : $area->turnout().'%' }}</td>
                </tr>
            @endforeach
            @isset($total)
                <tr class="total">
                    <td class="key">Total</td>
                    <td data-label="Leading">@php($leader = $total->leader($candidates)){{ $leader ? $leader.' '.$total->share($leader).'%' : '—' }}</td>
                    <td class="num" data-label="PUs reported">{{ $total->reported }} / {{ $total->units }}</td>
                    @foreach ($parties as $party)
                        <td class="num" data-label="{{ $party }}">{{ number_format($total->votes[$party] ?? 0) }} <span class="muted">{{ $total->share($party) }}%</span></td>
                    @endforeach
                    <td class="num" data-label="Valid votes">{{ number_format($total->totalVotes()) }}</td>
                    <td class="num" data-label="Rejected">{{ number_format($total->rejected) }}</td>
                    <td class="num" data-label="Turnout">{{ $total->turnout() === null ? '—' : $total->turnout().'%' }}</td>
                </tr>
            @endisset
        </tbody>
    </table>
</div>
