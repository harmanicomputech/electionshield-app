<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Ec8aPhoto;
use App\Models\User;
use App\Services\Ec8aPhotoStore;
use App\Services\UssdIngestor;
use App\Support\UploadLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(UssdIngestor::class)->result($this->resultPayload());
    }

    private function photo(string $name = 'ec8a.jpg', int $seed = 1): UploadedFile
    {
        // A real JPEG, so thumbnails and dimensions work.
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, $seed * 20 % 255, 200, 180));
        ob_start();
        imagejpeg($image, null, 80);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    public function test_a_coordinator_uploads_a_photo_for_a_result(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Chidi']));

        $this->post('/photos', ['reference' => 'rs784321', 'photo' => $this->photo(), 'note' => 'From WhatsApp'])->assertRedirect();

        $photo = Ec8aPhoto::firstOrFail();
        $this->assertSame('RS784321', $photo->result_reference);
        $this->assertSame('coordinator', $photo->uploaded_via);
        $this->assertSame('Chidi', $photo->uploaded_by);
        $this->assertSame([1200, 900], [$photo->width, $photo->height]);
        $this->assertSame(64, strlen($photo->sha256));
        Storage::disk('local')->assertExists([$photo->path, $photo->thumbnail_path]);
        $this->assertSame(480, getimagesizefromstring(Storage::disk('local')->get($photo->thumbnail_path))[0]);
        $this->assertTrue(AuditLog::where('action', 'photo.uploaded')->exists());

        $this->get("/photos/{$photo->id}")->assertOk()->assertSee('610')->assertSee($photo->sha256);
        $this->get("/photos/{$photo->id}/image")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/photos/{$photo->id}/image/thumb")->assertOk();
        $this->get('/collation/Abakaliki/Abakaliki Ward 01')->assertSee('📷 1 EC8A');
    }

    public function test_the_same_file_twice_is_stored_once(): void
    {
        $this->actingAs(User::factory()->create());
        $file = $this->photo();

        $this->postJson('/photos', ['reference' => 'RS784321', 'photo' => $file])->assertOk()->assertJson(['status' => 'stored']);
        $this->postJson('/photos', ['reference' => 'RS784321', 'photo' => $file])->assertOk();

        $this->assertSame(1, Ec8aPhoto::count());
    }

    public function test_uploads_are_checked(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/photos', ['reference' => 'RS000000', 'photo' => $this->photo()])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson('/photos', ['reference' => 'RS784321', 'photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertJsonValidationErrors('photo');

        foreach (range(1, Ec8aPhotoStore::MAX_PER_RESULT) as $i) {
            $this->postJson('/photos', ['reference' => 'RS784321', 'photo' => $this->photo("p{$i}.jpg", $i)])->assertOk();
        }
        $this->postJson('/photos', ['reference' => 'RS784321', 'photo' => $this->photo('extra.jpg', 99)])->assertUnprocessable();
        $this->assertSame(Ec8aPhotoStore::MAX_PER_RESULT, Ec8aPhoto::count());
    }

    public function test_reviewing_a_photo(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Chidi']));
        $this->post('/photos', ['reference' => 'RS784321', 'photo' => $this->photo()]);
        $photo = Ec8aPhoto::firstOrFail();

        $this->postJson("/photos/{$photo->id}/review", ['review_status' => 'mismatch', 'review_note' => 'APC 610 on USSD, 510 on the sheet'])->assertOk();

        $photo->refresh();
        $this->assertSame('mismatch', $photo->review_status);
        $this->assertSame('Chidi', $photo->reviewed_by);
        $this->get('/photos?status=mismatch')->assertSee('Does not match');
        $this->get('/photos')->assertDontSee('RS784321 ·');
    }

    public function test_an_agent_uploads_with_the_link_and_no_login(): void
    {
        $url = UploadLink::url('RS784321');
        $this->assertStringEndsWith('/u/RS784321/'.UploadLink::token('RS784321'), $url);
        $this->assertSame(substr(hash_hmac('sha256', 'ec8a:RS784321', 'test-secret'), 0, 20), UploadLink::token('RS784321'));

        $this->get($url)->assertOk()->assertSee('Send the EC8A photo')->assertDontSee('610')->assertDontSee('+2348012345678');
        $this->postJson($url, ['photo' => $this->photo()])->assertOk();

        $photo = Ec8aPhoto::firstOrFail();
        $this->assertSame('agent', $photo->uploaded_via);
        $this->assertNull($photo->uploaded_by);
    }

    public function test_an_agent_link_works_before_the_result_arrives(): void
    {
        $url = UploadLink::url('RS999999');

        $this->get($url)->assertOk();
        $this->postJson($url, ['photo' => $this->photo()])->assertOk();

        $this->assertSame('RS999999', Ec8aPhoto::firstOrFail()->result_reference);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->get('/u/RS784321/0000000000aaaaaaaaaa')->assertNotFound();
        $this->postJson('/u/RS784321/0000000000aaaaaaaaaa', ['photo' => $this->photo()])->assertNotFound();
        $this->postJson('/u/RS784322/'.UploadLink::token('RS784321'), ['photo' => $this->photo()])->assertNotFound();

        $this->assertSame(0, Ec8aPhoto::count());
    }

    public function test_photos_are_private(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/photos', ['reference' => 'RS784321', 'photo' => $this->photo()]);
        $photo = Ec8aPhoto::firstOrFail();
        auth()->logout();

        $this->get("/photos/{$photo->id}/image")->assertRedirect('/login');
        $this->get('/photos')->assertRedirect('/login');
    }

    public function test_only_admins_delete_photos_and_the_file_goes_too(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/photos', ['reference' => 'RS784321', 'photo' => $this->photo()]);
        $photo = Ec8aPhoto::firstOrFail();

        $this->delete("/photos/{$photo->id}")->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->delete("/photos/{$photo->id}")->assertRedirect('/photos');
        Storage::disk('local')->assertMissing($photo->path);
        $this->assertSame(0, Ec8aPhoto::count());
    }

    public function test_the_evidence_export_includes_photo_fingerprints(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/photos', ['reference' => 'RS784321', 'photo' => $this->photo()]);
        $this->put('/official/pu/21202633007', ['irev_status' => 'uploaded', 'votes' => ['APC' => 500, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18]]);

        $lines = array_map('str_getcsv', array_filter(explode("\n", $this->get('/compare/export')->streamedContent())));
        $row = array_combine($lines[0], $lines[1]);

        $this->assertSame('1', $row['ec8a_photos']);
        $this->assertSame(Ec8aPhoto::firstOrFail()->sha256, $row['ec8a_photo_sha256']);
    }
}
