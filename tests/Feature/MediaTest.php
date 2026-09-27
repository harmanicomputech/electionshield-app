<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Incident;
use App\Models\User;
use App\Services\FieldMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private Attachment $video;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Incident::query()->create(['reference' => 'IN300001', 'polling_unit_code' => '21202633007', 'lga' => 'Ikwo', 'ward' => 'Ikwo Ward 01', 'type' => 'violence', 'type_label' => 'Violence', 'urgent' => true, 'agent_name' => 'Ada Obi', 'agent_phone' => '+2348012345678', 'reported_at' => now(), 'channel' => 'web']);
        $this->video = app(FieldMedia::class)->store(UploadedFile::fake()->create('fight.mp4', 2000, 'video/mp4'), 'IN300001', Attachment::VIDEO, null);
    }

    public function test_staff_see_incident_media_and_the_same_file_is_stored_once(): void
    {
        $again = app(FieldMedia::class)->store(UploadedFile::fake()->createWithContent('fight.mp4', Storage::disk('local')->get($this->video->path)), 'IN300001', Attachment::VIDEO, null);
        $this->assertSame($this->video->id, $again->id);
        $this->assertSame(1, Attachment::query()->count());

        $this->actingAs(User::factory()->create());
        $this->get('/incidents')->assertOk()->assertSee('via Web app')->assertSee(route('media.file', $this->video), false);
        $this->get('/media')->assertOk()->assertSee('Violence · IN300001');
        $this->get(route('media.file', $this->video))->assertOk()->assertHeader('Content-Type', 'video/mp4');

        $this->post(route('media.review', $this->video), ['review_status' => 'doubtful'])->assertRedirect();
        $this->assertSame('doubtful', $this->video->fresh()->review_status);
        $this->delete(route('media.destroy', $this->video))->assertForbidden();
    }

    public function test_agents_open_only_their_own_files(): void
    {
        $this->actingAs(User::factory()->agent('+2348099999999')->create());
        $this->get(route('media.file', $this->video))->assertForbidden();
        $this->get('/media')->assertForbidden();

        $this->actingAs(User::factory()->agent('+2348012345678')->create());
        $this->get(route('media.file', $this->video))->assertOk();
    }

    public function test_admins_delete_evidence_and_the_file_goes(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Storage::disk('local')->assertExists($this->video->path);

        $this->delete(route('media.destroy', $this->video))->assertRedirect();

        Storage::disk('local')->assertMissing($this->video->path);
        $this->assertModelMissing($this->video);
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.deleted']);
    }
}
