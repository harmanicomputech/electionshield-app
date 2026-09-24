<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A collation INEC declared: ward (EC8B) or LGA (EC8C).
 */
class OfficialCollation extends Model
{
    public const WARD = 'ward';

    public const LGA = 'lga';

    protected $fillable = ['level', 'lga', 'ward', 'accredited_voters', 'votes', 'rejected_votes', 'source', 'note', 'entered_by'];

    protected function casts(): array
    {
        return ['votes' => 'array'];
    }

    public function form(): string
    {
        return $this->level === self::WARD ? 'EC8B' : 'EC8C';
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
