<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\TownHallQuestion;
use App\Models\TownHallSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class TownHallTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(array $overrides = []): TownHallSession
    {
        return TownHallSession::create(array_replace([
            'slug' => 'meet-the-candidate-feb01',
            'title' => 'Meet the candidate',
            'host' => 'The candidate',
            'starts_at' => now()->subMinutes(10),
            'stream_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'questions_open' => true,
            'published' => true,
        ], $overrides));
    }

    private function question(TownHallSession $session, string $body, string $status = 'pending'): TownHallQuestion
    {
        $question = TownHallQuestion::create(['town_hall_session_id' => $session->id, 'body' => $body, 'status' => $status, 'name' => 'Ngozi', 'lga' => 'Ikwo']);
        $question->forceFill(['moderated_at' => $status === 'pending' ? null : now()])->save();

        return $question;
    }

    public function test_the_public_page_embeds_the_stream_and_shows_only_approved_questions(): void
    {
        $session = $this->makeSession();
        $this->question($session, 'What will you do about rural roads in Ikwo?', 'approved');
        $this->question($session, 'This is spam spam spam', 'rejected');
        $this->question($session, 'Unmoderated question about schools', 'pending');

        $this->get('/townhall')->assertOk()->assertSee('Live now')->assertSee('Meet the candidate');
        $this->get('/townhall/meet-the-candidate-feb01')->assertOk()
            ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertSee('rural roads in Ikwo')
            ->assertDontSee('spam spam')
            ->assertDontSee('Unmoderated');

        $this->getJson('/townhall/meet-the-candidate-feb01/questions')->assertOk()
            ->assertJsonCount(1, 'questions')
            ->assertJsonPath('questions.0.name', 'Ngozi')
            ->assertJsonPath('phase', 'live');
    }

    public function test_voters_ask_questions_which_wait_for_moderation(): void
    {
        $session = $this->makeSession();

        $this->post("/townhall/{$session->slug}/ask", ['body' => 'Short'])->assertSessionHasErrors('body');
        $this->post("/townhall/{$session->slug}/ask", ['body' => 'How will you pay teachers on time?', 'name' => 'Emeka', 'lga' => 'Nowhere'])->assertSessionHasErrors('lga');
        $this->post("/townhall/{$session->slug}/ask", ['body' => 'How will you pay teachers on time?', 'name' => 'Emeka'])->assertRedirect("/townhall/{$session->slug}");
        $this->post("/townhall/{$session->slug}/ask", ['body' => 'Buy cheap watches here now', 'website' => 'http://spam'])->assertRedirect();

        $question = TownHallQuestion::sole();
        $this->assertSame('pending', $question->status);
        $this->assertSame('Emeka', $question->name);
        $this->assertSame(64, strlen($question->ip_hash));
        $this->assertNotSame('127.0.0.1', $question->ip_hash);
    }

    public function test_a_flood_from_one_visitor_is_slowed_down(): void
    {
        $session = $this->makeSession();

        foreach (range(1, 5) as $i) {
            $this->withoutMiddleware(ThrottleRequests::class)
                ->post("/townhall/{$session->slug}/ask", ['body' => "Question number {$i} about the economy"]);
        }
        $this->withoutMiddleware(ThrottleRequests::class)
            ->post("/townhall/{$session->slug}/ask", ['body' => 'Question number 6 about the economy'])->assertSessionHas('error');

        $this->assertSame(5, TownHallQuestion::count());
    }

    public function test_closed_ended_and_hidden_sessions(): void
    {
        $closed = $this->makeSession(['slug' => 'closed', 'questions_open' => false]);
        $this->post('/townhall/closed/ask', ['body' => 'Is anyone listening to us?'])->assertSessionHas('error');

        $ended = $this->makeSession(['slug' => 'ended', 'starts_at' => now()->subDays(2), 'recording_url' => 'https://youtu.be/abcdefghijk']);
        $this->get('/townhall/ended')->assertSee('youtube-nocookie.com/embed/abcdefghijk', false)->assertSee('Questions are closed');

        $this->makeSession(['slug' => 'hidden', 'published' => false]);
        $this->get('/townhall/hidden')->assertNotFound();
        $this->get('/townhall')->assertDontSee('/townhall/hidden');
        $this->assertSame(0, TownHallQuestion::count());
    }

    public function test_moderators_approve_put_on_air_and_answer(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Moderator']));
        $session = $this->makeSession();
        $first = $this->question($session, 'First question about water supply');
        $second = $this->question($session, 'Second question about jobs for youth');

        $this->get("/manage/townhall/{$session->slug}")->assertOk()->assertSee('water supply');

        $this->postJson("/manage/townhall/{$session->slug}/questions/{$first->id}", ['action' => 'approve'])->assertOk();
        $this->postJson("/manage/townhall/{$session->slug}/questions/{$first->id}", ['action' => 'on_air'])->assertOk();
        $this->postJson("/manage/townhall/{$session->slug}/questions/{$second->id}", ['action' => 'on_air'])->assertOk();

        // One question on air at a time; putting one on air approves it.
        $this->assertNull($first->fresh()->on_air_at);
        $this->assertNotNull($second->fresh()->on_air_at);
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertSame('Moderator', $second->fresh()->moderated_by);

        $this->getJson("/manage/townhall/{$session->slug}/present.json")->assertJsonPath('question.body', 'Second question about jobs for youth');
        $this->get("/manage/townhall/{$session->slug}/present")->assertOk()->assertSee('jobs for youth');

        $this->postJson("/manage/townhall/{$session->slug}/questions/{$second->id}", ['action' => 'answered'])->assertOk();
        $this->assertSame('answered', $second->fresh()->status);
        $this->assertNull($second->fresh()->on_air_at);
        $public = collect($this->getJson("/townhall/{$session->slug}/questions")->json('questions'))->keyBy('body');
        $this->assertTrue($public['Second question about jobs for youth']['answered']);
        $this->assertFalse($public['First question about water supply']['answered']);
    }

    public function test_questions_belong_to_their_session(): void
    {
        $this->actingAs(User::factory()->create());
        $one = $this->makeSession();
        $other = $this->makeSession(['slug' => 'other']);
        $question = $this->question($other, 'A question for the other session');

        $this->postJson("/manage/townhall/{$one->slug}/questions/{$question->id}", ['action' => 'approve'])->assertNotFound();
        $this->assertSame('pending', $question->fresh()->status);
    }

    public function test_admins_manage_sessions_and_draft_a_reminder(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name' => 'Kehinde']));

        $this->post('/manage/townhall-sessions', ['title' => 'X', 'starts_at' => '2026-12-01 18:00', 'stream_url' => 'https://example.com/video'])->assertSessionHasErrors('stream_url');
        $this->post('/manage/townhall-sessions', ['title' => 'Youth town hall', 'starts_at' => '2026-12-01 18:00', 'stream_url' => 'https://www.facebook.com/Candidate/videos/123', 'questions_open' => 1, 'published' => 1])->assertRedirect();

        $session = TownHallSession::sole();
        $this->assertSame('youth-town-hall-dec01', $session->slug);
        $this->assertSame('2026-12-01 17:00', $session->starts_at->format('Y-m-d H:i')); // stored in UTC
        $this->assertSame('facebook', TownHallSession::embed($session->stream_url)['provider']);

        $this->post("/manage/townhall-sessions/{$session->slug}/reminder")->assertRedirect();
        $broadcast = Broadcast::sole();
        $this->assertSame('draft', $broadcast->status);
        $this->assertStringContainsString('Youth town hall, Tue 1 Dec, 6:00pm', $broadcast->message);
        $this->assertStringContainsString('/townhall/youth-town-hall-dec01', $broadcast->message);
        $this->assertSame(['supporters'], $broadcast->audience['groups']);

        $this->actingAs(User::factory()->create());
        $this->get('/manage/townhall-sessions/create')->assertForbidden();
        $this->get('/manage/townhall')->assertOk();
    }

    public function test_stream_links_become_embeds(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/AbCdEfGhIjK?autoplay=1&rel=0', TownHallSession::embed('https://www.youtube.com/live/AbCdEfGhIjK?si=x')['src']);
        $this->assertSame('AbCdEfGhIjK', basename(parse_url(TownHallSession::embed('https://youtu.be/AbCdEfGhIjK')['src'], PHP_URL_PATH)));
        $this->assertStringStartsWith('https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Ffb.watch%2Fabc', TownHallSession::embed('https://fb.watch/abc')['src']);
        $this->assertNull(TownHallSession::embed('https://vimeo.com/123'));
        $this->assertNull(TownHallSession::embed(null));
    }
}
