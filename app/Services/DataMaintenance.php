<?php

namespace App\Services;

use App\Models\Ec8aPhoto;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\Presence;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Clearing rehearsal data and taking a full backup (the host has no
 * terminal, so both are console buttons).
 */
class DataMaintenance
{
    /**
     * Tables in the backup, with columns never exported (secrets and
     * anything that identifies a visitor).
     */
    private const BACKUP = [
        'polling_units' => [], 'agents' => [], 'results' => [], 'result_votes' => [],
        'incidents' => [], 'presences' => [], 'material_reports' => [],
        'official_results' => [], 'official_collations' => [], 'ec8a_photos' => [],
        'webhook_events' => [], 'sync_states' => [],
        'contacts' => [], 'opt_outs' => [], 'broadcasts' => [], 'broadcast_messages' => [],
        'town_hall_sessions' => [], 'town_hall_questions' => ['ip_hash'],
        'users' => ['password', 'remember_token'], 'audit_logs' => [],
    ];

    public function __construct(private Ec8aPhotoStore $photos) {}

    /**
     * Delete every rehearsal record the USSD service sent us (and their
     * EC8A photos). Real records are never touched.
     *
     * @return array<string, int>
     */
    public function clearRehearsal(): array
    {
        $references = Result::query()->where('rehearsal', true)->pluck('reference');
        $photos = Ec8aPhoto::query()->whereIn('result_reference', $references)->get();

        $counts = DB::transaction(fn () => [
            'results' => Result::query()->where('rehearsal', true)->delete(),
            'incidents' => Incident::query()->where('rehearsal', true)->delete(),
            'check-ins' => Presence::query()->where('rehearsal', true)->delete(),
            'materials reports' => MaterialReport::query()->where('rehearsal', true)->delete(),
        ]);

        foreach ($photos as $photo) {
            $this->photos->delete($photo);
        }

        return [...$counts, 'EC8A photos' => $photos->count()];
    }

    /**
     * Write the backup zip to a temporary file and return its path.
     */
    public function backup(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shield-backup-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup file.');
        }

        $summary = [];

        foreach (self::BACKUP as $table => $excluded) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_diff(Schema::getColumnListing($table), $excluded));
            $csv = fopen('php://temp', 'w+');
            fputcsv($csv, $columns, escape: '\\');
            $rows = 0;

            DB::table($table)->select($columns)->orderBy($columns[0])->chunk(1000, function ($chunk) use ($csv, $columns, &$rows) {
                foreach ($chunk as $row) {
                    fputcsv($csv, array_map(fn ($column) => $row->{$column}, $columns), escape: '\\');
                    $rows++;
                }
            });

            rewind($csv);
            $zip->addFromString("{$table}.csv", (string) stream_get_contents($csv));
            fclose($csv);
            $summary[] = str_pad($table, 22).number_format($rows);
        }

        $zip->addFromString('README.txt', implode("\n", [
            'Election Shield web app: full backup',
            'Taken '.now()->setTimezone(config('election.timezone'))->format('l j F Y, g:i A T').' from '.config('app.url'),
            '',
            'One CSV per table (UTF-8, first row = column names). Times are UTC.',
            'Not included: passwords, settings (they hold keys), push subscriptions,',
            'sessions, and the EC8A photo files themselves (see ec8a_photos.csv for',
            'their SHA-256; copy storage/app/private/ec8a/ with the File Manager).',
            '',
            'Rows per table:',
            ...$summary,
        ]));

        $zip->close();

        return $path;
    }
}
