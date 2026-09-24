<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Models\Incident;
use App\Models\Result;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Which new records alert coordinators' devices: urgent incidents and
 * corrections waiting for review. They fire when the record is first
 * stored here, by webhook or by the catch-up sync, and are sent after the
 * response (or command) so the webhook reply isn't held up. Old records (a full
 * import, a backfill) never alert.
 */
class PushAlerts
{
    private const RECENT_MINUTES = 30;

    public static function incidentCreated(Incident $incident): void
    {
        if (! $incident->urgent || ! self::recent($incident->reported_at)) {
            return;
        }

        $place = $incident->pollingUnit?->name ?? 'PU '.$incident->polling_unit_code;
        $area = collect([$incident->lga, $incident->ward])->filter()->implode(' › ');

        self::send('urgent_incidents', [
            'title' => ($incident->rehearsal ? '[Rehearsal] ' : '').'⚠ '.$incident->label().' reported',
            'body' => trim($place.($area ? " ({$area})" : '').($incident->note ? ': '.Str::limit($incident->note, 90) : '')),
            'url' => route('incidents', ['status' => 'open', 'urgent' => 1], false).'#incident-'.$incident->reference,
            'tag' => 'incident-'.$incident->reference,
            'urgent' => true,
        ]);
    }

    public static function resultCreated(Result $result): void
    {
        if ($result->status !== ResultStatus::Pending || ! $result->corrects_reference || ! self::recent($result->submitted_at)) {
            return;
        }

        self::send('corrections', [
            'title' => ($result->rehearsal ? '[Rehearsal] ' : '').'Correction waiting for review',
            'body' => ($result->pollingUnit?->name ?? 'PU '.$result->polling_unit_code).": {$result->reference} corrects {$result->corrects_reference}",
            'url' => config('services.ussd.console_url').'/corrections',
            'tag' => 'correction-'.$result->reference,
        ]);
    }

    private static function recent(?Carbon $time): bool
    {
        // A time far in the future is a wrong clock, not a new report.
        return $time !== null && $time->between(now()->subMinutes(self::RECENT_MINUTES), now()->addMinutes(5));
    }

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private static array $pending = [];

    private static ?int $registeredFor = null;

    /**
     * Queue the alert to go out once the response has been sent (or the
     * command has finished). Each alert is sent once, however many
     * requests the app instance handles.
     *
     * @param  array<string, mixed>  $message
     */
    private static function send(string $topic, array $message): void
    {
        if (self::$registeredFor !== spl_object_id(app())) {
            // A new app instance: nothing left over from an earlier one.
            self::$pending = [];
            self::$registeredFor = spl_object_id(app());
            app()->terminating(fn () => self::flush());
        }

        self::$pending[] = [$topic, $message];
    }

    public static function flush(): void
    {
        [$pending, self::$pending] = [self::$pending, []];

        foreach ($pending as [$topic, $message]) {
            app(PushNotifier::class)->toTopic($topic, $message);
        }
    }
}
