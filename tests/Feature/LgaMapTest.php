<?php

namespace Tests\Feature;

use App\Models\PollingUnit;
use App\Models\User;
use App\Services\LgaMap;
use App\Services\PollingUnitImporter;
use App\Services\UssdIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsUssdEvents;
use Tests\TestCase;

class LgaMapTest extends TestCase
{
    use RefreshDatabase;
    use SendsUssdEvents;

    public function test_every_lga_in_the_register_has_its_own_tile(): void
    {
        app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());
        $lgas = PollingUnit::query()->distinct()->orderBy('lga')->pluck('lga')->all();

        $layout = LgaMap::LAYOUT;
        ksort($layout);
        $this->assertSame($lgas, array_keys($layout));

        $cells = array_map(fn ($p) => implode(',', $p), LgaMap::LAYOUT);
        $this->assertSame(count($cells), count(array_unique($cells)), 'Two LGAs share a tile position.');
    }

    public function test_layers_colour_by_the_rules_and_always_show_the_value(): void
    {
        config(['election.principal_party' => 'APC']);
        $ingestor = app(UssdIngestor::class);
        // Abakaliki: APC 610 of 1125 = 54.2% (good); Ivo: APC 20 of 100 = 20% (bad).
        $ingestor->result($this->resultPayload(['reference' => 'RS1', 'polling_unit' => $this->unit('21202633001', 'Abakaliki', 'Ward A')]));
        $ingestor->pollingUnit($this->unit('21202633002', 'Abakaliki', 'Ward A'));
        $ingestor->result($this->resultPayload(['reference' => 'RS2', 'polling_unit' => $this->unit('21902633001', 'Ivo', 'Ward I'), 'votes' => ['APC' => 20, 'PDP' => 70, 'LP' => 10, 'OTHERS' => 0]]));
        foreach (['IN1', 'IN2', 'IN3'] as $ref) {
            $ingestor->incident(['reference' => $ref, 'polling_unit' => ['code' => '21902633001'], 'type' => 'delay', 'urgent' => false, 'agent' => [], 'reported_at' => now()->toIso8601String()], false);
        }

        $map = (new LgaMap(false))->build(['share', 'results', 'incidents'], fn ($lga) => "/x/{$lga}");

        $this->assertSame(['class' => 'good', 'value' => '54.2%'], array_intersect_key($map['tiles']['Abakaliki']['layers']['share'], array_flip(['class', 'value'])));
        $this->assertSame('bad', $map['tiles']['Ivo']['layers']['share']['class']);
        $this->assertSame('none', $map['tiles']['Izzi']['layers']['share']['class']);
        $this->assertSame(['seq3', '50%'], [$map['tiles']['Abakaliki']['layers']['results']['class'], $map['tiles']['Abakaliki']['layers']['results']['value']]);
        $this->assertSame(['bad', '3'], [$map['tiles']['Ivo']['layers']['incidents']['class'], $map['tiles']['Ivo']['layers']['incidents']['value']]);
        $this->assertSame('/x/Ivo', $map['tiles']['Ivo']['link']);
        $this->assertSame('APC share', $map['layers']['share']['label']);
    }

    public function test_the_map_appears_on_the_dashboard_pus_and_incidents(): void
    {
        $this->actingAs(User::factory()->create());
        app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath());

        $this->get('/')->assertOk()->assertSee('data-lga-map', false)->assertSee('Afikpo South');
        $this->get('/?map=results')->assertOk()->assertSee('data-active="results"', false);
        $this->get('/monitor')->assertOk()->assertSee('data-lga-map', false);
        $this->get('/incidents')->assertOk()->assertSee('data-lga-map', false)->assertSee('/incidents?lga=Ikwo', false);
    }
}
