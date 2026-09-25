<?php

namespace App\Http\Middleware;

use App\Support\BackgroundRunner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After the response has gone to the browser, run any due background work
 * (see BackgroundRunner). Only where PHP can finish the response first, so
 * nobody waits for it.
 */
class RunBackgroundWork
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! BackgroundRunner::enabled() || ! BackgroundRunner::canWorkAfterResponse() || $request->routeIs('runner')) {
            return;
        }

        app(BackgroundRunner::class)->runAfterWebRequest();
    }
}
