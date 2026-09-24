<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INEC's result for a PU as shown on IReV.
 */
class OfficialResult extends Model
{
    public const UPLOADED = 'uploaded';

    public const NOT_UPLOADED = 'not_uploaded';

    protected $fillable = [
        'polling_unit_code', 'lga', 'ward', 'irev_status', 'accredited_voters', 'votes',
        'rejected_votes', 'source', 'note', 'entered_by',
    ];

    protected function casts(): array
    {
        return ['votes' => 'array'];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }

    public function uploaded(): bool
    {
        return $this->irev_status === self::UPLOADED;
    }

    /**
     * @return array<string, int> in ballot order
     */
    public function votesByParty(): array
    {
        $votes = (array) $this->votes;

        return collect(config('election.parties'))->mapWithKeys(fn (string $party) => [$party => (int) ($votes[$party] ?? 0)])->all();
    }

    public function totalValidVotes(): int
    {
        return array_sum($this->votesByParty());
    }
}
