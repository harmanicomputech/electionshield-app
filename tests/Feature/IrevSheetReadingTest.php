<?php

namespace Tests\Feature;

use App\Models\OfficialResult;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\IrevSheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IrevSheetReadingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public static array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        self::$asked = [];
        config(['services.anthropic.key' => 'sk-test']);
        PollingUnit::query()->create(['code' => '21202633007', 'name' => 'Polling Unit 007', 'ward' => 'Abakaliki Ward 01', 'lga' => 'Abakaliki', 'registered_voters' => 1507]);

        $this->app->instance(IrevSheetReader::class, new class extends IrevSheetReader
        {
            protected function ask(array $content, array $schema): string
            {
                IrevSheetReadingTest::$asked[] = compact('content', 'schema');

                return json_encode(['legible' => true, 'pu_code' => 'EB/212/02633/007', 'accredited_voters' => 1200, 'rejected_votes' => 21, 'votes' => ['APC' => 610, 'PDP' => 402, 'LP' => null, 'OTHERS' => 18], 'notes' => 'LP figure overwritten']);
            }
        });
    }

    public function test_an_uploaded_sheet_fills_the_form_and_is_kept_when_saved(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Coord One']));

        $this->get('/official/pu/21202633007')->assertOk()->assertSee('Read the sheet with AI');

        $this->post('/official/pu/21202633007/read', ['sheet_file' => UploadedFile::fake()->image('ec8a.jpg')])
            ->assertRedirect('/official/pu/21202633007')
            ->assertSessionHasInput('accredited_voters', 1200)
            ->assertSessionHasInput('votes', ['APC' => '610', 'PDP' => '402', 'LP' => '', 'OTHERS' => '18']);

        $sheet = session()->getOldInput('sheet');
        $this->assertSame('image', self::$asked[0]['content'][0]['type']);
        $this->assertSame(['APC', 'PDP', 'LP', 'OTHERS'], self::$asked[0]['schema']['properties']['votes']['required']);

        $this->get('/official/pu/21202633007')->assertSee('AI read these figures')->assertSee('Some figures could not be read')->assertSee('value="'.$sheet.'"', false);
        $this->assertStringStartsWith('irev/21202633007/', $sheet);
        Storage::disk('local')->assertExists($sheet);

        // Nothing is saved until a person checks and saves.
        $this->assertSame(0, OfficialResult::query()->count());
        $this->put('/official/pu/21202633007', ['irev_status' => 'uploaded', 'votes' => ['APC' => 610, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18], 'accredited_voters' => 1200, 'rejected_votes' => 21, 'sheet' => $sheet])->assertRedirect();

        $official = OfficialResult::query()->sole();
        $this->assertSame('ai-read, checked', $official->source);
        $this->assertSame($sheet, $official->sheet_path);
        $this->get('/official/pu/21202633007/sheet')->assertOk();
    }

    public function test_links_must_be_https_on_an_allowed_host(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['docs.inecelectionresults.net/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/octet-stream'])]);

        $this->post('/official/pu/21202633007/read', ['sheet_url' => 'https://evil.example.com/sheet.jpg'])->assertSessionHas('error');
        $this->post('/official/pu/21202633007/read', ['sheet_url' => 'http://docs.inecelectionresults.net/a.pdf'])->assertSessionHasErrors('sheet_url');
        $this->assertSame([], self::$asked);

        $this->post('/official/pu/21202633007/read', ['sheet_url' => 'https://docs.inecelectionresults.net/elections/a.pdf'])->assertSessionHasInput('accredited_voters', 1200);
        $this->assertSame('document', self::$asked[0]['content'][0]['type']);
        $this->assertSame('application/pdf', self::$asked[0]['content'][0]['source']['mediaType']);
    }

    public function test_without_an_api_key_or_permission(): void
    {
        config(['services.anthropic.key' => null]);
        $this->actingAs(User::factory()->create());
        $this->get('/official/pu/21202633007')->assertSee('ANTHROPIC_API_KEY');
        $this->post('/official/pu/21202633007/read', ['sheet_file' => UploadedFile::fake()->image('ec8a.jpg')])->assertSessionHas('error');

        $this->actingAs(User::factory()->role('observer')->create());
        $this->post('/official/pu/21202633007/read', ['sheet_file' => UploadedFile::fake()->image('ec8a.jpg')])->assertForbidden();
    }
}
