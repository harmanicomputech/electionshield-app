<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Services\Collation;
use App\Services\IrevSheetReader;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Entering INEC's IReV result for a PU. The form deliberately doesn't show
 * our PVT figures, so they can't influence what is typed; the comparison
 * appears once it is saved.
 */
class OfficialResultController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $code = PollingUnit::normalizeCode((string) $request->query('code'));

        if ($code !== '') {
            return PollingUnit::query()->where('code', $code)->exists()
                ? redirect()->route('official.pu', $code)
                : back()->with('error', "No polling unit {$request->query('code')} in the register.");
        }

        $unchecked = $request->boolean('unchecked');

        return view('official.index', [
            'recent' => OfficialResult::query()->with('pollingUnit:code,name')->when($unchecked, fn ($query) => $query->where('needs_check', true))->latest('updated_at')->limit($unchecked ? 200 : 20)->get(),
            'onlyUnchecked' => $unchecked,
            'uncheckedCount' => OfficialResult::query()->where('needs_check', true)->count(),
            'entered' => OfficialResult::query()->where('irev_status', OfficialResult::UPLOADED)->count(),
            'notUploaded' => OfficialResult::query()->where('irev_status', OfficialResult::NOT_UPLOADED)->count(),
            'units' => PollingUnit::query()->count(),
        ]);
    }

    public function edit(string $code): View
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($code))->firstOrFail();

        return view('official.pu', [
            'unit' => $unit,
            'official' => OfficialResult::query()->where('polling_unit_code', $unit->code)->first(),
            'parties' => Collation::parties(),
            'reader' => app(IrevSheetReader::class)->enabled(),
            'reading' => session('ai_reading'),
        ]);
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($code))->firstOrFail();
        $uploaded = $request->input('irev_status', OfficialResult::UPLOADED) === OfficialResult::UPLOADED;
        $number = [$uploaded ? 'required' : 'nullable', 'integer', 'min:0', 'max:100000'];

        $validated = $request->validate([
            'irev_status' => ['required', Rule::in([OfficialResult::UPLOADED, OfficialResult::NOT_UPLOADED])],
            'accredited_voters' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'rejected_votes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'votes' => [$uploaded ? 'required' : 'nullable', 'array'],
            ...collect(Collation::parties())->mapWithKeys(fn (string $party) => ["votes.{$party}" => $number])->all(),
            'note' => ['nullable', 'string', 'max:1000'],
            'sheet' => ['nullable', 'string', 'max:255'],
        ]);

        // A sheet read by AI (read() below) is kept with the result as evidence.
        $sheet = filled($validated['sheet'] ?? null) && str_starts_with($validated['sheet'], "irev/{$unit->code}/") && Storage::disk('local')->exists($validated['sheet'])
            ? $validated['sheet'] : null;

        $before = OfficialResult::query()->where('polling_unit_code', $unit->code)->first();

        $official = OfficialResult::updateOrCreate(['polling_unit_code' => $unit->code], [
            'lga' => $unit->lga,
            'ward' => $unit->ward,
            'irev_status' => $validated['irev_status'],
            'accredited_voters' => $validated['accredited_voters'] ?? null,
            'votes' => $uploaded ? array_map('intval', $validated['votes']) : null,
            'rejected_votes' => $validated['rejected_votes'] ?? null,
            'source' => $sheet ? 'ai-read, checked' : (str_starts_with((string) $before?->source, 'irev') ? 'irev, checked' : 'manual'),
            'needs_check' => false,
            ...($sheet ? ['sheet_path' => $sheet, 'sheet_sha256' => hash('sha256', Storage::disk('local')->get($sheet))] : []),
            'note' => $validated['note'] ?? null,
            'entered_by' => $request->user()->name,
        ]);

        Audit::record('official.pu', ($official->wasRecentlyCreated ? 'Entered' : 'Changed')." the IReV result for PU {$unit->code}: ".($uploaded ? json_encode($official->votesByParty()) : 'no upload on IReV'));

        return redirect()->route('official.pu', $unit->code)->with('status', 'Saved. The comparison with our agent\'s figures is below.')->with('warnings', $this->warnings($official, $unit));
    }

    /**
     * Read the IReV result sheet (an uploaded file or a pasted IReV link)
     * with AI and fill the form with its figures for a person to check. The
     * sheet is kept; nothing is saved as the official result until they press Save.
     */
    public function read(Request $request, string $code, IrevSheetReader $reader): RedirectResponse
    {
        $unit = PollingUnit::query()->where('code', PollingUnit::normalizeCode($code))->firstOrFail();
        $validated = $request->validate([
            'sheet_file' => ['nullable', 'required_without:sheet_url', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:20480'],
            'sheet_url' => ['nullable', 'required_without:sheet_file', 'url:https', 'max:1000'],
        ], ['required_without' => 'Upload the sheet or paste its IReV link.']);

        try {
            [$bytes, $mime] = $request->hasFile('sheet_file')
                ? [(string) file_get_contents($request->file('sheet_file')->getRealPath()), (string) $request->file('sheet_file')->getMimeType()]
                : $reader->download($validated['sheet_url']);
            $reading = $reader->read($bytes, $mime, Collation::parties());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The sheet could not be read right now ('.class_basename($e).'). Enter the figures by hand or try again.');
        }

        $sha = hash('sha256', $bytes);
        $path = "irev/{$unit->code}/".substr($sha, 0, 32).'.'.($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg'));
        Storage::disk('local')->put($path, $bytes);

        $readCode = preg_replace('/\D/', '', (string) $reading['pu_code']);
        $warnings = array_values(array_filter([
            ! $reading['legible'] ? 'The AI found the sheet hard to read or not an EC8A: check every figure.' : null,
            $readCode !== '' && $readCode !== $unit->code ? "The PU code on the sheet ({$reading['pu_code']}) doesn't look like this PU ({$unit->code}): is it the right sheet?" : null,
            in_array(null, $reading['votes'], true) ? 'Some figures could not be read (left empty).' : null,
        ]));

        Audit::record('official.ai_read', "Read the IReV sheet for PU {$unit->code} with AI (sha256 {$sha})");

        return redirect()->route('official.pu', $unit->code)
            ->withInput([
                'irev_status' => OfficialResult::UPLOADED,
                'votes' => array_map(fn ($value) => $value === null ? '' : (string) $value, $reading['votes']),
                'accredited_voters' => $reading['accredited_voters'],
                'rejected_votes' => $reading['rejected_votes'],
                'note' => trim('Read by AI from the IReV sheet. '.($reading['notes'] ?? '')),
                'sheet' => $path,
            ])
            ->with('ai_reading', ['warnings' => $warnings, 'notes' => $reading['notes']]);
    }

    public function sheet(string $code): Response
    {
        $official = OfficialResult::query()->where('polling_unit_code', PollingUnit::normalizeCode($code))->whereNotNull('sheet_path')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($official->sheet_path), 404);

        return Storage::disk('local')->response($official->sheet_path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(string $code): RedirectResponse
    {
        $official = OfficialResult::query()->where('polling_unit_code', PollingUnit::normalizeCode($code))->firstOrFail();
        $official->delete();
        Audit::record('official.pu.deleted', "Deleted the IReV result for PU {$official->polling_unit_code}");

        return redirect()->route('official')->with('status', "Deleted the IReV result for PU {$official->polling_unit_code}.");
    }

    /**
     * Checks that don't block saving (the sheet may really say this) but
     * are worth a second look.
     *
     * @return list<string>
     */
    private function warnings(OfficialResult $official, PollingUnit $unit): array
    {
        if (! $official->uploaded()) {
            return [];
        }

        $warnings = [];
        $cast = $official->totalValidVotes() + (int) $official->rejected_votes;

        if ($official->accredited_voters !== null && $cast > $official->accredited_voters) {
            $warnings[] = "Votes cast ({$cast}) are more than accredited voters ({$official->accredited_voters}): over-voting.";
        }
        if ($unit->registered_voters && ($official->accredited_voters ?? $cast) > $unit->registered_voters) {
            $warnings[] = 'More accredited voters than registered ('.number_format($unit->registered_voters).').';
        }

        return $warnings;
    }
}
