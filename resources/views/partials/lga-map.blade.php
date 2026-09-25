{{-- Schematic LGA map. Expects $map from App\Services\LgaMap::build(); optional $title. --}}
@php
    $keys = array_keys($map['layers']);
    $active = in_array(request('map'), $keys, true) ? request('map') : $keys[0];
@endphp
<section class="card lga-map" data-lga-map data-active="{{ $active }}" aria-labelledby="map-title">
    <div class="map-head">
        <h2 id="map-title">{{ $title ?? 'LGA map' }}</h2>
        @if (count($keys) > 1)
            <div class="map-layers" role="tablist" aria-label="Show on the map">
                @foreach ($map['layers'] as $key => $layer)
                    <a href="{{ request()->fullUrlWithQuery(['map' => $key]) }}" role="tab" class="{{ $key === $active ? 'on' : '' }}" aria-selected="{{ $key === $active ? 'true' : 'false' }}" data-map-layer="{{ $key }}">{{ $layer['label'] }}</a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="map-grid">
        @foreach ($map['tiles'] as $name => $tile)
            @php($cell = $tile['layers'][$active])
            <a class="tile {{ $cell['class'] }}" href="{{ $tile['link'] }}" style="grid-column: {{ $tile['col'] }}; grid-row: {{ $tile['row'] }}" title="{{ $name }}: {{ $cell['title'] }}"
               data-cells='@json($tile['layers'])'>
                <span class="tile-name">{{ $name }}</span>
                <span class="tile-value" data-tile-value>{{ $cell['value'] }}</span>
            </a>
        @endforeach
    </div>

    @foreach ($map['layers'] as $key => $layer)
        <ul class="legend map-legend" data-map-legend="{{ $key }}" @if ($key !== $active) hidden @endif>
            @foreach ($layer['legend'] as [$class, $text])
                <li><i class="tile-swatch {{ $class }}"></i>{{ $text }}</li>
            @endforeach
        </ul>
    @endforeach
    <p class="small muted" style="margin:8px 0 0">Schematic: each LGA is a tile placed roughly where it lies in the state (north at the top), not to scale. Tap an LGA for its figures.</p>
</section>
