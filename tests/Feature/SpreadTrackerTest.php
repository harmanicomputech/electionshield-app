<?php

namespace Tests\Feature;

use App\Models\PollingUnit;
use App\Services\Collation;
use App\Services\SpreadTracker;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class SpreadTrackerTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    /**
     * One reported PU per LGA with the given votes; LGA names are LGA01..LGA13.
     *
     * @param  list<array<string, int>>  $votesByLga
     */
    private function election(array $votesByLga, bool $rehearsal = false): SpreadTracker
    {
        $ingestor = app(UssdIngestor::class);

        foreach ($votesByLga as $i => $votes) {
            $lga = sprintf('LGA%02d', $i + 1);
            $code = sprintf('%03d0000100%d', $i + 1, 1);
            $ingestor->result($this->resultPayload([
                'reference' => 'RS'.($i + 1),
                'polling_unit' => $this->unit($code, $lga, "{$lga} Ward 01", 1000),
                'votes' => $votes,
                'accredited_voters' => array_sum($votes) + 10,
            ]), $rehearsal);
        }

        $collation = new Collation($rehearsal);

        return new SpreadTracker($collation->state(), $collation->lgas());
    }

    public function test_the_leader_with_25_percent_in_9_lgas_would_be_declared(): void
    {
        $lgas = array_merge(
            array_fill(0, 9, ['APC' => 50, 'PDP' => 30, 'LP' => 20, 'OTHERS' => 0]),
            array_fill(0, 4, ['APC' => 20, 'PDP' => 60, 'LP' => 20, 'OTHERS' => 0]), // APC 530 votes, PDP 510
        );

        $tracker = $this->election($lgas);

        $this->assertSame(9, $tracker->lgasMet('APC'));
        $this->assertSame(13, $tracker->lgasMet('PDP'));
        $this->assertSame(['outcome' => 'declared', 'party' => 'APC'], $tracker->projection());

        $apc = collect($tracker->candidates())->firstWhere('party', 'APC');
        $this->assertTrue($apc['leading']);
        $this->assertTrue($apc['meets_spread']);
    }

    public function test_a_leader_short_of_the_spread_faces_a_runoff(): void
    {
        $lgas = array_merge(
            array_fill(0, 8, ['APC' => 90, 'PDP' => 5, 'LP' => 5, 'OTHERS' => 0]),
            array_fill(0, 5, ['APC' => 20, 'PDP' => 40, 'LP' => 40, 'OTHERS' => 0]),
        );

        $tracker = $this->election($lgas);

        $this->assertSame(8, $tracker->lgasMet('APC'));
        $this->assertSame(['outcome' => 'runoff', 'party' => 'APC'], $tracker->projection());
        $this->assertSame(1, collect($tracker->candidates())->firstWhere('party', 'APC')['lgas_needed']);
    }

    public function test_exactly_25_percent_counts(): void
    {
        $tracker = $this->election([['APC' => 25, 'PDP' => 75, 'LP' => 0, 'OTHERS' => 0]]);

        $this->assertSame(1, $tracker->lgasMet('APC'));
    }

    public function test_others_count_in_the_total_but_are_not_a_candidate(): void
    {
        $tracker = $this->election([['APC' => 20, 'PDP' => 10, 'LP' => 10, 'OTHERS' => 60]]);

        $this->assertSame(['outcome' => 'runoff', 'party' => 'APC'], $tracker->projection());
        $this->assertSame(0, $tracker->lgasMet('APC'));
        $this->assertSame(['APC', 'PDP', 'LP'], array_column($tracker->candidates(), 'party'));
    }

    public function test_weak_links_are_below_or_near_25_percent_or_poorly_covered(): void
    {
        config(['election.principal_party' => 'PDP']);

        $tracker = $this->election([
            ['APC' => 50, 'PDP' => 20, 'LP' => 30, 'OTHERS' => 0], // below
            ['APC' => 50, 'PDP' => 27, 'LP' => 23, 'OTHERS' => 0], // near
            ['APC' => 30, 'PDP' => 60, 'LP' => 10, 'OTHERS' => 0], // strong
        ]);

        // LGA03 has 3 PUs in the register but only 1 reported: low coverage.
        PollingUnit::create(['code' => '00300002001', 'lga' => 'LGA03', 'ward' => 'LGA03 Ward 01']);
        PollingUnit::create(['code' => '00300002002', 'lga' => 'LGA03', 'ward' => 'LGA03 Ward 01']);
        $collation = new Collation(false);
        $tracker = new SpreadTracker($collation->state(), $collation->lgas());

        $links = collect($tracker->weakLinks())->keyBy(fn ($link) => $link['lga']->name);

        $this->assertSame('PDP', $tracker->focusParty());
        $this->assertSame(['LGA01', 'LGA02', 'LGA03'], $links->keys()->all());
        $this->assertTrue($links['LGA01']['below']);
        $this->assertTrue($links['LGA02']['near']);
        $this->assertTrue($links['LGA03']['low_coverage']);
        $this->assertFalse($links['LGA03']['below']);
    }

    public function test_no_results_means_no_projection(): void
    {
        $this->assertSame(['outcome' => 'none', 'party' => null], $this->election([])->projection());
    }

    public function test_real_and_rehearsal_figures_never_mix(): void
    {
        $this->election([['APC' => 100, 'PDP' => 0, 'LP' => 0, 'OTHERS' => 0]], rehearsal: true);

        $collation = new Collation(false);
        $this->assertSame(0, $collation->state()->totalVotes());
        $this->assertSame(100, (new Collation(true))->state()->totalVotes());
    }

    public function test_collation_totals_add_up_by_lga_and_ward(): void
    {
        $ingestor = app(UssdIngestor::class);
        $ingestor->result($this->resultPayload(['reference' => 'RS1', 'polling_unit' => $this->unit('21202633001', 'Abakaliki', 'Ward A')]));
        $ingestor->result($this->resultPayload(['reference' => 'RS2', 'polling_unit' => $this->unit('21202633002', 'Abakaliki', 'Ward B')]));
        $ingestor->result($this->resultPayload(['reference' => 'RS3', 'polling_unit' => $this->unit('21302633001', 'Afikpo North', 'Ward C')]));
        PollingUnit::create(['code' => '21202633003', 'lga' => 'Abakaliki', 'ward' => 'Ward A', 'registered_voters' => 900]);

        $collation = new Collation(false);
        $lgas = $collation->lgas();

        $this->assertSame(['Abakaliki', 'Afikpo North'], array_keys($lgas));
        $this->assertSame(1220, $lgas['Abakaliki']->votes['APC']);
        $this->assertSame(2, $lgas['Abakaliki']->reported);
        $this->assertSame(3, $lgas['Abakaliki']->units);
        $this->assertSame(66.7, $lgas['Abakaliki']->coverage());
        $this->assertSame(1830, $collation->state()->votes['APC']);

        $wards = $collation->wards('Abakaliki');
        $this->assertSame(['Ward A', 'Ward B'], array_keys($wards));
        $this->assertSame(2, $wards['Ward A']->units);

        $units = $collation->units('Abakaliki', 'Ward A');
        $this->assertCount(2, $units);
        $this->assertSame('RS1', $units[0]['result']->reference);
        $this->assertNull($units[1]['result']);
    }
}
