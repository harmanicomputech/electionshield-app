<?php

namespace App\Http\Controllers;

use App\Support\BackgroundRunner;
use Illuminate\Http\JsonResponse;

/**
 * GET /cron/{token}: for a free external pinger (cron-job.org, UptimeRobot)
 * on hosts without per-minute cron. The token is shown on the System page.
 */
class RunnerController extends Controller
{
    public function __invoke(string $token, BackgroundRunner $runner): JsonResponse
    {
        abort_unless(hash_equals(BackgroundRunner::token(), $token), 404);

        $ran = $runner->run(queueSeconds: 40, source: 'pinger');

        return response()->json(['status' => $ran ? 'ran' : 'busy', 'at' => now()->toIso8601String()]);
    }
}
