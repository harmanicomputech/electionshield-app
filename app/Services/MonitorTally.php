<?php

namespace App\Services;

/**
 * Election-day status counts for an area (LGA, ward or the state).
 */
class MonitorTally
{
    public int $units = 0;

    public int $checkedIn = 0;

    /** @var array<string, int> arrived, incomplete, not_arrived, none */
    public array $materials = ['arrived' => 0, 'incomplete' => 0, 'not_arrived' => 0, 'none' => 0];

    public int $results = 0;

    public int $openIncidents = 0;

    public int $urgentIncidents = 0;

    public int $needsAttention = 0;

    public function __construct(public string $name) {}

    public function add(PuStatus $unit): void
    {
        $this->units++;
        $this->checkedIn += $unit->checkedInAt ? 1 : 0;
        $this->materials[$unit->materialsStatus() ?? 'none'] = ($this->materials[$unit->materialsStatus() ?? 'none'] ?? 0) + 1;
        $this->results += $unit->result ? 1 : 0;
        $this->openIncidents += $unit->openIncidents;
        $this->urgentIncidents += $unit->urgentIncidents;
        $this->needsAttention += $unit->needsAttention() ? 1 : 0;
    }

    public function percent(int $count): float
    {
        return $this->units > 0 ? round(100 * $count / $this->units, 1) : 0.0;
    }
}
