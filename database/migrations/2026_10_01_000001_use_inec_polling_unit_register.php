<?php

use App\Services\PollingUnitImporter;
use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first bundled register was not INEC's: made-up PU and ward names, codes
 * like EB/212/02633/007 and voter numbers far above Ebonyi's real total. It
 * is replaced by INEC's list (codes like 11/01/01/007, stored as 110101007,
 * with INEC's approximate locations). Only a database still holding the old
 * codes is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('polling_units')) {
            return;
        }

        $old = DB::table('polling_units')->where('code', 'like', '2%')->whereRaw('length(code) = 11')->exists();
        if (! $old) {
            return;
        }

        $result = app(PollingUnitImporter::class)->import(PollingUnitImporter::bundledPath(), replace: true);

        Audit::record('system.pu_import', "Replaced the polling unit register with INEC's: {$result['created']} added, {$result['removed']} old PUs removed (agents assigned to them need a new PU)");
    }

    public function down(): void
    {
        // The old register was not real; there is nothing to go back to.
    }
};
