<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PuMonitor;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    private UssdIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestor = app(UssdIngestor::class);

        foreach (['21202633001', '21202633002', '21202633003'] as $code) {
            $this->ingestor->pollingUnit($this->unit($code, 'Abakaliki', 'Ward A'));
        }
        $this->ingestor->pollingUnit($this->unit('21302633001', 'Afikpo North', 'Ward C'));
    }

    private function agent(): array
    {
        return ['name' => 'Ada Obi', 'phone_number' => '+2348012345678'];
    }

    public function test_each_pu_shows_check_in_latest_materials_result_and_open_incidents(): void
    {
        $this->ingestor->presence(['id' => 1, 'polling_unit' => ['code' => '21202633001'], 'agent' => $this->agent(), 'confirmed_at' => '2027-02-06T07:20:00+00:00'], false);
        $this->ingestor->presence(['id' => 2, 'polling_unit' => ['code' => '21202633001'], 'agent' => $this->agent(), 'confirmed_at' => '2027-02-06T07:05:00+00:00'], false);
        // Arrives out of order: the later report still wins.
        $this->ingestor->materials(['id' => 9, 'polling_unit' => $this->unit('21202633001', 'Abakaliki', 'Ward A'), 'status' => 'arrived', 'agent' => $this->agent(), 'reported_at' => '2027-02-06T08:30:00+00:00'], false);
        $this->ingestor->materials(['id' => 8, 'polling_unit' => $this->unit('21202633001', 'Abakaliki', 'Ward A'), 'status' => 'not_arrived', 'agent' => $this->agent(), 'reported_at' => '2027-02-06T07:30:00+00:00'], false);
        $this->ingestor->materials(['id' => 10, 'polling_unit' => $this->unit('21202633002', 'Abakaliki', 'Ward A'), 'status' => 'incomplete', 'agent' => $this->agent(), 'reported_at' => '2027-02-06T08:00:00+00:00'], false);
        $this->ingestor->result($this->resultPayload(['polling_unit' => $this->unit('21202633001', 'Abakaliki', 'Ward A')]));
        $this->ingestor->incident(['reference' => 'IN1', 'polling_unit' => $this->unit('21202633003', 'Abakaliki', 'Ward A'), 'type' => 'violence', 'urgent' => true, 'agent' => $this->agent(), 'reported_at' => '2027-02-06T09:00:00+00:00'], false);

        $monitor = new PuMonitor(false);
        $units = $monitor->units('Abakaliki');

        $this->assertCount(3, $units);
        $this->assertSame('2027-02-06 07:05', $units['21202633001']->checkedInAt->format('Y-m-d H:i'));
        $this->assertSame('arrived', $units['21202633001']->materialsStatus());
        $this->assertSame('RS784321', $units['21202633001']->result->reference);
        $this->assertFalse($units['21202633001']->needsAttention());
        $this->assertTrue($units['21202633002']->needsAttention());
        $this->assertSame(1, $units['21202633003']->urgentIncidents);

        $lgas = $monitor->tally($monitor->units(), fn ($unit) => $unit->lga);
        $this->assertSame(['Abakaliki', 'Afikpo North'], array_keys($lgas));
        $this->assertSame(1, $lgas['Abakaliki']->checkedIn);
        $this->assertSame(['arrived' => 1, 'incomplete' => 1, 'not_arrived' => 0, 'none' => 1], $lgas['Abakaliki']->materials);
        $this->assertSame(1, $lgas['Abakaliki']->results);
        $this->assertSame(2, $lgas['Abakaliki']->needsAttention);
    }

    public function test_rehearsal_reports_are_left_out_of_the_real_board(): void
    {
        $this->ingestor->presence(['id' => 1, 'polling_unit' => ['code' => '21202633001'], 'agent' => $this->agent(), 'confirmed_at' => '2027-02-06T07:20:00+00:00'], true);

        $this->assertNull((new PuMonitor(false))->units()['21202633001']->checkedInAt);
        $this->assertNotNull((new PuMonitor(true))->units()['21202633001']->checkedInAt);
    }

    public function test_the_board_pages_render(): void
    {
        $this->actingAs(User::factory()->create());
        $this->ingestor->materials(['id' => 10, 'polling_unit' => $this->unit('21202633002', 'Abakaliki', 'Ward A'), 'status' => 'incomplete', 'agent' => $this->agent(), 'reported_at' => '2027-02-06T08:00:00+00:00'], false);

        $this->get('/monitor')->assertOk()->assertSee('Abakaliki')->assertSee('1 incomplete');
        $this->get('/monitor/Abakaliki')->assertOk()->assertSee('Ward A');
        $this->get('/monitor/Abakaliki/Ward A')->assertOk()->assertSee('Materials incomplete')->assertSee('No check-in');
        $this->get('/monitor/Abakaliki/Ward A?problems=1')->assertOk()->assertSee('Need attention');
        $this->get('/monitor/Nowhere')->assertNotFound();
    }
}
