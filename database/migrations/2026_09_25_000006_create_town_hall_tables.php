<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The digital town hall: public live sessions where voters send
     * questions, moderated before anything is shown.
     */
    public function up(): void
    {
        Schema::create('town_hall_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('host')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('stream_url', 500)->nullable();
            $table->string('recording_url', 500)->nullable();
            $table->boolean('questions_open')->default(true);
            $table->boolean('published')->default(true);
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('town_hall_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('town_hall_session_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->nullable();
            $table->string('lga', 100)->nullable();
            $table->text('body');
            // pending, approved, rejected, answered
            $table->string('status', 20)->default('pending');
            $table->timestamp('on_air_at')->nullable();
            $table->string('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            // A keyed hash, for spotting floods; the IP itself is not kept.
            $table->char('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['town_hall_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('town_hall_questions');
        Schema::dropIfExists('town_hall_sessions');
    }
};
