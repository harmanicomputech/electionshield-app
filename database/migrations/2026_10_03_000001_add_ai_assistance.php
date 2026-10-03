<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI assistance for election day: incident triage (on the incident), result
 * checks (one row per result: the first rule check and the EC8A photo
 * reading) and situation briefs. The AI only suggests; people decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('duplicate_of', 20)->nullable();
            $table->string('ai_priority', 10)->nullable();
            $table->string('ai_summary', 300)->nullable();
            $table->string('ai_action', 300)->nullable();
            $table->string('ai_credibility', 10)->nullable();
            $table->string('ai_reason', 300)->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->unsignedTinyInteger('triage_attempts')->default(0);
            $table->string('triage_error', 300)->nullable();
            $table->index(['triaged_at', 'reported_at']);
        });

        Schema::create('result_checks', function (Blueprint $table) {
            $table->id();
            $table->string('result_reference', 20)->unique();
            $table->string('level', 10)->nullable();
            $table->json('flags')->nullable();
            $table->unsignedBigInteger('photo_id')->nullable();
            $table->string('photo_status', 12)->nullable();
            $table->json('photo_reading')->nullable();
            $table->json('photo_differences')->nullable();
            $table->string('photo_error', 300)->nullable();
            $table->unsignedTinyInteger('photo_attempts')->default(0);
            $table->timestamp('photo_checked_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('situation_briefs', function (Blueprint $table) {
            $table->id();
            $table->boolean('rehearsal')->default(false);
            $table->string('headline', 300);
            $table->json('points');
            $table->json('actions');
            $table->json('facts');
            $table->string('written_by', 100)->nullable();
            $table->timestamps();
            $table->index(['rehearsal', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('situation_briefs');
        Schema::dropIfExists('result_checks');
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['triaged_at', 'reported_at']);
            $table->dropColumn(['duplicate_of', 'ai_priority', 'ai_summary', 'ai_action', 'ai_credibility', 'ai_reason', 'triaged_at', 'triage_attempts', 'triage_error']);
        });
    }
};
