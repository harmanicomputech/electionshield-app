<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The USSD service signs every event: Bearer {USSD_WEBHOOK_TOKEN} and
 * X-Election-Shield-Signature: sha256=<HMAC-SHA256 of the raw body>.
 * Both must be configured; unsigned events are never accepted.
 */
class VerifyUssdWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.ussd.webhook_token');
        $secret = (string) config('services.ussd.webhook_secret');

        if ($token === '' || $secret === '') {
            return response()->json(['message' => 'The webhook is not configured.'], 503);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($token, (string) $request->bearerToken())
            || ! hash_equals($expected, (string) $request->header('X-Election-Shield-Signature'))) {
            return response()->json(['message' => 'Invalid token or signature.'], 401);
        }

        return $next($request);
    }
}
