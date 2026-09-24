<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('polling_unit_code', 20)->index();
            $table->string('lga')->nullable()->index();
            $table->string('ward')->nullable();
            $table->string('type', 30);
            $table->string('type_label')->nullable();
            $table->boolean('urgent')->default(false);
            $table->text('note')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('agent_phone', 20)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->boolean('rehearsal')->default(false);
            $table->timestamps();
        });

        // Agents can report materials again; the latest report is the PU's status.
        Schema::create('material_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ussd_id')->unique();
            $table->string('polling_unit_code', 20)->index();
            $table->string('lga')->nullable();
            $table->string('ward')->nullable();
            $table->string('status', 20);
            $table->string('status_label')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('agent_phone', 20)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->boolean('rehearsal')->default(false);
            $table->timestamps();
        });

        Schema::create('presences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ussd_id')->unique();
            $table->string('polling_unit_code', 20)->index();
            $table->string('lga')->nullable();
            $table->string('ward')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('agent_phone', 20)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->boolean('rehearsal')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presences');
        Schema::dropIfExists('material_reports');
        Schema::dropIfExists('incidents');
    }
};
