<?php

namespace App\Services\Ai;

use App\Models\Ec8aPhoto;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\ResultCheck;
use App\Services\Collation;
use App\Services\IrevSheetReader;
use App\Services\PushNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Checks each agent result for signs of a wrong or altered EC8A:
 *
 *  - rules, worked out instantly: more votes than accredited voters, totals
 *    that don't add up, turnout or a winner's share that is hard to believe,
 *    figures that are all round numbers, a result far out of line with the
 *    ward's other polling units, figures that differ from IReV;
 *  - AI: the EC8A photo is read and its figures compared with the agent's.
 *
 * A flag is a reason to look again, not proof. People mark results reviewed.
 */
class ResultChecks
{
    /** Photos read per background pass (each read is one AI request). */
    private const PHOTOS_PER_RUN = 3;

    private const MAX_PHOTO_ATTEMPTS = 3;

    public function __construct(private IrevSheetReader $reader, private Claude $ai) {}

    /**
     * Every counted result with at least one flag, most serious first.
     *
     * @return Collection<int, array{result: Result, flags: list<array{level: string, text: string}>, level: string, check: ?ResultCheck}>
     */
    public function flagged(bool $rehearsal, bool $reviewed = false): Collection
    {
        $results = (new Collation($rehearsal))->results();
        // Collation loads only the PU's code and voters; the page shows its name and code too.
        $results->load('pollingUnit:code,name,ward,lga,registered_voters');
        $byWard = $results->groupBy(fn (Result $result) => $result->lga.'|'.$result->ward);
        $checks = ResultCheck::query()->whereIn('result_reference', $results->pluck('reference'))->get()->keyBy('result_reference');
        $official = OfficialResult::query()->whereIn('polling_unit_code', $results->keys())->get()->keyBy('polling_unit_code');

        return $results->values()
            ->map(function (Result $result) use ($byWard, $checks, $official) {
                $check = $checks[$result->reference] ?? null;
                $flags = $this->flags($result, $byWard[$result->lga.'|'.$result->ward] ?? collect(), $official[$result->polling_unit_code] ?? null, $check);

                return ['result' => $result, 'flags' => $flags, 'level' => self::level($flags), 'check' => $check];
            })
            ->filter(fn (array $row) => $row['flags'] !== [] && (bool) $row['check']?->reviewed_at === $reviewed)
            ->sortBy([fn ($a, $b) => self::rank($a['level']) <=> self::rank($b['level']), fn ($a, $b) => $b['result']->submitted_at <=> $a['result']->submitted_at])
            ->values();
    }

    /**
     * The reasons to look at a result again.
     *
     * @param  Collection<int, Result>  $ward  counted results in the same ward (it may include this one)
     * @return list<array{level: string, text: string}>
     */
    public function flags(Result $result, Collection $ward, ?OfficialResult $official = null, ?ResultCheck $check = null): array
    {
        $flags = [];
        $serious = function (string $text) use (&$flags) {
            $flags[] = ['level' => ResultCheck::SERIOUS, 'text' => $text];
        };
        $review = function (string $text) use (&$flags) {
            $flags[] = ['level' => ResultCheck::REVIEW, 'text' => $text];
        };
        $n = fn (int|float $value) => number_format($value);
        $pct = fn (float $value) => rtrim(rtrim(number_format($value, 1), '0'), '.').'%';

        $votes = $result->votesByParty();
        $accredited = (int) $result->accredited_voters;
        $valid = (int) $result->total_valid_votes;
        $rejected = (int) $result->rejected_votes;
        $cast = (int) $result->total_votes_cast ?: $valid + $rejected;

        if ($accredited === 0 && $cast > 0) {
            $serious("Votes are recorded ({$n($cast)}) but no accredited voters.");
        } elseif ($cast > $accredited) {
            $serious("More votes cast ({$n($cast)}) than accredited voters ({$n($accredited)}).");
        }

        if (array_sum($votes) !== $valid) {
            $serious("The party votes add up to {$n(array_sum($votes))}, not the {$n($valid)} valid votes reported.");
        }

        $registered = $result->pollingUnit?->registered_voters;
        if ($registered) {
            if ($accredited > $registered) {
                $serious("More accredited voters ({$n($accredited)}) than registered voters ({$n($registered)}).");
            } elseif ($registered >= 50 && $accredited >= 0.9 * $registered) {
                $review("Turnout of {$pct(100 * $accredited / $registered)} of registered voters.");
            }
        }

        arsort($votes);
        $top = array_key_first($votes);
        if ($valid >= 50 && $top !== null && $votes[$top] >= 0.95 * $valid) {
            $review("{$top} got {$pct(100 * $votes[$top] / $valid)} of the valid votes.");
        }

        $figures = array_values(array_filter([$accredited, ...array_values($votes)], fn (int $value) => $value > 0));
        if (count($figures) >= 3 && collect($figures)->every(fn (int $value) => $value % 10 === 0)) {
            $review('Every figure is a round number, which is more likely when figures are made up than counted.');
        }

        $this->compareWithWard($result, $ward->reject(fn (Result $other) => $other->reference === $result->reference), $votes, $top, $review, $n, $pct);

        if ($official?->uploaded()) {
            $theirs = $official->votesByParty();
            $differences = collect($votes)
                ->filter(fn (int $count, string $party) => array_key_exists($party, $theirs) && $theirs[$party] !== null && (int) $theirs[$party] !== $count)
                ->map(fn (int $count, string $party) => "{$party} {$n($count)} here, {$n((int) $theirs[$party])} on IReV")
                ->values();
            if ($differences->isNotEmpty()) {
                $serious('Differs from IReV: '.$differences->implode('; ').'.');
            }
        }

        if ($check?->photo_status === ResultCheck::PHOTO_MISMATCH) {
            $serious('The EC8A photo shows different figures: '.implode('; ', $check->photo_differences ?? []).'.');
        } elseif ($check?->photo_status === ResultCheck::PHOTO_UNREADABLE) {
            $review('The EC8A photo could not be read (not an EC8A, or too unclear): ask the agent for a clearer one.');
        }

        return $flags;
    }

    /**
     * Out of line with the ward's other results (needs at least three of them).
     *
     * @param  Collection<int, Result>  $others
     * @param  array<string, int>  $votes
     */
    private function compareWithWard(Result $result, Collection $others, array $votes, ?string $top, callable $review, callable $n, callable $pct): void
    {
        if ($others->count() < 3) {
            return;
        }

        $accredited = (int) $result->accredited_voters;
        $median = (float) $others->pluck('accredited_voters')->median();
        if ($median > 0 && $accredited > max(2.5 * $median, $median + 150)) {
            $review("{$n($accredited)} accredited voters, far above the ward's other results (typically {$n($median)}).");
        }

        $valid = (int) $result->total_valid_votes;
        if ($top === null || $valid === 0) {
            return;
        }
        $shares = $others->filter(fn (Result $other) => $other->total_valid_votes > 0)
            ->map(fn (Result $other) => 100 * ($other->votesByParty()[$top] ?? 0) / $other->total_valid_votes);
        if ($shares->count() < 3) {
            return;
        }
        $here = 100 * $votes[$top] / $valid;
        $usual = $shares->avg();
        if (abs($here - $usual) >= 40) {
            $review("{$top} got {$pct($here)} here but {$pct($usual)} on average at the ward's other {$shares->count()} results.");
        }
    }

    /**
     * @param  list<array{level: string, text: string}>  $flags
     */
    public static function level(array $flags): ?string
    {
        $levels = array_column($flags, 'level');

        return match (true) {
            in_array(ResultCheck::SERIOUS, $levels, true) => ResultCheck::SERIOUS,
            $levels !== [] => ResultCheck::REVIEW,
            default => null,
        };
    }

    private static function rank(?string $level): int
    {
        return $level === ResultCheck::SERIOUS ? 0 : 1;
    }

    /**
     * Background pass: check new results once (and alert on serious flags),
     * then read EC8A photos that haven't been read.
     */
    public function step(): void
    {
        $this->checkNewResults();

        if ($this->ai->enabled()) {
            $this->readPhotos(self::PHOTOS_PER_RUN);
        }
    }

    /**
     * A check row for each counted result that has none; serious ones alert.
     */
    public function checkNewResults(): void
    {
        foreach ([false, true] as $rehearsal) {
            $results = (new Collation($rehearsal))->results();
            $known = ResultCheck::query()->whereIn('result_reference', $results->pluck('reference'))->pluck('result_reference')->flip();
            $new = $results->reject(fn (Result $result) => isset($known[$result->reference]));
            if ($new->isEmpty()) {
                continue;
            }
            $byWard = $results->groupBy(fn (Result $result) => $result->lga.'|'.$result->ward);

            foreach ($new as $result) {
                $flags = $this->flags($result, $byWard[$result->lga.'|'.$result->ward] ?? collect());
                ResultCheck::query()->firstOrCreate(['result_reference' => $result->reference], ['level' => self::level($flags), 'flags' => $flags]);
                if (self::level($flags) === ResultCheck::SERIOUS && $result->submitted_at?->gt(now()->subHour())) {
                    $this->alert($result, $flags);
                }
            }
        }
    }

    /**
     * Read EC8A photos of results that have one and haven't been read (or
     * have a newer photo since).
     */
    public function readPhotos(int $max): int
    {
        $done = 0;
        $latest = Ec8aPhoto::query()->selectRaw('max(id) as id')->groupBy('result_reference')->pluck('id');
        $photos = Ec8aPhoto::query()->whereIn('id', $latest)->orderByDesc('id')->get();
        $checks = ResultCheck::query()->whereIn('result_reference', $photos->pluck('result_reference'))->get()->keyBy('result_reference');

        foreach ($photos as $photo) {
            if ($done >= $max) {
                break;
            }
            $check = $checks[$photo->result_reference] ?? new ResultCheck(['result_reference' => $photo->result_reference]);
            // Read once per photo; give up on one after a few failed tries.
            if ((int) $check->photo_id === $photo->id && ($check->photo_checked_at || $check->photo_attempts >= self::MAX_PHOTO_ATTEMPTS)) {
                continue;
            }
            $result = Result::query()->with('votes')->where('reference', $photo->result_reference)->first();
            if (! $result) {
                continue;
            }
            $this->readPhoto($result, $photo, $check);
            $done++;
        }

        return $done;
    }

    /**
     * Read one photo with AI and compare it with the agent's figures.
     */
    public function readPhoto(Result $result, Ec8aPhoto $photo, ?ResultCheck $check = null): ResultCheck
    {
        $check ??= ResultCheck::query()->firstOrNew(['result_reference' => $result->reference]);
        if ((int) $check->photo_id !== $photo->id) {
            $check->photo_attempts = 0;
        }
        $check->photo_id = $photo->id;
        $check->photo_attempts++;

        try {
            $reading = $this->reader->read(Storage::disk('local')->get($photo->path), $photo->mime_type, config('election.parties'));
            $differences = $this->differences($result, $reading);
            $readable = $reading['legible'] && ($reading['accredited_voters'] !== null || array_filter($reading['votes'], fn ($v) => $v !== null) !== []);

            $check->fill([
                'photo_status' => ! $readable ? ResultCheck::PHOTO_UNREADABLE : ($differences === [] ? ResultCheck::PHOTO_MATCHES : ResultCheck::PHOTO_MISMATCH),
                'photo_reading' => $reading,
                'photo_differences' => $differences,
                'photo_error' => null,
                'photo_checked_at' => now(),
            ])->save();

            if ($check->photo_status === ResultCheck::PHOTO_MISMATCH && $result->submitted_at?->gt(now()->subHours(3))) {
                $this->alert($result, [['level' => ResultCheck::SERIOUS, 'text' => 'The EC8A photo shows different figures: '.implode('; ', $differences)]]);
            }
        } catch (Throwable $e) {
            $check->fill(['photo_error' => mb_substr($e->getMessage(), 0, 300)])->save();
            report($e);
        }

        return $check;
    }

    /**
     * Where the photo's figures differ from the agent's (unread figures are skipped).
     *
     * @param  array{pu_code: ?string, accredited_voters: ?int, rejected_votes: ?int, votes: array<string, ?int>}  $reading
     * @return list<string>
     */
    public function differences(Result $result, array $reading): array
    {
        $differences = [];
        $n = fn (int $value) => number_format($value);

        $code = PollingUnit::normalizeCode((string) $reading['pu_code']);
        if ($code !== '' && strlen($code) >= 6 && $code !== $result->polling_unit_code) {
            $differences[] = "the sheet is for PU {$reading['pu_code']}, not {$result->pollingUnit?->inecCode()}";
        }
        if ($reading['accredited_voters'] !== null && $reading['accredited_voters'] !== (int) $result->accredited_voters) {
            $differences[] = "accredited {$n($reading['accredited_voters'])} on the sheet, {$n((int) $result->accredited_voters)} sent";
        }
        if ($reading['rejected_votes'] !== null && $reading['rejected_votes'] !== (int) $result->rejected_votes) {
            $differences[] = "rejected {$n($reading['rejected_votes'])} on the sheet, {$n((int) $result->rejected_votes)} sent";
        }
        foreach ($result->votesByParty() as $party => $sent) {
            $onSheet = $reading['votes'][$party] ?? null;
            if ($onSheet !== null && $onSheet !== $sent) {
                $differences[] = "{$party} {$n($onSheet)} on the sheet, {$n($sent)} sent";
            }
        }

        return $differences;
    }

    /**
     * @param  list<array{level: string, text: string}>  $flags
     */
    private function alert(Result $result, array $flags): void
    {
        try {
            app(PushNotifier::class)->toTopic('result_checks', [
                'title' => ($result->rehearsal ? '[Rehearsal] ' : '').'Check this result: '.($result->pollingUnit?->name ?? 'PU '.$result->polling_unit_code),
                'body' => collect($flags)->where('level', ResultCheck::SERIOUS)->pluck('text')->implode(' '),
                'url' => route('result-checks', absolute: false).'#check-'.$result->reference,
                'tag' => 'check-'.$result->reference,
                'urgent' => true,
            ], $result->lga);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
