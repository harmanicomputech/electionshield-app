<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A photo or video attached to an incident or a result (by its reference).
 * EC8A sheet photos for results are Ec8aPhoto records instead, because they
 * have their own review against the figures.
 */
class Attachment extends Model
{
    public const IMAGE = 'image';

    public const VIDEO = 'video';

    protected $fillable = [
        'reference', 'kind', 'path', 'mime', 'size', 'sha256', 'original_name',
        'uploaded_by', 'user_id', 'review_status', 'reviewed_by', 'reviewed_at', 'note',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'size' => 'integer'];
    }

    public function isVideo(): bool
    {
        return $this->kind === self::VIDEO;
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? round($this->size / 1048576, 1).' MB' : max(1, round($this->size / 1024)).' KB';
    }
}
