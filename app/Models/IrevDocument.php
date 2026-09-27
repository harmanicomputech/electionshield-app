<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One IReV polling unit of the followed election and its EC8A upload.
 */
class IrevDocument extends Model
{
    public const WAITING = 'waiting';       // no sheet on IReV yet

    public const NEW = 'new';               // a sheet to fetch and read

    public const SAVED = 'saved';           // read and saved as the official result

    public const KEPT = 'kept';             // a person had already entered this PU: left alone

    public const UNREADABLE = 'unreadable'; // the AI couldn't read it: enter by hand

    public const FETCHED = 'fetched';       // downloaded, waiting for AI (no API key)

    public const BLOCKED = 'blocked';       // IReV refused the download

    public const FAILED = 'failed';         // other error; retried a few times

    public const UNMATCHED = 'unmatched';   // not found in our register: match by hand

    public const LABELS = [
        self::WAITING => 'No sheet on IReV yet',
        self::NEW => 'New sheet, being fetched',
        self::SAVED => 'Read and saved',
        self::KEPT => 'Already entered by a person',
        self::UNREADABLE => 'Could not be read: enter by hand',
        self::FETCHED => 'Downloaded: enter by hand (AI off)',
        self::BLOCKED => 'IReV refused the download',
        self::FAILED => 'Error: will retry',
        self::UNMATCHED => 'Not matched to our register',
    ];

    protected $fillable = [
        'irev_election_id', 'irev_pu_code', 'pu_name', 'lga_name', 'ward_name', 'polling_unit_code', 'matched_by_hand',
        'document_url', 'document_updated_at', 'status', 'sheet_path', 'sheet_sha256', 'attempts', 'last_error', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['document_updated_at' => 'datetime', 'processed_at' => 'datetime', 'matched_by_hand' => 'boolean'];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class, 'polling_unit_code', 'code');
    }
}
