<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polling_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name')->nullable();
            $table->string('ward')->nullable()->index();
            $table->string('lga')->nullable()->index();
            $table->unsignedInteger('registered_voters')->nullable();
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ussd_id')->unique();
            $table->string('name');
            $table->string('phone_number', 20);
            $table->string('polling_unit_code', 20)->nullable()->index();
            $table->boolean('locked')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
        Schema::dropIfExists('polling_units');
    }
};
