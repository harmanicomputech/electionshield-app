<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OfficialCollation;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Services\Collation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Bulk import of official figures from CSV (admins).
 *
 *   IReV:        pu_code,irev_status,accredited,APC,PDP,LP,OTHERS,rejected,note
 *   Collations:  level,lga,ward,accredited,APC,PDP,LP,OTHERS,rejected,note
 *
 * Rows are upserted, so a corrected file can be imported again. Rows with
 * errors are skipped and listed; the rest are saved.
 */
class OfficialImportController extends Controller
{
    private const MAX_ERRORS = 50;

    public function show(): View
    {
        return view('official.import', ['parties' => Collation::parties()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:irev,collations'],
            'file' => ['required', 'file', 'max:4096', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($column) => strtolower(trim((string) $column, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle, escape: '\\') ?: []);
        $saved = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, $validated, $request, &$saved, &$errors, &$line) {
            while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                $line++;

                if (array_filter($row, fn ($value) => trim((string) $value) !== '') === []) {
                    continue;
                }

                $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
                $error = $validated['type'] === 'irev' ? $this->importResult($data, $request->user()->name) : $this->importCollation($data, $request->user()->name);

                if ($error === null) {
                    $saved++;
                } elseif (count($errors) < self::MAX_ERRORS) {
                    $errors[] = "Line {$line}: {$error}";
                }
            }
        });

        fclose($handle);

        $label = $validated['type'] === 'irev' ? 'IReV results' : 'collations';
        Audit::record('official.import', "Imported {$saved} {$label} from {$request->file('file')->getClientOriginalName()}".($errors ? ' ('.count($errors).' rows skipped)' : ''));

        return back()->with($errors ? 'error' : 'status', "Imported {$saved} {$label}.".($errors ? ' Skipped rows are listed below.' : ''))->with('import_errors', $errors);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importResult(array $data, string $user): ?string
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($data['pu_code'] ?? ''))->first();

        if (! $unit) {
            return 'unknown pu_code "'.($data['pu_code'] ?? '').'"';
        }

        $status = strtolower(trim($data['irev_status'] ?? '')) ?: OfficialResult::UPLOADED;
        $status = str_replace([' ', '-'], '_', $status);

        if (! in_array($status, [OfficialResult::UPLOADED, OfficialResult::NOT_UPLOADED], true)) {
            return 'irev_status must be uploaded or not_uploaded';
        }

        $votes = $status === OfficialResult::UPLOADED ? $this->votes($data) : null;

        if (is_string($votes)) {
            return $votes;
        }

        OfficialResult::updateOrCreate(['polling_unit_code' => $unit->code], [
            'lga' => $unit->lga,
            'ward' => $unit->ward,
            'irev_status' => $status,
            'accredited_voters' => $this->number($data['accredited'] ?? ''),
            'votes' => $votes,
            'rejected_votes' => $this->number($data['rejected'] ?? ''),
            'source' => 'import',
            'note' => trim($data['note'] ?? '') ?: null,
            'entered_by' => $user,
        ]);

        return null;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importCollation(array $data, string $user): ?string
    {
        $level = strtolower(trim($data['level'] ?? ''));
        $lga = trim($data['lga'] ?? '');
        $ward = $level === OfficialCollation::WARD ? trim($data['ward'] ?? '') : '';

        if (! in_array($level, [OfficialCollation::WARD, OfficialCollation::LGA], true)) {
            return 'level must be ward or lga';
        }

        if (! PollingUnit::query()->where('lga', $lga)->when($ward !== '', fn ($query) => $query->where('ward', $ward))->exists()) {
            return $ward !== '' ? "unknown ward \"{$ward}\" in \"{$lga}\"" : "unknown lga \"{$lga}\"";
        }

        $votes = $this->votes($data);

        if (is_string($votes)) {
            return $votes;
        }

        OfficialCollation::updateOrCreate(['level' => $level, 'lga' => $lga, 'ward' => $ward], [
            'accredited_voters' => $this->number($data['accredited'] ?? ''),
            'votes' => $votes,
            'rejected_votes' => $this->number($data['rejected'] ?? ''),
            'source' => 'import',
            'note' => trim($data['note'] ?? '') ?: null,
            'entered_by' => $user,
        ]);

        return null;
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, int>|string the votes, or an error
     */
    private function votes(array $data): array|string
    {
        $votes = [];

        foreach (Collation::parties() as $party) {
            $value = $this->number($data[strtolower($party)] ?? '');

            if ($value === null) {
                return "missing or invalid {$party} votes";
            }

            $votes[$party] = $value;
        }

        return $votes;
    }

    private function number(string $value): ?int
    {
        $value = str_replace([',', ' '], '', trim($value));

        return ctype_digit($value) ? (int) $value : null;
    }
}
