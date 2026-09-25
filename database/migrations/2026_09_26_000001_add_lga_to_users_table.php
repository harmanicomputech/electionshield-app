<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A coordinator's home LGA: their alerts and default filters. Empty
     * means state-wide.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('lga', 100)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lga');
        });
    }
};
