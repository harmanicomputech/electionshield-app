<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * INEC's figures, entered by hand or imported: the IReV result per PU
     * and the declared ward (EC8B) and LGA (EC8C) collations. Compared with
     * our PVT figures; never mixed into them.
     */
    public function up(): void
    {
        Schema::create('official_results', function (Blueprint $table) {
            $table->id();
            $table->string('polling_unit_code', 20)->unique();
            $table->string('lga')->nullable()->index();
            $table->string('ward')->nullable();
            // uploaded, or not_uploaded when IReV shows no result sheet.
            $table->string('irev_status', 20)->default('uploaded');
            $table->unsignedInteger('accredited_voters')->nullable();
            $table->json('votes')->nullable();
            $table->unsignedInteger('rejected_votes')->nullable();
            $table->string('source', 20)->default('manual');
            $table->text('note')->nullable();
            $table->string('entered_by')->nullable();
            $table->timestamps();
        });

        Schema::create('official_collations', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10); // ward (EC8B) or lga (EC8C)
            $table->string('lga');
            $table->string('ward')->default('');
            $table->unsignedInteger('accredited_voters')->nullable();
            $table->json('votes');
            $table->unsignedInteger('rejected_votes')->nullable();
            $table->string('source', 20)->default('manual');
            $table->text('note')->nullable();
            $table->string('entered_by')->nullable();
            $table->timestamps();

            $table->unique(['level', 'lga', 'ward']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_collations');
        Schema::dropIfExists('official_results');
    }
};
