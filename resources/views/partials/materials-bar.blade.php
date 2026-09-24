{{-- Materials status for an area. Expects $tally (MonitorTally). Colour is backed by the text below it. --}}
@php
    $m = $tally->materials;
    $problems = array_filter([($m['incomplete'] ?? 0) ? $m['incomplete'].' incomplete' : null, ($m['not_arrived'] ?? 0) ? $m['not_arrived'].' not arrived' : null]);
    $segments = [['good', $m['arrived'] ?? 0, 'arrived'], ['warn', $m['incomplete'] ?? 0, 'incomplete'], ['bad', $m['not_arrived'] ?? 0, 'not arrived'], ['none', $m['none'] ?? 0, 'no report']];
@endphp
<span class="cell-stack">
    <span class="statusbar" aria-hidden="true">
        @foreach ($segments as [$class, $count])
            @if ($count > 0)<span class="{{ $class }}" style="flex: {{ $count }}"></span>@endif
        @endforeach
    </span>
    <span class="small">{{ $m['arrived'] ?? 0 }} arrived @foreach ($problems as $problem) · <b>{{ $problem }}</b>@endforeach</span>
</span>
