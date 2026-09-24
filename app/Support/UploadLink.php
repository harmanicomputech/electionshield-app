<?php

namespace App\Support;

/**
 * No-login EC8A upload links for agents, one per result reference:
 *
 *   {APP_URL}/u/{reference}/{token}
 *   token = first 20 hex chars of HMAC-SHA256("ec8a:{reference}", USSD_WEBHOOK_SECRET)
 *
 * The USSD service holds the same secret (DASHBOARD_WEBHOOK_SECRET), so it
 * can put the link in the agent's SMS receipt without asking this app.
 */
class UploadLink
{
    public static function token(string $reference): string
    {
        return substr(hash_hmac('sha256', 'ec8a:'.$reference, (string) config('services.ussd.webhook_secret')), 0, 20);
    }

    public static function valid(string $reference, string $token): bool
    {
        return filled(config('services.ussd.webhook_secret')) && hash_equals(self::token($reference), $token);
    }

    public static function url(string $reference): string
    {
        return route('upload.agent', [$reference, self::token($reference)]);
    }
}
