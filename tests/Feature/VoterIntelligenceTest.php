<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PollingUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VoterStat;
use App\Services\PollingUnitImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VoterIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('figures.csv', $content);
    }

    public function test_the_state_page_shows_the_register_and_inecs_figures_with_their_sources(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $page = $this->get('/intelligence')->assertOk()
            ->assertSee('Ebonyi State')
            ->assertSee('1,597,646')                       // INEC's 2023 Ebonyi total
            ->assertSee('2,940')                           // PUs in the register
            ->assertSee('Registered voters per LGA, ward and polling unit aren')
            ->assertSee('Ikwo')->assertSee(route('intelligence.lga', 'Ikwo'), false)
            ->assertSee('No local figures · showing Nigeria (national)')
            ->assertSee('Largest age group: 18–34 (youth) (39.7%).', false)
            ->assertSee('More men than women: Men (52.5%).')
            ->assertSee('Largest occupation group recorded: Students (27.8%).')
            ->assertSee('INEC 2023 register, presented 11 Jan 2023 (Premium Times)')
            ->assertSee('Load figures');
        // Nothing made up: no PVC or first-time figures are built in.
        $page->assertSee('No figures loaded yet. INEC publishes PVC collection');
        $this->assertSame(0, VoterStat::whereIn('dimension', ['pvc', 'first_time'])->count());
    }

    public function test_drilling_down_to_a_ward_and_a_polling_unit(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        PollingUnit::where('code', '110101001')->update(['registered_voters' => 700]);
        Agent::create(['ussd_id' => 1, 'name' => 'Ada', 'phone_number' => '+2348011111111', 'polling_unit_code' => '110101001']);
        Volunteer::create(['reference' => 'VL1', 'name' => 'Obi', 'phone_number' => '+2348022222222', 'contact_phone' => '+2348022222222', 'lga' => 'Abakaliki', 'ward' => 'Abakpa', 'roles' => ['canvass'], 'registered_at' => now()]);

        $this->get('/intelligence/Ikwo')->assertOk()->assertSee('Registered voters for Ikwo LGA aren')->assertSee('Load figures');
        $this->get('/intelligence/Abakaliki')->assertOk()->assertSee('Abakaliki LGA')->assertSee('Abakpa')->assertDontSee('Registered voters for Abakaliki LGA aren')
            ->assertSee('1/', false)                      // 1 of Abakpa's PUs has a voter number
            ->assertSee(route('intelligence.ward', ['Abakaliki', 'Abakpa']), false);
        $this->get('/intelligence/Abakaliki/Abakpa')->assertOk()->assertSee('Abakpa ward')
            ->assertSee('ADAZI-ENU HALL I')->assertSee('11/01/01/001')->assertSee('700')
            ->assertSee('polling units here have no agent assigned');
        $this->get('/intelligence/Abakaliki/Amagu / Enyigba')->assertOk();
        $this->get('/intelligence/pu/11/01/01/001')->assertNotFound();
        $this->get('/intelligence/pu/110101001')->assertOk()->assertSee('ADAZI-ENU HALL I')->assertSee('Abakpa ward, Abakaliki LGA');
        $this->get('/intelligence/Nowhere')->assertNotFound();
        $this->get('/intelligence/Ebonyi/Amagu / Enyigba')->assertNotFound();
    }

    public function test_local_figures_replace_the_national_picture(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Kehinde']);
        $this->actingAs($admin);

        $this->post('/intelligence/data/figures', ['file' => $this->csv(
            "level,lga,ward,pu_code,dimension,category,count,percent,source,source_url,as_of,note\n"
            ."lga,ikwo,,,registered,total,160000,,INEC 2027 register,https://inecnigeria.org/x,2027-01-10,\n"
            ."lga,Ikwo,,,pvc,uncollected,\"12,000\",,INEC PVC statistics,https://inecnigeria.org/statistics/pvc,20 Jan 2027,\n"
            ."lga,Ikwo,,,gender,Women,,54.2,Campaign survey,,,sample of 900\n"
            ."lga,Ikwo,,,gender,Men,,45.8,Campaign survey,,,\n"
            ."ward,Abakaliki,Abakpa,,first_time,new,300,,INEC CVR,,,\n"
            ."pu,,,11/01/01/001,age,18 - 34,120,,INEC CVR,,,\n"
        )])->assertSessionHas('status', '6 figures saved.');

        $this->assertSame(12000, VoterStat::where(['level' => 'lga', 'area' => 'Ikwo', 'dimension' => 'pvc', 'category' => 'uncollected'])->value('count'));
        $this->assertSame('18-34', VoterStat::where(['level' => 'pu', 'area' => '110101001'])->value('category'));
        $this->assertSame('Kehinde', VoterStat::where('source', 'Campaign survey')->value('added_by'));

        $this->get('/intelligence/Ikwo')->assertOk()
            ->assertSee('160,000')
            ->assertSee('Figures for Ikwo LGA')
            ->assertSee('More women than men: Women (54.2%).')
            ->assertSee('12,000 PVCs not collected (7.5%)', false)
            ->assertSee('Campaign survey');
        // The state page lists Ikwo's figures and the loaded sources.
        $this->get('/intelligence')->assertSee('PVCs not collected')->assertSee('12,000')->assertSee('Most PVCs not collected: Ikwo (12,000).');
        $this->get('/intelligence/Abakaliki')->assertSee('First-time voters')->assertSee('300');
        // A PU with its own age figure; other dimensions come from larger areas.
        $this->get('/intelligence/pu/110101001')->assertSee('Figures for this polling unit')->assertSee('No local figures · showing Nigeria (national)');

        // Uploading again replaces rather than duplicates; removing a source removes its figures.
        $this->post('/intelligence/data/figures', ['file' => $this->csv("level,lga,ward,pu_code,dimension,category,count,percent,source\nlga,Ikwo,,,pvc,uncollected,11000,,INEC PVC statistics\n")]);
        $this->assertSame(11000, VoterStat::where(['area' => 'Ikwo', 'dimension' => 'pvc'])->value('count'));
        $this->post('/intelligence/data/remove', ['source' => 'Campaign survey'])->assertSessionHas('status', 'Removed 2 figures from "Campaign survey".');
        $this->assertSame(0, VoterStat::where('source', 'Campaign survey')->count());
        $this->assertSame(3, AuditLog::where('action', 'like', 'voter_data.%')->count());
    }

    public function test_a_file_with_any_bad_row_saves_nothing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/intelligence/data/figures', ['file' => $this->csv(
            "level,lga,ward,pu_code,dimension,category,count,percent,source\n"
            ."lga,Ikwo,,,pvc,uncollected,100,,INEC\n"
            ."lga,Atlantis,,,pvc,uncollected,100,,INEC\n"
            ."ward,Ikwo,Abakpa,,pvc,uncollected,100,,INEC\n"
            ."pu,,,999,pvc,uncollected,100,,INEC\n"
            ."region,,,,pvc,uncollected,100,,INEC\n"
            ."state,,,,mood,happy,100,,INEC\n"
            ."state,,,,gender,male,,120,INEC\n"
            ."state,,,,gender,male,,,INEC\n"
            ."state,,,,gender,male,5,,\n"
            ."state,,,,pvc,collected,1500000,,EXAMPLE (replace with the real source)\n"
        )])->assertSessionHas('error', fn ($message) => str_contains($message, 'Nothing was saved')
            && str_contains($message, 'Line 3: LGA "Atlantis" is not in the register')
            && str_contains($message, 'Line 4: ward "Abakpa" is not in Ikwo LGA')
            && str_contains($message, 'Line 5: PU "999" is not in the register')
            && str_contains($message, 'Line 6: unknown level')
            && str_contains($message, 'Line 7: unknown dimension')
            && str_contains($message, 'Line 8: percent "120"')
            && str_contains($message, 'Line 9: needs a count or a percent')
            && str_contains($message, 'Line 10: missing source')
            && str_contains($message, 'Line 11: this is an example row'));

        $this->assertSame(0, VoterStat::where('level', 'lga')->count());
        $this->post('/intelligence/data/figures', ['file' => $this->csv("lga,count\nIkwo,1\n")])->assertSessionHas('error', fn ($m) => str_contains($m, 'no "level" column'));
    }

    public function test_registered_voters_per_pu_and_the_register_download(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/intelligence/data/voters', ['file' => $this->csv("code,registered_voters\n11/01/01/001,\"1,020\"\n110101002,640\n")])
            ->assertSessionHas('status', fn ($m) => str_starts_with($m, 'Registered voters saved for 2 polling units.'));
        $this->assertSame([1020, 640], PollingUnit::whereIn('code', ['110101001', '110101002'])->orderBy('code')->pluck('registered_voters')->all());

        $this->post('/intelligence/data/voters', ['file' => $this->csv("code,registered_voters\n110101001,9\n99,5\n")])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'PU "99" is not in the register'));
        $this->assertSame(1020, PollingUnit::where('code', '110101001')->value('registered_voters'));

        $csv = $this->get('/intelligence/data/register.csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("code,name,ward,lga,registered_voters,latitude,longitude\n11/01/01/001,\"ADAZI-ENU HALL I\",Abakpa,Abakaliki,1020,6.3166473,8.1160672\n", $csv);
        $this->assertSame(2941, substr_count($csv, "\n"));

        $template = $this->get('/intelligence/data/template.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('EXAMPLE', $template);
    }

    public function test_who_can_see_and_who_can_load(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $this->assertTrue(Role::where('key', 'coordinator')->first()->allows('view_voter_intelligence'));

        $this->actingAs($coordinator);
        $this->get('/intelligence')->assertOk()->assertDontSee('Load figures')->assertSee('Voter intelligence');
        $this->post('/intelligence/data/figures', ['file' => $this->csv("level\n")])->assertForbidden();
        $this->get('/intelligence/data/template.csv')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'observer']));
        $this->get('/intelligence')->assertForbidden();
    }
}
