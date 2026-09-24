<?php

namespace App\Support;

/**
 * Chart colour slots follow the party (ballot order), never its rank, so a
 * party keeps its colour on every page. OTHERS is neutral grey.
 */
class Party
{
    public static function slot(string $party): string
    {
        if ($party === 'OTHERS') {
            return 'other';
        }

        $index = array_search($party, config('election.parties'), true);

        return $index === false || $index >= 4 ? 'other' : 'slot'.($index + 1);
    }

    public static function candidate(string $party): ?string
    {
        return config("election.candidates.{$party}");
    }
}
