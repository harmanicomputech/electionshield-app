<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\PollingUnit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the PU register from CSV: code,name,ward,lga,registered_voters and
 * optionally latitude,longitude (the same file the USSD service uses). Rows
 * are upserted by code, so importing again, or a later sync from the USSD
 * service, updates rather than duplicates.
 *
 * An empty registered_voters keeps the figure already stored. Coordinates
 * from the file are INEC's approximate locations: they only fill a PU with
 * no location, or one whose location came from INEC before, never one set
 * from an agent's check-in. With $replace, PUs missing from the file are
 * removed (and agents assigned to them unassigned).
 */
class PollingUnitImporter
{
    public const BUNDLED = 'data/ebonyi_polling_units.csv';

    private const MAX_ERRORS = 50;

    /** location_source for coordinates from INEC's PU locator (approximate). */
    public const INEC_LOCATION = 'INEC PU locator (approximate)';

    public static function bundledPath(): string
    {
        return database_path(self::BUNDLED);
    }

    /**
     * @return array{created: int, updated: int, errors: list<string>, removed?: int}
     */
    public function import(string $path, bool $replace = false): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle, escape: '\\') ?: []);

        foreach (['code', 'name', 'ward', 'lga'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);
                throw new RuntimeException("The file has no \"{$required}\" column. Expected: code,name,ward,lga,registered_voters");
            }
        }

        $rows = [];
        $locations = [];
        $errors = [];
        $line = 1;

        while (($values = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;

            if (array_filter($values, fn ($value) => trim((string) $value) !== '') === []) {
                continue;
            }

            $data = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
            $code = PollingUnit::normalizeCode((string) $data['code']);
            $registered = trim((string) ($data['registered_voters'] ?? ''));
            $lat = trim((string) ($data['latitude'] ?? ''));
            $lng = trim((string) ($data['longitude'] ?? ''));

            $error = match (true) {
                ! preg_match('/^\d{6,12}$/', $code) => "invalid code \"{$data['code']}\"",
                trim((string) $data['lga']) === '' || trim((string) $data['ward']) === '' => 'missing ward or LGA',
                $registered !== '' && ! ctype_digit($registered) => "invalid registered_voters \"{$registered}\"",
                default => null,
            };

            if ($error !== null) {
                count($errors) < self::MAX_ERRORS && $errors[] = "Line {$line}: {$error}";

                continue;
            }

            $rows[$code] = [
                'code' => $code,
                'name' => trim((string) $data['name']) ?: null,
                'ward' => trim((string) $data['ward']),
                'lga' => trim((string) $data['lga']),
                'registered_voters' => $registered === '' ? null : (int) $registered,
            ];
            if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
                $locations[$code] = [(float) $lat, (float) $lng];
            }
        }

        fclose($handle);

        if ($replace && ($errors !== [] || $rows === [])) {
            throw new RuntimeException('Not replacing the register: the file has errors or no polling units ('.($errors[0] ?? 'empty').').');
        }

        $existing = 0;
        foreach (array_chunk(array_map('strval', array_keys($rows)), 1000) as $codes) {
            $existing += PollingUnit::query()->whereIn('code', $codes)->count();
        }
        $removed = 0;

        DB::transaction(function () use ($rows, $locations, $replace, &$removed) {
            $now = now();

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                // Rows without a voter figure leave the stored one alone.
                foreach ([true, false] as $withVoters) {
                    $part = array_values(array_filter($chunk, fn ($row) => ($row['registered_voters'] !== null) === $withVoters));
                    if ($part === []) {
                        continue;
                    }
                    PollingUnit::query()->upsert(
                        array_map(function ($row) use ($withVoters, $now) {
                            if (! $withVoters) {
                                unset($row['registered_voters']);
                            }

                            return [...$row, 'created_at' => $now, 'updated_at' => $now];
                        }, $part),
                        ['code'],
                        $withVoters ? ['name', 'ward', 'lga', 'registered_voters', 'updated_at'] : ['name', 'ward', 'lga', 'updated_at'],
                    );
                }
            }

            foreach (array_chunk($locations, 500, true) as $chunk) {
                $open = PollingUnit::query()->whereIn('code', array_map('strval', array_keys($chunk)))
                    ->where(fn ($query) => $query->whereNull('latitude')->orWhere('location_source', self::INEC_LOCATION))
                    ->pluck('code');
                foreach ($open as $code) {
                    [$lat, $lng] = $chunk[$code];
                    PollingUnit::query()->where('code', $code)->update(['latitude' => $lat, 'longitude' => $lng, 'location_source' => self::INEC_LOCATION]);
                }
            }

            if ($replace) {
                foreach (PollingUnit::query()->pluck('code')->diff(array_map('strval', array_keys($rows)))->chunk(500) as $codes) {
                    Agent::query()->whereIn('polling_unit_code', $codes->all())->update(['polling_unit_code' => null]);
                    $removed += PollingUnit::query()->whereIn('code', $codes->all())->delete();
                }
            }
        });

        return ['created' => count($rows) - $existing, 'updated' => $existing, 'errors' => $errors, ...($replace ? ['removed' => $removed] : [])];
    }
}
