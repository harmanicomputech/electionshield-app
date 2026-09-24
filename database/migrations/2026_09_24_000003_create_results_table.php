<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A copy of the USSD service's EC8A results. The USSD service is the
        // source of truth; lga and ward are copied from the payload so
        // collation works before the PU register has been imported.
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('status', 20);
            $table->string('polling_unit_code', 20)->index();
            $table->string('lga')->nullable();
            $table->string('ward')->nullable();
            $table->unsignedInteger('accredited_voters')->default(0);
            $table->unsignedInteger('total_valid_votes')->default(0);
            $table->unsignedInteger('rejected_votes')->default(0);
            $table->unsignedInteger('total_votes_cast')->default(0);
            $table->string('corrects_reference', 20)->nullable()->index();
            $table->string('agent_name')->nullable();
            $table->string('agent_phone', 20)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->boolean('rehearsal')->default(false);
            $table->timestamps();

            $table->index(['status', 'rehearsal', 'lga']);
        });

        Schema::create('result_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->constrained()->cascadeOnDelete();
            $table->string('party', 20);
            $table->unsignedInteger('votes');

            $table->unique(['result_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_votes');
        Schema::dropIfExists('results');
    }
};
