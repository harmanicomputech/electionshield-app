<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where agents were when they checked in from the web app (GPS, required),
 * each PU's location to measure it against, and where staff and agents
 * were when they used the app (roles with "Location is recorded").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presences', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('location_accuracy', 8, 1)->nullable();
            $table->timestamp('located_at')->nullable();
            $table->timestamp('location_reviewed_at')->nullable();
            $table->string('location_reviewed_by')->nullable();
        });

        Schema::table('polling_units', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_source', 100)->nullable();
        });

        Schema::create('user_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12); // ok, denied, unavailable
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 1)->nullable();
            $table->string('action', 150);
            $table->timestamp('located_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Existing installs: coordinators and agents start sharing location.
        foreach (['coordinator', 'agent'] as $key) {
            $row = DB::table('roles')->where('key', $key)->first();
            if ($row) {
                $permissions = json_decode($row->permissions, true) ?: [];
                if (! in_array('share_location', $permissions, true)) {
                    $permissions[] = 'share_location';
                    DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode($permissions)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_locations');

        Schema::table('presences', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_accuracy', 'located_at', 'location_reviewed_at', 'location_reviewed_by']);
        });

        Schema::table('polling_units', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'location_source']);
        });
    }
};
