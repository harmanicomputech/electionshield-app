{{-- Monitoring counts for a list of areas. Expects $areas (MonitorTally by name), $label, $link (fn(name): url), $total. --}}
<div class="table-wrap">
    <table class="stack">
        <thead>
            <tr>
                <th>{{ $label }}</th>
                <th class="num">Checked in</th>
                <th>Materials</th>
                <th class="num">Results</th>
                <th class="num">Open incidents</th>
                <th class="num">Need attention</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($areas as $name => $area)
                <tr>
                    <td class="key"><a class="rowlink" href="{{ $link($name) }}">{{ $name }}</a></td>
                    <td class="num" data-label="Checked in">{{ $area->checkedIn }} / {{ $area->units }} <span class="muted">({{ $area->percent($area->checkedIn) }}%)</span></td>
                    <td class="full" data-label="Materials">@include('partials.materials-bar', ['tally' => $area])</td>
                    <td class="num" data-label="Results">{{ $area->results }} / {{ $area->units }}</td>
                    <td class="num" data-label="Open incidents">{{ $area->openIncidents }}@if ($area->urgentIncidents) <span class="badge bad">{{ $area->urgentIncidents }} urgent</span>@endif</td>
                    <td class="num" data-label="Need attention">@if ($area->needsAttention)<span class="badge warn">{{ $area->needsAttention }} PUs</span>@else<span class="badge good">✓ None</span>@endif</td>
                </tr>
            @endforeach
            <tr class="total">
                <td class="key">Total</td>
                <td class="num" data-label="Checked in">{{ $total->checkedIn }} / {{ $total->units }}</td>
                <td class="full" data-label="Materials">@include('partials.materials-bar', ['tally' => $total])</td>
                <td class="num" data-label="Results">{{ $total->results }} / {{ $total->units }}</td>
                <td class="num" data-label="Open incidents">{{ $total->openIncidents }}</td>
                <td class="num" data-label="Need attention">{{ $total->needsAttention }} PUs</td>
            </tr>
        </tbody>
    </table>
</div>
<p class="legend" style="margin-top:8px"><span><i class="good"></i>Materials arrived</span><span><i class="warn"></i>Incomplete</span><span><i class="bad"></i>Not arrived</span><span><i class="none"></i>No report</span></p>
<p class="small muted">"Need attention": no agent checked in, materials incomplete or not arrived, or an urgent incident still open.</p>
