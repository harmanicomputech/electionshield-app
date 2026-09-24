<?php

namespace App\Services;

use App\Models\OfficialCollation;
use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Models\Result;
use Illuminate\Support\Collection;

/**
 * Our PVT figures against INEC's: vote by vote per PU (IReV), and by share
 * of the valid votes per ward (EC8B) and LGA (EC8C), since the PVT may not
 * yet cover every PU in an area.
 */
class ResultComparison
{
    private Collation $collation;

    public function __construct(bool $rehearsal)
    {
        $this->collation = new Collation($rehearsal);
    }

    /**
     * Every PU with a PVT result or an official entry, by code.
     *
     * @return Collection<string, array{code: string, unit: ?PollingUnit, lga: string, ward: string, pvt: ?Result, official: ?OfficialResult, diff: array<string, int>, max_diff: int, flags: list<string>}>
     */
    public function units(?string $lga = null): Collection
    {
        $pvt = $this->collation->results()->when($lga !== null, fn (Collection $results) => $results->filter(fn (Result $result) => $result->lga === $lga));
        $official = OfficialResult::query()->when($lga !== null, fn ($query) => $query->where('lga', $lga))->get()->keyBy('polling_unit_code');
        $codes = $pvt->keys()->merge($official->keys())->unique();
        $units = PollingUnit::query()->whereIn('code', $codes)->get()->keyBy('code');
        $threshold = (int) config('election.discrepancy_votes');

        return $codes->sort(SORT_NATURAL)->values()->mapWithKeys(function (string $code) use ($pvt, $official, $units, $threshold) {
            $ours = $pvt->get($code);
            $theirs = $official->get($code);
            $diff = [];
            $flags = [];

            if ($theirs && ! $theirs->uploaded()) {
                $flags[] = 'no_upload';
            }

            if ($ours && $theirs?->uploaded()) {
                $pvtVotes = $ours->votesByParty();

                foreach ($theirs->votesByParty() as $party => $votes) {
                    $diff[$party] = $votes - ($pvtVotes[$party] ?? 0);
                }
            }

            $maxDiff = $diff === [] ? 0 : max(array_map('abs', $diff));

            if ($maxDiff >= $threshold && $diff !== []) {
                $flags[] = 'discrepancy';
            }
            if (! $ours) {
                $flags[] = 'no_pvt';
            }
            if (! $theirs) {
                $flags[] = 'not_entered';
            }

            return [$code => [
                'code' => $code,
                'unit' => $units->get($code),
                'lga' => $ours?->lga ?? $theirs?->lga ?? Collation::UNKNOWN,
                'ward' => $ours?->ward ?? $theirs?->ward ?? Collation::UNKNOWN,
                'pvt' => $ours,
                'official' => $theirs,
                'diff' => $diff,
                'max_diff' => $maxDiff,
                'flags' => $flags,
            ]];
        });
    }

    /**
     * PUs worth a coordinator's attention: a vote discrepancy or no IReV upload.
     *
     * @param  Collection<string, array<string, mixed>>  $units
     * @return Collection<string, array<string, mixed>>
     */
    public function flagged(Collection $units): Collection
    {
        return $units->filter(fn (array $row) => array_intersect($row['flags'], ['discrepancy', 'no_upload']) !== [])
            ->sortByDesc(fn (array $row) => [in_array('discrepancy', $row['flags'], true), $row['max_diff']]);
    }

    /**
     * PVT against declared collations, per LGA (EC8C) or per ward in an LGA (EC8B).
     *
     * @return list<array{name: string, pvt: Tally, declared: ?OfficialCollation, share_diff: array<string, float>, vote_diff: array<string, int>, max_share_diff: float, complete: bool, flagged: bool}>
     */
    public function collations(?string $lga = null): array
    {
        $tallies = $lga === null ? $this->collation->lgas() : $this->collation->wards($lga);
        $declared = OfficialCollation::query()
            ->where('level', $lga === null ? OfficialCollation::LGA : OfficialCollation::WARD)
            ->when($lga !== null, fn ($query) => $query->where('lga', $lga))
            ->get()
            ->keyBy(fn (OfficialCollation $row) => $lga === null ? $row->lga : $row->ward);

        $points = (float) config('election.discrepancy_share_points');
        $votes = (int) config('election.discrepancy_votes');
        $names = collect(array_keys($tallies))->merge($declared->keys())->unique()->reject(fn ($name) => $name === Collation::UNKNOWN)->sort(SORT_NATURAL | SORT_FLAG_CASE);

        return $names->map(function (string $name) use ($tallies, $declared, $points, $votes) {
            $pvt = $tallies[$name] ?? new Tally($name, Collation::parties());
            $official = $declared->get($name);
            $shareDiff = [];
            $voteDiff = [];

            if ($official && $pvt->totalVotes() > 0 && $official->totalValidVotes() > 0) {
                foreach ($official->votesByParty() as $party => $count) {
                    // + 0.0 turns -0.0 into 0.0.
                    $shareDiff[$party] = round(100 * $count / $official->totalValidVotes() - $pvt->share($party), 2) + 0.0;
                    $voteDiff[$party] = $count - ($pvt->votes[$party] ?? 0);
                }
            }

            $maxShare = $shareDiff === [] ? 0.0 : max(array_map('abs', $shareDiff));
            $complete = $pvt->units > 0 && $pvt->reported >= $pvt->units;
            $maxVotes = $voteDiff === [] ? 0 : max(array_map('abs', $voteDiff));

            return [
                'name' => $name,
                'pvt' => $pvt,
                'declared' => $official,
                'share_diff' => $shareDiff,
                'vote_diff' => $voteDiff,
                'max_share_diff' => $maxShare,
                'complete' => $complete,
                'flagged' => $shareDiff !== [] && ($maxShare >= $points || ($complete && $maxVotes >= $votes)),
            ];
        })->values()->all();
    }
}
