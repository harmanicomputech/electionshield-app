<?php

namespace App\Models;

use App\Enums\ResultStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    protected $fillable = [
        'reference', 'status', 'polling_unit_code', 'lga', 'ward',
        'accredited_voters', 'total_valid_votes', 'rejected_votes', 'total_votes_cast',
        'corrects_reference', 'agent_name', 'agent_phone',
        'submitted_at', 'reviewed_at', 'reviewed_by', 'review_note', 'rehearsal',
    ];

    protected function casts(): array
    {
        return [
            'status' => ResultStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'rehearsal' => 'boolean',
        ];
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ResultVote::class);
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }

    /**
     * The results that count: accepted, and real or rehearsal depending on
     * what the console is set to show.
     */
    public function scopeCounted(Builder $query, bool $rehearsal): void
    {
        $query->where('status', ResultStatus::Accepted)->where('rehearsal', $rehearsal);
    }

    /**
     * @return array<string, int>
     */
    public function votesByParty(): array
    {
        $votes = $this->votes->pluck('votes', 'party')->all();

        return collect(config('election.parties'))
            ->mapWithKeys(fn (string $party) => [$party => (int) ($votes[$party] ?? 0)])
            ->union($votes)
            ->all();
    }
}
