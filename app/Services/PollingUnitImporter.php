<?php

namespace App\Services;

use App\Models\PollingUnit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the PU register from CSV: code,name,ward,lga,registered_voters
 * (the same file the USSD service uses). Rows are upserted by code, so
 * importing again, or a later sync from the USSD service, updates rather
 * than duplicates.
 */
class PollingUnitImporter
{
    public const BUNDLED = 'data/ebonyi_polling_units.csv';

    private const MAX_ERRORS = 50;

    public static function bundledPath(): string
    {
        return database_path(self::BUNDLED);
    }

    /**
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function import(string $path): array
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

            $error = match (true) {
                strlen($code) !== 11 => "invalid code \"{$data['code']}\"",
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
        }

        fclose($handle);

        $existing = PollingUnit::query()->whereIn('code', array_keys($rows))->count();

        DB::transaction(function () use ($rows) {
            $now = now();

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                PollingUnit::query()->upsert(
                    array_map(fn ($row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk),
                    ['code'],
                    ['name', 'ward', 'lga', 'registered_voters', 'updated_at'],
                );
            }
        });

        return ['created' => count($rows) - $existing, 'updated' => $existing, 'errors' => $errors];
    }
}
