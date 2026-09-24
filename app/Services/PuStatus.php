<?php

namespace App\Services;

use App\Models\MaterialReport;
use App\Models\PollingUnit;
use App\Models\Result;
use Illuminate\Support\Carbon;

/**
 * One polling unit's election-day status.
 */
class PuStatus
{
    public ?PollingUnit $unit = null;

    public ?Carbon $checkedInAt = null;

    public ?MaterialReport $materials = null;

    public ?Result $result = null;

    public int $openIncidents = 0;

    public int $urgentIncidents = 0;

    public function __construct(public string $code, public string $lga, public string $ward) {}

    public function name(): string
    {
        return $this->unit?->name ?: 'PU '.$this->code;
    }

    /**
     * arrived, incomplete, not_arrived, or null with no report.
     */
    public function materialsStatus(): ?string
    {
        return $this->materials?->status;
    }

    /**
     * Something a coordinator should look at: no agent checked in,
     * materials missing or incomplete, or an urgent incident still open.
     */
    public function needsAttention(): bool
    {
        return $this->checkedInAt === null
            || in_array($this->materialsStatus(), ['incomplete', 'not_arrived'], true)
            || $this->urgentIncidents > 0;
    }
}
