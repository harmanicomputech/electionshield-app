<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordinators acknowledge and resolve incidents here; the USSD service
     * never sends these fields, so syncing never overwrites them.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('rehearsal');
            $table->string('acknowledged_by')->nullable()->after('acknowledged_at');
            $table->timestamp('resolved_at')->nullable()->after('acknowledged_by');
            $table->string('resolved_by')->nullable()->after('resolved_at');
            $table->text('resolution_note')->nullable()->after('resolved_by');

            $table->index(['rehearsal', 'resolved_at', 'urgent']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['rehearsal', 'resolved_at', 'urgent']);
            $table->dropColumn(['acknowledged_at', 'acknowledged_by', 'resolved_at', 'resolved_by', 'resolution_note']);
        });
    }
};
