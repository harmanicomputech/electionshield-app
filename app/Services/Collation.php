<?php

namespace App\Services;

use App\Models\PollingUnit;
use App\Models\Result;
use Illuminate\Support\Collection;

/**
 * Collates accepted results by state, LGA, ward and PU. Only accepted
 * results count, and never real and rehearsal data together.
 */
class Collation
{
    public const UNKNOWN = 'Unknown';

    /** @var array<string, Collection<string, Result>> */
    private array $results = [];

    public function __construct(private bool $rehearsal) {}

    /**
     * @return list<string>
     */
    public static function parties(): array
    {
        return config('election.parties');
    }

    /**
     * The candidates' parties, in ballot order (OTHERS is not a candidate).
     *
     * @return list<string>
     */
    public static function candidates(): array
    {
        return array_values(array_intersect(self::parties(), array_keys(config('election.candidates'))));
    }

    public function state(): Tally
    {
        $state = new Tally(config('election.name'), self::parties());
        $state->units = PollingUnit::query()->count();
        $state->registered = (int) PollingUnit::query()->sum('registered_voters');

        $this->addAll($state, $this->results());

        return $state;
    }

    /**
     * @return array<string, Tally> by LGA name, alphabetical
     */
    public function lgas(): array
    {
        return $this->group(
            $this->results(),
            fn (Result $result) => $result->lga ?: self::UNKNOWN,
            PollingUnit::query()->selectRaw('lga as area, count(*) as units, coalesce(sum(registered_voters), 0) as registered')->groupBy('lga')->get(),
        );
    }

    /**
     * @return array<string, Tally> by ward name, alphabetical
     */
    public function wards(string $lga): array
    {
        return $this->group(
            $this->results()->filter(fn (Result $result) => ($result->lga ?: self::UNKNOWN) === $lga),
            fn (Result $result) => $result->ward ?: self::UNKNOWN,
            PollingUnit::query()->where('lga', $lga)->selectRaw('ward as area, count(*) as units, coalesce(sum(registered_voters), 0) as registered')->groupBy('ward')->get(),
        );
    }

    /**
     * Every PU in a ward with its counted result (or null), by code.
     *
     * @return list<array{code: string, unit: ?PollingUnit, result: ?Result}>
     */
    public function units(string $lga, string $ward): array
    {
        $results = $this->results()->filter(fn (Result $result) => $result->lga === $lga && $result->ward === $ward);
        $units = PollingUnit::query()->where('lga', $lga)->where('ward', $ward)->orderBy('code')->get()->keyBy('code');

        return $units->keys()->merge($results->keys())->unique()->sort()->values()
            ->map(fn (string $code) => ['code' => $code, 'unit' => $units->get($code), 'result' => $results->get($code)])
            ->all();
    }

    /**
     * PUs that have more than one accepted result (the USSD service allows
     * only one, so this means a missed supersession; the latest counts).
     */
    public function duplicateCount(): int
    {
        return Result::query()->counted($this->rehearsal)->count() - $this->results()->count();
    }

    /**
     * Counted results by PU code; if a PU has several accepted results,
     * the latest submitted one.
     *
     * @return Collection<string, Result>
     */
    public function results(): Collection
    {
        return $this->results['all'] ??= Result::query()
            ->counted($this->rehearsal)
            ->with('votes', 'pollingUnit:code,registered_voters')
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('polling_unit_code');
    }

    /**
     * @param  Collection<string, Result>  $results
     * @param  callable(Result): string  $area
     * @param  Collection<int, PollingUnit>  $register
     * @return array<string, Tally>
     */
    private function group(Collection $results, callable $area, Collection $register): array
    {
        $tallies = [];

        foreach ($register as $row) {
            $name = $row->area ?: self::UNKNOWN;
            $tally = $tallies[$name] ??= new Tally($name, self::parties());
            $tally->units += (int) $row->units;
            $tally->registered += (int) $row->registered;
        }

        foreach ($results as $result) {
            $name = $area($result);
            $this->add($tallies[$name] ??= new Tally($name, self::parties()), $result);
        }

        ksort($tallies, SORT_NATURAL | SORT_FLAG_CASE);

        return $tallies;
    }

    /**
     * @param  Collection<string, Result>  $results
     */
    private function addAll(Tally $tally, Collection $results): void
    {
        foreach ($results as $result) {
            $this->add($tally, $result);
        }
    }

    private function add(Tally $tally, Result $result): void
    {
        $tally->addResult(
            $result->accredited_voters,
            $result->total_valid_votes,
            $result->rejected_votes,
            $result->total_votes_cast,
            $result->votesByParty(),
            (int) $result->pollingUnit?->registered_voters,
        );
    }
}
