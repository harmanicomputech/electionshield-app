<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The USSD service is open to the public:
 * - incidents can come from anyone ("public"), with the caller's number and
 *   an LGA/ward (the polling unit is optional for them);
 * - "How can you help?" sign-ups arrive as volunteers (one per phone number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('polling_unit_code', 20)->nullable()->change();
            $table->string('source', 10)->default('agent')->index();
            $table->string('reporter_phone', 20)->nullable();
        });

        Schema::create('volunteers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('name', 80);
            $table->string('phone_number', 20)->index();
            $table->string('contact_phone', 20);
            $table->string('lga')->nullable()->index();
            $table->string('ward')->nullable();
            $table->json('roles');
            $table->json('skills')->nullable();
            $table->string('other', 160)->nullable();
            $table->boolean('is_agent')->default(false);
            $table->string('channel', 10)->default('ussd');
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('ussd_updated_at')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->string('contacted_by')->nullable();
            $table->string('follow_up_note', 500)->nullable();
            $table->boolean('rehearsal')->default(false);
            $table->timestamps();
        });

        // Coordinators can see volunteers (admins have everything anyway).
        if (Schema::hasTable('roles')) {
            $row = DB::table('roles')->where('key', 'coordinator')->first();
            if ($row) {
                $permissions = json_decode($row->permissions, true) ?: [];
                if (! in_array('view_volunteers', $permissions, true)) {
                    $permissions[] = 'view_volunteers';
                    DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode($permissions)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteers');

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'reporter_phone']);
        });
    }
};
