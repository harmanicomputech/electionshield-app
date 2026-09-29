<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person choice of whether the app records their location: null follows
 * their role ("Location is recorded"), "always" or "never" overrides it
 * (admins are only tracked with "always").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('track_location', 10)->nullable()->after('lga');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('track_location');
        });
    }
};
