<?php

namespace App\Services\Irev;

use App\Models\IrevDocument;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Services\Collation;
use App\Services\IrevSheetReader;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Follows one election on IReV: walks its wards a few at a time (from the
 * background runner), notices every EC8A upload, matches the polling unit
 * to our register, downloads the sheet, has it read by AI, and saves the
 * figures as the PU's official result, marked as needing a person's check.
 * Results a person entered are never overwritten.
 */
class IrevWatcher
{
    public const WARDS_PER_RUN = 15;

    public const SHEETS_PER_RUN = 6;

    private const MAX_ATTEMPTS = 3;

    /** @var array<string, string>|null our PUs by matching key */
    private ?array $index = null;

    public function __construct(private IrevClient $client, private IrevSheetReader $reader) {}

    public function configured(): bool
    {
        return filled(Settings::get('irev.election_oid')) && filled(Settings::get('irev.election_id'));
    }

    public function automatic(): bool
    {
        return $this->configured() && Settings::get('irev.auto') === '1';
    }

    /**
     * Elections on IReV for this state, newest first.
     *
     * @return list<array{oid: string, id: int, name: string, date: ?string}>
     */
    public function stateElections(): array
    {
        $state = Str::upper((string) config('election.state', 'Ebonyi'));

        return collect($this->client->elections())
            ->filter(fn ($election) => is_array($election) && str_contains(Str::upper((string) ($election['full_name'] ?? '')), $state))
            ->map(fn (array $election) => [
                'oid' => (string) ($election['_id'] ?? ''),
                'id' => (int) ($election['election_id'] ?? 0),
                'name' => (string) $election['full_name'],
                'date' => isset($election['election_date']) ? substr((string) $election['election_date'], 0, 10) : null,
            ])
            ->filter(fn (array $election) => $election['oid'] !== '' && $election['id'] > 0)
            ->sortByDesc('date')->values()->all();
    }

    public function follow(string $oid, int $id, string $name): void
    {
        Settings::set('irev.election_oid', $oid);
        Settings::set('irev.election_id', (string) $id);
        Settings::set('irev.election_name', $name);
        Settings::set('irev.wards', null);
        Settings::set('irev.ward_cursor', '0');
        Settings::set('irev.last_error', null);
    }

    public function setAutomatic(bool $on): void
    {
        Settings::set('irev.auto', $on ? '1' : '0');
    }

    /**
     * One background step: look at the next few wards, then fetch and read
     * a few new sheets.
     *
     * @return array{wards: int, sheets: int}
     */
    public function step(int $wards = self::WARDS_PER_RUN, int $sheets = self::SHEETS_PER_RUN): array
    {
        if (! $this->configured()) {
            return ['wards' => 0, 'sheets' => 0];
        }

        try {
            $looked = $this->sweep($wards);
            Settings::set('irev.last_error', null);
        } catch (Throwable $e) {
            report($e);
            Settings::set('irev.last_error', Str::limit($e->getMessage(), 300));
            $looked = 0;
        }

        return ['wards' => $looked, 'sheets' => $this->process($sheets)];
    }

    /**
     * Look at the next $count wards (wrapping round), recording every PU's upload.
     */
    public function sweep(int $count): int
    {
        [$oid, $id] = [(string) Settings::get('irev.election_oid'), (int) Settings::get('irev.election_id')];
        $wards = $this->wards($oid, $id);

        if ($wards === []) {
            return 0;
        }

        $cursor = (int) Settings::get('irev.ward_cursor', '0') % count($wards);

        for ($i = 0; $i < min($count, count($wards)); $i++) {
            [$wardOid, $lgaName, $wardName] = $wards[($cursor + $i) % count($wards)];

            foreach ($this->client->pollingUnits($oid, $id, $wardOid) as $entry) {
                if (is_array($entry)) {
                    $this->record($entry, $id, $lgaName, $wardName);
                }
            }
        }

        $next = $cursor + min($count, count($wards));
        Settings::set('irev.ward_cursor', (string) ($next % count($wards)));
        Settings::set('irev.last_sweep_at', now()->toIso8601String());

        if ($next >= count($wards)) {
            Settings::set('irev.last_full_sweep_at', now()->toIso8601String());
        }

        return min($count, count($wards));
    }

    /**
     * @param  array<string, mixed>  $entry  one element of /pus
     */
    public function record(array $entry, int $electionId, ?string $lgaName = null, ?string $wardName = null): ?IrevDocument
    {
        $unit = is_array($entry['polling_unit'] ?? null) ? $entry['polling_unit'] : [];
        $code = (string) ($entry['pu_code'] ?? $unit['pu_code'] ?? '');

        if ($code === '') {
            return null;
        }

        $document = IrevDocument::query()->firstOrNew(['irev_election_id' => $electionId, 'irev_pu_code' => $code]);
        $document->pu_name = (string) ($entry['name'] ?? $unit['name'] ?? $document->pu_name);
        $document->lga_name = (string) (data_get($unit, 'lga.name') ?? $lgaName ?? $document->lga_name);
        $document->ward_name = (string) (data_get($unit, 'ward.name') ?? $wardName ?? $document->ward_name);

        if (! $document->matched_by_hand) {
            $document->polling_unit_code = $this->match($document->lga_name, $document->ward_name, (string) ($entry['code'] ?? $unit['code'] ?? Str::afterLast($code, '/')), $code);
        }

        $url = data_get($entry, 'document.url');
        $updated = data_get($entry, 'document.updated_at') ?? (isset($entry['result_updated_time']) ? Carbon::createFromTimestampMs((int) $entry['result_updated_time'])->toIso8601String() : null);

        if (! filled($url)) {
            $document->status = $document->exists && $document->status !== IrevDocument::WAITING ? $document->status : IrevDocument::WAITING;
        } elseif ($url !== $document->document_url) {
            // A new (or replaced) sheet.
            $document->fill(['document_url' => $url, 'document_updated_at' => $updated ? Carbon::parse($updated) : now(), 'attempts' => 0, 'last_error' => null]);
            $document->status = $document->polling_unit_code ? IrevDocument::NEW : IrevDocument::UNMATCHED;
        }

        $document->save();

        return $document;
    }

    /**
     * Fetch and read up to $max new sheets. Returns how many were handled.
     */
    public function process(int $max): int
    {
        $handled = 0;
        $documents = IrevDocument::query()
            ->whereIn('status', [IrevDocument::NEW, IrevDocument::FAILED])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->whereNotNull('polling_unit_code')
            ->orderBy('document_updated_at')
            ->limit($max)->get();

        foreach ($documents as $document) {
            $handled++;

            try {
                $this->handle($document);
            } catch (IrevBlocked $e) {
                $document->forceFill(['status' => IrevDocument::BLOCKED, 'last_error' => $e->getMessage(), 'processed_at' => now()])->save();
                Settings::set('irev.last_error', $e->getMessage());

                break; // The image store refuses this server: don't hammer it.
            } catch (Throwable $e) {
                report($e);
                $document->forceFill(['status' => IrevDocument::FAILED, 'attempts' => $document->attempts + 1, 'last_error' => Str::limit($e->getMessage(), 490), 'processed_at' => now()])->save();
            }
        }

        return $handled;
    }

    private function handle(IrevDocument $document): void
    {
        $code = (string) $document->polling_unit_code;
        $existing = OfficialResult::query()->where('polling_unit_code', $code)->first();

        // A person's entry (or one they checked) stands.
        if ($existing && ($existing->source !== 'irev-auto' || ! $existing->needs_check)) {
            $document->forceFill(['status' => IrevDocument::KEPT, 'processed_at' => now()])->save();

            return;
        }

        [$bytes, $mime] = $this->client->download((string) $document->document_url);
        $sha = hash('sha256', $bytes);
        $path = "irev/{$code}/".substr($sha, 0, 32).'.'.match ($mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };
        Storage::disk('local')->put($path, $bytes);
        $document->forceFill(['sheet_path' => $path, 'sheet_sha256' => $sha]);

        if (! $this->reader->enabled()) {
            $document->forceFill(['status' => IrevDocument::FETCHED, 'processed_at' => now()])->save();

            return;
        }

        $parties = Collation::parties();
        $reading = $this->reader->read($bytes, $mime, $parties);

        if (! $reading['legible'] || in_array(null, $reading['votes'], true)) {
            $document->forceFill(['status' => IrevDocument::UNREADABLE, 'last_error' => $reading['notes'] ?? 'Some figures could not be read.', 'processed_at' => now()])->save();

            return;
        }

        $unit = PollingUnit::query()->where('code', $code)->first();
        OfficialResult::updateOrCreate(['polling_unit_code' => $code], [
            'lga' => $unit?->lga,
            'ward' => $unit?->ward,
            'irev_status' => OfficialResult::UPLOADED,
            'accredited_voters' => $reading['accredited_voters'],
            'votes' => $reading['votes'],
            'rejected_votes' => $reading['rejected_votes'],
            'source' => 'irev-auto',
            'needs_check' => true,
            'note' => trim('Fetched from IReV and read by AI; not yet checked by a person. '.($reading['notes'] ?? '')),
            'entered_by' => 'IReV watcher',
            'sheet_path' => $path,
            'sheet_sha256' => $sha,
            'irev_document_url' => $document->document_url,
        ]);

        $document->forceFill(['status' => IrevDocument::SAVED, 'last_error' => null, 'processed_at' => now()])->save();
        Audit::record('official.irev_auto', "Fetched the IReV sheet for PU {$code} and saved the AI reading (sha256 {$sha})", actor: 'IReV watcher');
    }

    /**
     * Link an IReV polling unit to ours by hand.
     */
    public function matchByHand(IrevDocument $document, string $code): void
    {
        $document->forceFill([
            'polling_unit_code' => $code,
            'matched_by_hand' => true,
            'status' => $document->document_url ? IrevDocument::NEW : IrevDocument::WAITING,
            'attempts' => 0,
        ])->save();
    }

    /**
     * Our PU for an IReV one: the same digits of the code, or the same LGA,
     * ward and PU number by name.
     */
    public function match(?string $lga, ?string $ward, string $number, string $irevCode): ?string
    {
        $this->index ??= $this->buildIndex();
        $digits = preg_replace('/\D/', '', $irevCode);
        $number = str_pad(preg_replace('/\D/', '', $number) ?: '', 3, '0', STR_PAD_LEFT);

        return $this->index['code:'.$digits] ?? $this->index['name:'.self::key($lga).'|'.self::key($ward).'|'.$number] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function buildIndex(): array
    {
        $index = [];

        foreach (PollingUnit::query()->get(['code', 'lga', 'ward']) as $unit) {
            $index['code:'.$unit->code] = $unit->code;
            $index['name:'.self::key($unit->lga).'|'.self::key($unit->ward).'|'.substr($unit->code, -3)] = $unit->code;
        }

        return $index;
    }

    public static function key(?string $name): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii((string) $name))) ?? '';
    }

    /**
     * The followed election's wards as [ward _id, LGA name, ward name], kept
     * in settings so a sweep needs one request per ward.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function wards(string $oid, int $id): array
    {
        $cached = json_decode((string) Settings::get('irev.wards'), true);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $wards = [];

        foreach ($this->client->lgas($oid, $id) as $lga) {
            foreach ((array) ($lga['wards'] ?? []) as $ward) {
                if (is_array($ward) && filled($ward['_id'] ?? null)) {
                    $wards[] = [(string) $ward['_id'], (string) data_get($lga, 'lga.name', data_get($lga, 'name', '')), (string) ($ward['name'] ?? '')];
                }
            }
        }

        if ($wards === []) {
            throw new RuntimeException('IReV lists no wards for this election.');
        }

        Settings::set('irev.wards', json_encode($wards));

        return $wards;
    }
}
