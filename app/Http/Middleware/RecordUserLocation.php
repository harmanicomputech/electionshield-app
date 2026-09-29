<?php

namespace App\Http\Middleware;

use App\Services\LocationRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the location app.js sends with each action (requests that change something carry
 * X-ES-Location; plain forms a _es_location field) for people whose role has "Location is recorded".
 */
class RecordUserLocation
{
    public function __construct(private LocationRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user && ! $request->isMethodSafe() && ! $request->routeIs('location.ping', 'field.presence')
            && $response->getStatusCode() < 400 && ($location = LocationRecorder::parse($request->header('X-ES-Location') ?? $request->input('_es_location')))) {
            $this->recorder->record($user, $location, $this->describe($request));
        }

        return $response;
    }

    private function describe(Request $request): string
    {
        $route = $request->route();
        $name = (string) ($route?->getName() ?? $request->path());
        $subject = collect($route?->parameters() ?? [])->map(fn ($value) => is_object($value) ? ($value->reference ?? $value->getKey()) : $value)->implode(' ');

        return trim(str_replace(['.', '-'], ' ', $name).($subject !== '' ? " {$subject}" : ''));
    }
}
