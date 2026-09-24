{{-- Headline monitoring figures. Expects $total (MonitorTally). --}}
<div class="stats">
    <div class="stat"><b>{{ number_format($total->checkedIn) }}<small class="muted"> / {{ number_format($total->units) }}</small></b><span>Agents checked in ({{ $total->percent($total->checkedIn) }}%)</span></div>
    <div class="stat"><b>{{ number_format($total->materials['arrived'] ?? 0) }}</b><span>Materials arrived · {{ ($total->materials['incomplete'] ?? 0) + ($total->materials['not_arrived'] ?? 0) }} with problems</span></div>
    <div class="stat"><b>{{ number_format($total->results) }}</b><span>Results in ({{ $total->percent($total->results) }}%)</span></div>
    <a class="stat" href="{{ route('incidents') }}" style="text-decoration:none;color:inherit"><b>{{ number_format($total->openIncidents) }}</b><span>Unresolved incidents{{ $total->urgentIncidents ? ' · '.$total->urgentIncidents.' urgent' : '' }} →</span></a>
</div>
