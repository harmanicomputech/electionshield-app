<?php

namespace App\Services;

use App\Models\PollingUnit;
use App\Models\VoterStat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads voter figures from CSV into VoterStat:
 *
 *   level,lga,ward,pu_code,dimension,category,count,percent,source,source_url,as_of,note
 *
 * level is state, lga, ward, pu or national; the LGA, ward and PU must be in
 * the register. Each row needs a count or a percent and a source. A figure
 * for the same area, dimension and category is replaced. Nothing is saved
 * if any row is wrong.
 *
 * Also loads registered voters per PU into the register (code,registered_voters).
 */
class VoterStatImporter
{
    public const COLUMNS = ['level', 'lga', 'ward', 'pu_code', 'dimension', 'category', 'count', 'percent', 'source', 'source_url', 'as_of', 'note'];

    private const MAX_ERRORS = 20;

    /**
     * @return array{saved: int, errors: list<string>}
     */
    public function import(string $path, string $addedBy): array
    {
        [$header, $lines] = $this->read($path, ['level', 'dimension', 'category', 'source']);

        // Names as in the register, matched without regard to capitals.
        $lgas = PollingUnit::query()->distinct()->pluck('lga')->mapWithKeys(fn ($lga) => [mb_strtolower($lga) => $lga]);
        $wards = PollingUnit::query()->select('lga', 'ward')->distinct()->get()->mapWithKeys(fn ($row) => [mb_strtolower($row->lga.'|'.$row->ward) => $row->ward]);

        $rows = [];
        $errors = [];
        foreach ($lines as $number => $values) {
            $data = array_map('trim', array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), '')));
            $level = strtolower($data['level']);
            $dimension = strtolower($data['dimension']);
            $category = $this->category($dimension, $data['category']);
            $count = str_replace([',', ' '], '', $data['count'] ?? '');
            $percent = rtrim($data['percent'] ?? '', '% ');
            $code = PollingUnit::normalizeCode($data['pu_code'] ?? '');
            $asOf = $data['as_of'] ?? '';
            $data['lga'] = $lgas[mb_strtolower($data['lga'] ?? '')] ?? ($data['lga'] ?? '');
            $data['ward'] = $wards[mb_strtolower($data['lga'].'|'.($data['ward'] ?? ''))] ?? ($data['ward'] ?? '');

            $error = match (true) {
                ! isset(VoterStat::LEVELS[$level]) => "unknown level \"{$data['level']}\" (use state, lga, ward, pu or national)",
                ! isset(VoterStat::DIMENSIONS[$dimension]) => "unknown dimension \"{$data['dimension']}\" (use ".implode(', ', array_keys(VoterStat::DIMENSIONS)).')',
                $category === '' => 'missing category',
                in_array($level, ['lga', 'ward'], true) && ! isset($lgas[mb_strtolower($data['lga'])]) => "LGA \"{$data['lga']}\" is not in the register",
                $level === 'ward' && ! isset($wards[mb_strtolower($data['lga'].'|'.$data['ward'])]) => "ward \"{$data['ward']}\" is not in {$data['lga']} LGA",
                $level === 'pu' && ($code === '' || ! PollingUnit::query()->where('code', $code)->exists()) => "PU \"{$data['pu_code']}\" is not in the register",
                $count !== '' && ! ctype_digit($count) => "count \"{$data['count']}\" is not a whole number",
                $percent !== '' && (! is_numeric($percent) || $percent < 0 || $percent > 100) => "percent \"{$data['percent']}\" is not between 0 and 100",
                $count === '' && $percent === '' => 'needs a count or a percent',
                $data['source'] === '' => 'missing source (say where the figure comes from)',
                str_starts_with(strtoupper($data['source']), 'EXAMPLE') => 'this is an example row from the template: put the real figure and its source, or delete the row',
                ($data['source_url'] ?? '') !== '' && ! filter_var($data['source_url'], FILTER_VALIDATE_URL) => 'source_url is not a web address',
                $asOf !== '' && strtotime($asOf) === false => "as_of \"{$asOf}\" is not a date",
                default => null,
            };
            if ($error !== null) {
                count($errors) < self::MAX_ERRORS && $errors[] = "Line {$number}: {$error}";

                continue;
            }

            $area = VoterStat::areaKey($level, $data['lga'] ?? null, $data['ward'] ?? null, $code);
            $rows[$level.'#'.$area.'#'.$dimension.'#'.$category] = [
                'level' => $level,
                'area' => $area,
                'dimension' => $dimension,
                'category' => $category,
                'count' => $count === '' ? null : (int) $count,
                'percent' => $percent === '' ? null : round((float) $percent, 2),
                'source' => mb_substr($data['source'], 0, 190),
                'source_url' => ($data['source_url'] ?? '') ?: null,
                'as_of' => $asOf === '' ? null : date('Y-m-d', strtotime($asOf)),
                'note' => ($data['note'] ?? '') === '' ? null : mb_substr($data['note'], 0, 300),
                'added_by' => mb_substr($addedBy, 0, 100),
            ];
        }

        if ($errors !== []) {
            return ['saved' => 0, 'errors' => $errors];
        }

        DB::transaction(function () use ($rows) {
            $now = now();
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                VoterStat::query()->upsert(
                    array_map(fn ($row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk),
                    ['level', 'area', 'dimension', 'category'],
                    ['count', 'percent', 'source', 'source_url', 'as_of', 'note', 'added_by', 'updated_at'],
                );
            }
        });

        return ['saved' => count($rows), 'errors' => []];
    }

    /**
     * Registered voters per PU (code,registered_voters), into the register.
     *
     * @return array{saved: int, errors: list<string>}
     */
    public function importRegisteredVoters(string $path): array
    {
        [$header, $lines] = $this->read($path, ['code', 'registered_voters']);

        $figures = [];
        $errors = [];
        foreach ($lines as $number => $values) {
            $data = array_map('trim', array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), '')));
            $code = PollingUnit::normalizeCode($data['code']);
            $voters = str_replace([',', ' '], '', $data['registered_voters']);

            $error = match (true) {
                ! ctype_digit($voters) => "registered_voters \"{$data['registered_voters']}\" is not a whole number",
                ! PollingUnit::query()->where('code', $code)->exists() => "PU \"{$data['code']}\" is not in the register",
                default => null,
            };
            if ($error !== null) {
                count($errors) < self::MAX_ERRORS && $errors[] = "Line {$number}: {$error}";

                continue;
            }
            $figures[$code] = (int) $voters;
        }

        if ($errors !== []) {
            return ['saved' => 0, 'errors' => $errors];
        }

        DB::transaction(function () use ($figures) {
            foreach ($figures as $code => $voters) {
                PollingUnit::query()->where('code', (string) $code)->update(['registered_voters' => $voters]);
            }
        });

        return ['saved' => count($figures), 'errors' => []];
    }

    /**
     * @param  list<string>  $required
     * @return array{0: list<string>, 1: array<int, list<string>>} the header and the non-empty lines by line number
     */
    private function read(string $path, array $required): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        try {
            $header = array_map(fn ($column) => strtolower(trim((string) $column, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle, escape: '') ?: []);
            foreach ($required as $column) {
                if (! in_array($column, $header, true)) {
                    throw new RuntimeException("The file has no \"{$column}\" column. Expected: ".implode(',', $required === ['code', 'registered_voters'] ? $required : self::COLUMNS));
                }
            }

            $lines = [];
            $number = 1;
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $number++;
                if (array_filter($values, fn ($value) => trim((string) $value) !== '') !== []) {
                    $lines[$number] = $values;
                }
            }
        } finally {
            fclose($handle);
        }

        return [$header, $lines];
    }

    /**
     * Known categories by key or label ("Men" → male, "18 - 34" → 18-34); others as typed.
     */
    private function category(string $dimension, string $category): string
    {
        $plain = strtolower(trim($category));
        foreach (VoterStat::DIMENSIONS[$dimension][1] ?? [] as $key => $label) {
            if ($plain === $key || $plain === strtolower($label)) {
                return $key;
            }
        }
        if ($dimension === 'gender' && in_array($plain, ['men', 'm', 'males'], true)) {
            return 'male';
        }
        if ($dimension === 'gender' && in_array($plain, ['women', 'f', 'females'], true)) {
            return 'female';
        }
        if ($dimension === 'age') {
            return str_replace([' ', '–'], ['', '-'], $plain);
        }

        return mb_substr(preg_replace('/\s+/', '_', $plain) ?? '', 0, 60);
    }
}
