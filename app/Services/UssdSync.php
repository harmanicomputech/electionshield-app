<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\Presence;
use App\Models\Result;
use App\Models\SyncState;
use App\Support\Time;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pulls from the USSD read API: the first full import, then every few
 * minutes only what changed since the last server_time seen (minus a minute
 * of overlap), catching anything a webhook missed. Records are upserted, so
 * the overlap and repeated runs are harmless.
 */
class UssdSync
{
    /** Register and agents first, so records find their PU. */
    public const RESOURCES = ['polling-units', 'agents', 'results', 'incidents', 'presences', 'materials'];

    private const PER_PAGE = 500;

    private const MAX_PAGES = 200;

    public function __construct(private UssdIngestor $ingestor) {}

    public function enabled(): bool
    {
        return filled(config('services.ussd.api_url')) && filled(config('services.ussd.api_token'));
    }

    /**
     * @return array<string, array{count: int, error: ?string}>
     */
    public function run(bool $full = false): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Set USSD_API_URL and USSD_API_TOKEN in .env to sync from the USSD service.');
        }

        $report = [];

        foreach (self::RESOURCES as $resource) {
            $report[$resource] = $this->syncResource($resource, $full);
        }

        return $report;
    }

    /**
     * @return array{count: int, error: ?string}
     */
    public function syncResource(string $resource, bool $full = false): array
    {
        $state = SyncState::firstOrNew(['resource' => $resource]);
        $state->last_run_at = now();

        $query = ['per_page' => self::PER_PAGE];

        if (! $full && $state->synced_until) {
            $query['updated_since'] = $state->synced_until->copy()->subMinute()->toIso8601String();
        }

        $count = 0;
        $serverTime = null;

        try {
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $response = $this->get($resource, $query);

                // Anything changed after the first page was served is
                // picked up next run, so resume from its server_time.
                $serverTime ??= $response['server_time'] ?? null;

                DB::transaction(function () use ($resource, $response, &$count) {
                    foreach ($response['data'] ?? [] as $record) {
                        $this->apply($resource, $record, (bool) ($response['rehearsal_mode'] ?? false));
                        $count++;
                    }
                });

                if (blank($response['next_cursor'] ?? null)) {
                    break;
                }

                $query['cursor'] = $response['next_cursor'];
            }
        } catch (Throwable $e) {
            report($e);
            $state->fill(['last_count' => $count, 'last_error' => Str::limit($e->getMessage(), 1000)])->save();

            return ['count' => $count, 'error' => $e->getMessage()];
        }

        $state->fill([
            'synced_until' => $serverTime ? Time::parse($serverTime) : now(),
            'last_success_at' => now(),
            'last_count' => $count,
            'last_error' => null,
        ])->save();

        return ['count' => $count, 'error' => null];
    }

    /**
     * Pulled records carry no rehearsal flag: a record we already hold
     * keeps the flag its webhook event gave it, and a new one takes the
     * response's rehearsal_mode.
     *
     * @param  array<string, mixed>  $record
     */
    private function apply(string $resource, array $record, bool $rehearsalMode): void
    {
        $rehearsal = array_key_exists('rehearsal', $record) ? (bool) $record['rehearsal'] : null;

        match ($resource) {
            'polling-units' => $this->ingestor->pollingUnit($record),
            'agents' => $this->ingestor->agent($record),
            'results' => $this->ingestor->result($record, $rehearsal ?? $this->existingRehearsal(Result::class, 'reference', $record['reference'] ?? null, $rehearsalMode)),
            'incidents' => $this->ingestor->incident($record, $rehearsal ?? $this->existingRehearsal(Incident::class, 'reference', $record['reference'] ?? null, $rehearsalMode)),
            'presences' => $this->ingestor->presence($record, $rehearsal ?? $this->existingRehearsal(Presence::class, 'ussd_id', $record['id'] ?? null, $rehearsalMode)),
            'materials' => $this->ingestor->materials($record, $rehearsal ?? $this->existingRehearsal(MaterialReport::class, 'ussd_id', $record['id'] ?? null, $rehearsalMode)),
        };
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function existingRehearsal(string $model, string $key, mixed $value, bool $default): bool
    {
        $existing = $value === null ? null : $model::query()->where($key, $value)->value('rehearsal');

        return $existing === null ? $default : (bool) $existing;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $resource, array $query): array
    {
        return Http::baseUrl(config('services.ussd.api_url'))
            ->withToken(config('services.ussd.api_token'))
            ->acceptJson()
            ->timeout(config('services.ussd.timeout'))
            ->retry(2, 1000, throw: true)
            ->get('/'.$resource, $query)
            ->throw()
            ->json() ?? [];
    }
}
