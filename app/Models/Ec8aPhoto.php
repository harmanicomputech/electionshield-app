<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ec8aPhoto extends Model
{
    public const UNCHECKED = 'unchecked';

    public const MATCHES = 'matches';

    public const MISMATCH = 'mismatch';

    protected $fillable = [
        'result_reference', 'path', 'thumbnail_path', 'mime_type', 'size', 'width', 'height',
        'sha256', 'uploaded_via', 'uploaded_by', 'note',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'result_reference', 'reference');
    }

    public function reviewLabel(): string
    {
        return match ($this->review_status) {
            self::MATCHES => '✓ Matches our figures',
            self::MISMATCH => '✗ Does not match',
            default => 'Not checked yet',
        };
    }
}
