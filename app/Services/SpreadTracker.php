<?php

namespace App\Services;

/**
 * Section 179(2): the winner needs the highest number of votes and at least
 * 25% of the votes in at least two-thirds of the LGAs (9 of Ebonyi's 13).
 * Worked out live from the PVT figures, so it is "on current figures".
 */
class SpreadTracker
{
    public float $share;

    public int $required;

    public int $lgaCount;

    /** @var array<string, Tally> */
    public array $lgas;

    /**
     * @param  array<string, Tally>  $lgas
     */
    public function __construct(public Tally $state, array $lgas)
    {
        $this->share = (float) config('election.spread_share');
        $this->required = (int) config('election.spread_lgas_required');
        $this->lgaCount = (int) config('election.lga_count');
        $this->lgas = array_filter($lgas, fn (Tally $lga) => $lga->name !== Collation::UNKNOWN);
    }

    /**
     * Each candidate's standing, leader first.
     *
     * @return list<array{party: string, candidate: string, votes: int, share: float, lgas_met: int, lgas_needed: int, meets_spread: bool, leading: bool}>
     */
    public function candidates(): array
    {
        $leader = $this->state->leader(Collation::candidates());

        $rows = array_map(function (string $party) use ($leader) {
            $met = $this->lgasMet($party);

            return [
                'party' => $party,
                'candidate' => config("election.candidates.{$party}"),
                'votes' => $this->state->votes[$party] ?? 0,
                'share' => $this->state->share($party),
                'lgas_met' => $met,
                'lgas_needed' => max(0, $this->required - $met),
                'meets_spread' => $met >= $this->required,
                'leading' => $party === $leader,
            ];
        }, Collation::candidates());

        usort($rows, fn (array $a, array $b) => $b['votes'] <=> $a['votes']);

        return $rows;
    }

    public function lgasMet(string $party): int
    {
        return count(array_filter($this->lgas, fn (Tally $lga) => $lga->totalVotes() > 0 && $lga->share($party) >= $this->share));
    }

    /**
     * The outcome on current figures: none (no results), declared (the
     * leader also meets the spread), runoff (the leader does not), or tie.
     *
     * @return array{outcome: string, party: ?string}
     */
    public function projection(): array
    {
        if ($this->state->totalVotes() === 0) {
            return ['outcome' => 'none', 'party' => null];
        }

        $leader = $this->state->leader(Collation::candidates());

        if ($leader === null) {
            return ['outcome' => 'tie', 'party' => null];
        }

        return ['outcome' => $this->lgasMet($leader) >= $this->required ? 'declared' : 'runoff', 'party' => $leader];
    }

    /**
     * The candidate weak links are worked out for: the principal party if
     * configured, otherwise the current leader.
     */
    public function focusParty(): ?string
    {
        $principal = config('election.principal_party');

        return in_array($principal, Collation::candidates(), true) ? $principal : $this->state->leader(Collation::candidates());
    }

    /**
     * LGAs where the focus candidate is under or near 25%, or where few PUs
     * have reported, weakest first.
     *
     * @return list<array{lga: Tally, share: float, below: bool, near: bool, low_coverage: bool}>
     */
    public function weakLinks(?string $party = null): array
    {
        $party ??= $this->focusParty();
        $near = $this->share + (float) config('election.near_margin');
        $lowCoverage = (float) config('election.low_coverage');
        $links = [];

        foreach ($this->lgas as $lga) {
            $share = $party ? $lga->share($party) : 0.0;
            $hasVotes = $lga->totalVotes() > 0;
            $row = [
                'lga' => $lga,
                'share' => $share,
                'below' => $party !== null && $hasVotes && $share < $this->share,
                'near' => $party !== null && $hasVotes && $share >= $this->share && $share < $near,
                'low_coverage' => $lga->units > 0 ? $lga->coverage() < $lowCoverage : ! $hasVotes,
            ];

            if ($row['below'] || $row['near'] || $row['low_coverage']) {
                $links[] = $row;
            }
        }

        usort($links, fn (array $a, array $b) => [$b['below'], $b['near'], $a['share']] <=> [$a['below'], $a['near'], $b['share']]);

        return $links;
    }
}
