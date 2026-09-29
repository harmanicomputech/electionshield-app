{{-- Time frame chips. Expects $range and the route parameters to keep in $keep. --}}
<nav class="range-chips" aria-label="Time frame">
    @foreach (\App\Services\PeopleLocations::RANGES as $key => [$label])
        <a href="{{ route($routeName, [...$keep, 'range' => $key]) }}" class="{{ $range === $key ? 'on' : '' }}" @if ($range === $key) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
</nav>
