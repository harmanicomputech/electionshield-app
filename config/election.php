<?php

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [

    'name' => env('ELECTION_NAME', 'Ebonyi State Governorship Election'),

    'date' => env('ELECTION_DATE', '2027-02-06'),

    'timezone' => env('ELECTION_TIMEZONE', 'Africa/Lagos'),

    /*
    |--------------------------------------------------------------------------
    | Ballot
    |--------------------------------------------------------------------------
    |
    | Parties in the order agents enter them on USSD. OTHERS is the combined
    | votes of every other party: it counts towards totals but is not a
    | candidate, so it is left out of the 25% tracker.
    |
    */

    'parties' => $list(env('ELECTION_PARTIES', 'APC,PDP,LP,OTHERS')),

    'candidates' => [
        'APC' => 'Francis Ogbonna Nwifuru',
        'PDP' => 'Ifeanyi Chukwuma Odii',
        'LP' => 'Splendor Oko Eze',
    ],

    // The party this situation room works for. Weak links are worked out
    // for it; empty means "for the candidate currently leading".
    'principal_party' => env('ELECTION_PRINCIPAL_PARTY', ''),

    /*
    |--------------------------------------------------------------------------
    | Win Condition (section 179(2) of the 1999 Constitution)
    |--------------------------------------------------------------------------
    |
    | The highest number of votes, and at least 25% of the votes in at least
    | two-thirds of the LGAs. Ebonyi has 13 LGAs: two-thirds is 8.67, taken
    | here as 9. Confirm the rounding with the legal team.
    |
    */

    'lga_count' => (int) env('ELECTION_LGA_COUNT', 13),

    'spread_share' => (float) env('ELECTION_SPREAD_SHARE', 25),

    'spread_lgas_required' => (int) env('ELECTION_SPREAD_LGAS_REQUIRED', 9),

    // An LGA is a weak link when the candidate's share is below
    // spread_share + near_margin percentage points...
    'near_margin' => (float) env('ELECTION_NEAR_MARGIN', 5),

    // ...or when fewer than this % of its PUs have an accepted result.
    'low_coverage' => (float) env('ELECTION_LOW_COVERAGE', 50),

    /*
    |--------------------------------------------------------------------------
    | Official Results Comparison
    |--------------------------------------------------------------------------
    |
    | A PU is flagged when any party's IReV figure differs from our agent's
    | EC8A by at least discrepancy_votes. A ward or LGA collation is flagged
    | when any party's declared share of the valid votes differs from its PVT
    | share by at least discrepancy_share_points percentage points; with full
    | PVT coverage, a raw vote difference of discrepancy_votes also counts.
    |
    */

    'discrepancy_votes' => (int) env('ELECTION_DISCREPANCY_VOTES', 10),

    'discrepancy_share_points' => (float) env('ELECTION_DISCREPANCY_SHARE_POINTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Set-up
    |--------------------------------------------------------------------------
    |
    | The first admin account is created in the browser with ADMIN_PASSWORD
    | as a one-time setup key (the host has no terminal).
    |
    */

    'admin_password' => env('ADMIN_PASSWORD'),

    // Shared hosting has no permanent queue worker: the scheduler cron works
    // the queue for most of each minute instead.
    'scheduler_runs_queue' => (bool) env('SCHEDULER_RUNS_QUEUE', true),

];
