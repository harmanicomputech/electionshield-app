<?php

use App\Http\Middleware\RecordUserLocation;
use App\Http\Middleware\RunBackgroundWork;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RunBackgroundWork::class);
        $middleware->appendToGroup('web', RecordUserLocation::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        // The agent upload link's token is its authorisation, and uploads
        // queued offline may be sent long after the page's CSRF token expired.
        $middleware->validateCsrfTokens(except: ['u/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Not set up yet (empty database): send people to the set-up page
        // instead of an error, and drop any login cookie from an earlier install.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*', 'login', 'setup')) {
                return null;
            }

            try {
                if (Schema::hasTable('users')) {
                    return null;
                }
            } catch (Throwable) {
                return null;
            }

            return redirect('/login')->withCookie(cookie()->forget(Auth::guard('web')->getRecallerName()));
        });
    })->create();
