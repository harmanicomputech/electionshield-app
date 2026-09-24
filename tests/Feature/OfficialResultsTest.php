<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficialCollation;
use App\Models\OfficialResult;
use App\Models\User;
use App\Services\ResultComparison;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class OfficialResultsTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    private UssdIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestor = app(UssdIngestor::class);

        // Ward A has two PUs; both report 610/402/95/18 to the PVT.
        foreach (['21202633001', '21202633002'] as $i => $code) {
            $this->ingestor->result($this->resultPayload(['reference' => 'RS'.$i, 'polling_unit' => $this->unit($code, 'Abakaliki', 'Ward A')]));
        }
        $this->ingestor->pollingUnit($this->unit('21202633003', 'Abakaliki', 'Ward B'));
    }

    private function enter(string $code, array $votes, string $status = 'uploaded'): void
    {
        $this->put("/official/pu/{$code}", ['irev_status' => $status, 'votes' => $votes, 'accredited_voters' => 1200, 'rejected_votes' => 21])->assertRedirect();
    }

    public function test_entering_an_irev_result_and_comparing_it(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Chidi']));

        // The entry form doesn't show our figures.
        $this->get('/official/pu/21202633001')->assertOk()->assertDontSee('610');

        $this->enter('21202633001', ['APC' => 610, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]);
        $this->enter('21202633002', ['APC' => 540, 'PDP' => 480, 'LP' => 95, 'OTHERS' => 18]);
        $this->enter('21202633003', [], 'not_uploaded');

        $this->assertSame('Chidi', OfficialResult::where('polling_unit_code', '21202633001')->value('entered_by'));
        $this->assertSame('Abakaliki', OfficialResult::where('polling_unit_code', '21202633001')->value('lga'));

        $comparison = new ResultComparison(false);
        $units = $comparison->units();

        $this->assertSame([], array_intersect($units['21202633001']['flags'], ['discrepancy', 'no_upload']));
        $this->assertSame(['APC' => -70, 'PDP' => 78, 'LP' => 0, 'OTHERS' => 0], $units['21202633002']['diff']);
        $this->assertContains('discrepancy', $units['21202633002']['flags']);
        $this->assertContains('no_upload', $units['21202633003']['flags']);
        $this->assertContains('no_pvt', $units['21202633003']['flags']);
        $this->assertSame(['21202633002', '21202633003'], array_map('strval', $comparison->flagged($units)->keys()->all()));

        $this->get('/official/pu/21202633002')->assertSee('Differs by up to 78 votes');
        $this->get('/compare')->assertOk()->assertSee('Differs by up to 78 votes')->assertSee('No IReV upload');
        $this->assertSame(3, AuditLog::where('action', 'official.pu')->count());
    }

    public function test_the_threshold_decides_what_is_flagged(): void
    {
        $this->actingAs(User::factory()->create());
        $this->enter('21202633001', ['APC' => 601, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]);

        $this->assertNotContains('discrepancy', (new ResultComparison(false))->units()['21202633001']['flags']);

        config(['election.discrepancy_votes' => 5]);
        $this->assertContains('discrepancy', (new ResultComparison(false))->units()['21202633001']['flags']);
    }

    public function test_uploaded_results_need_every_party(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/official/pu/21202633001', ['irev_status' => 'uploaded', 'votes' => ['APC' => 610]])->assertSessionHasErrors('votes.PDP');
        $this->put('/official/pu/99999999999', ['irev_status' => 'not_uploaded'])->assertNotFound();
    }

    public function test_over_voting_is_warned_about_but_saved(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/official/pu/21202633001', ['irev_status' => 'uploaded', 'votes' => ['APC' => 1000, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18], 'accredited_voters' => 1200])
            ->assertSessionHas('warnings', fn ($warnings) => str_contains($warnings[0], 'over-voting'));
        $this->assertSame(1, OfficialResult::count());
    }

    public function test_declared_collations_are_compared_by_share(): void
    {
        $this->actingAs(User::factory()->create());

        // PVT ward A: APC 1220 of 2250 = 54.22%. Declared: APC 45%.
        $this->put('/official/collations/ward/Abakaliki/Ward A', ['votes' => ['APC' => 450, 'PDP' => 450, 'LP' => 80, 'OTHERS' => 20]])->assertRedirect('/compare/Abakaliki');
        $this->put('/official/collations/lga/Abakaliki', ['votes' => ['APC' => 1230, 'PDP' => 800, 'LP' => 190, 'OTHERS' => 36]])->assertRedirect();

        $wards = collect((new ResultComparison(false))->collations('Abakaliki'))->keyBy('name');
        $this->assertTrue($wards['Ward A']['flagged']);
        $this->assertSame(-9.22, $wards['Ward A']['share_diff']['APC']);
        $this->assertTrue($wards['Ward A']['complete']);
        $this->assertNull($wards['Ward B']['declared']);

        $lgas = collect((new ResultComparison(false))->collations())->keyBy('name');
        $this->assertFalse($lgas['Abakaliki']['flagged']);
        $this->assertFalse($lgas['Abakaliki']['complete']);

        $this->get('/compare')->assertOk()->assertSee('Consistent')->assertSee('PVT partial');
        $this->get('/compare/Abakaliki')->assertOk()->assertSee('Differs 9.27 pts');
        $this->get('/official/collations/ward/Abakaliki/Nowhere')->assertNotFound();
        $this->get('/official/collations/state/Abakaliki')->assertNotFound();
    }

    public function test_admins_import_irev_results_and_collations_from_csv(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $csv = "pu_code,irev_status,accredited,APC,PDP,LP,OTHERS,rejected,note\n"
            ."EB/212/02633/001,uploaded,1200,610,402,95,18,21,\n"
            ."21202633002,Not uploaded,,,,,,,No sheet\n"
            ."21202633099,uploaded,1,1,1,1,1,1,\n"
            ."21202633003,uploaded,1,x,1,1,1,1,\n";
        $this->post('/official/import', ['type' => 'irev', 'file' => UploadedFile::fake()->createWithContent('irev.csv', $csv)])
            ->assertSessionHas('import_errors', fn ($errors) => count($errors) === 2 && str_contains($errors[0], 'Line 4') && str_contains($errors[1], 'APC'));

        $this->assertSame(2, OfficialResult::count());
        $this->assertSame('not_uploaded', OfficialResult::where('polling_unit_code', '21202633002')->value('irev_status'));
        $this->assertSame('import', OfficialResult::where('polling_unit_code', '21202633001')->value('source'));

        $csv = "level,lga,ward,accredited,APC,PDP,LP,OTHERS,rejected\nward,Abakaliki,Ward A,2400,\"1,220\",804,190,36,42\nlga,Abakaliki,,2400,1220,804,190,36,42\n";
        $this->post('/official/import', ['type' => 'collations', 'file' => UploadedFile::fake()->createWithContent('c.csv', $csv)])->assertSessionHas('status');

        $this->assertSame(2, OfficialCollation::count());
        $this->assertSame(1220, OfficialCollation::where('level', 'ward')->first()->votesByParty()['APC']);

        // Importing again updates rather than duplicates.
        $this->post('/official/import', ['type' => 'collations', 'file' => UploadedFile::fake()->createWithContent('c.csv', $csv)]);
        $this->assertSame(2, OfficialCollation::count());
    }

    public function test_the_evidence_export_lists_flagged_pus(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->enter('21202633001', ['APC' => 610, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]);
        $this->enter('21202633002', ['APC' => 540, 'PDP' => 480, 'LP' => 95, 'OTHERS' => 18]);

        $csv = $this->get('/compare/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $header = $lines[0];

        $this->assertCount(2, $lines);
        $row = array_combine($header, $lines[1]);
        $this->assertSame('21202633002', $row['pu_code']);
        $this->assertSame('EB/212/02633/002', $row['inec_code']);
        $this->assertSame('RS1', $row['pvt_reference']);
        $this->assertSame('+2348012345678', $row['pvt_agent_phone']);
        $this->assertSame('-70', $row['diff_APC']);
        $this->assertSame('480', $row['irev_PDP']);

        $all = $this->get('/compare/export?all=1')->streamedContent();
        $this->assertCount(3, array_filter(explode("\n", $all)));
        $this->assertTrue(AuditLog::where('action', 'compare.export')->exists());
    }

    public function test_coordinators_enter_results_but_cannot_import_export_or_delete(): void
    {
        $this->actingAs(User::factory()->create());
        $this->enter('21202633001', ['APC' => 610, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]);

        $this->get('/official/import')->assertForbidden();
        $this->get('/compare/export')->assertForbidden();
        $this->delete('/official/pu/21202633001')->assertForbidden();
        $this->get('/official')->assertOk()->assertDontSee('Import CSV');
        $this->get('/official?code=EB/212/02633/001')->assertRedirect('/official/pu/21202633001');
    }
}
