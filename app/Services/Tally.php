<?php

namespace App\Services;

/**
 * Collated EC8A figures for one area (state, LGA, ward or PU).
 */
class Tally
{
    public int $accredited = 0;

    public int $valid = 0;

    public int $rejected = 0;

    public int $cast = 0;

    /** PUs with an accepted result. */
    public int $reported = 0;

    /** PUs in the register. */
    public int $units = 0;

    public int $registered = 0;

    /** Registered voters at the PUs that have reported (for turnout). */
    public int $registeredReported = 0;

    /** @var array<string, int> */
    public array $votes = [];

    /**
     * @param  list<string>  $parties
     */
    public function __construct(public string $name, array $parties = [])
    {
        $this->votes = array_fill_keys($parties, 0);
    }

    /**
     * @param  array<string, int>  $votes
     */
    public function addResult(int $accredited, int $valid, int $rejected, int $cast, array $votes, int $registered = 0): void
    {
        $this->registeredReported += $registered;
        $this->accredited += $accredited;
        $this->valid += $valid;
        $this->rejected += $rejected;
        $this->cast += $cast;
        $this->reported++;

        foreach ($votes as $party => $count) {
            $this->votes[$party] = ($this->votes[$party] ?? 0) + $count;
        }
    }

    /**
     * Share of the valid votes, in percent (the 25% rule counts votes cast
     * for candidates, so rejected ballots are left out).
     */
    public function share(string $party): float
    {
        $total = array_sum($this->votes);

        return $total > 0 ? round(100 * ($this->votes[$party] ?? 0) / $total, 2) : 0.0;
    }

    public function totalVotes(): int
    {
        return array_sum($this->votes);
    }

    /**
     * Percentage of the area's PUs with an accepted result.
     */
    public function coverage(): float
    {
        return $this->units > 0 ? round(100 * $this->reported / $this->units, 1) : 0.0;
    }

    /**
     * Accredited voters as a percentage of those registered at the PUs
     * that have reported.
     */
    public function turnout(): ?float
    {
        return $this->registeredReported > 0 ? round(100 * $this->accredited / $this->registeredReported, 1) : null;
    }

    /**
     * The leading candidate's party, or null with no votes or a tie at the top.
     *
     * @param  list<string>  $among  the candidates' parties (OTHERS is not one)
     */
    public function leader(array $among): ?string
    {
        $votes = array_filter(array_intersect_key($this->votes, array_flip($among)));
        arsort($votes);
        $top = array_slice($votes, 0, 2, true);

        if ($top === [] || (count($top) === 2 && count(array_unique($top)) === 1)) {
            return null;
        }

        return array_key_first($top);
    }
}
