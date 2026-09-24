{{-- Expects $summary from CompareController. --}}
<div class="stats">
    <div class="stat"><b>{{ number_format($summary['compared']) }}</b><span>PUs compared (PVT and IReV)</span></div>
    <div class="stat"><b>{{ number_format($summary['discrepancy']) }}</b><span>Differ by {{ config('election.discrepancy_votes') }}+ votes</span></div>
    <div class="stat"><b>{{ number_format($summary['no_upload']) }}</b><span>No upload on IReV</span></div>
    <div class="stat"><b>{{ number_format($summary['not_entered']) }}</b><span>PVT results with no IReV entry yet</span></div>
</div>
