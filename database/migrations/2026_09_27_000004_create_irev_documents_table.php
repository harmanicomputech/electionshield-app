<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the IReV watcher has seen: one row per IReV polling unit of the
 * followed election, with its EC8A upload (IReV publishes images, not
 * figures) and how far it got (downloaded, read, saved). Official results
 * filled in automatically are marked as needing a person's check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irev_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('irev_election_id');
            $table->string('irev_pu_code', 30);
            $table->string('pu_name')->nullable();
            $table->string('lga_name')->nullable();
            $table->string('ward_name')->nullable();
            $table->string('polling_unit_code', 20)->nullable()->index();
            $table->boolean('matched_by_hand')->default(false);
            $table->string('document_url', 1000)->nullable();
            $table->timestamp('document_updated_at')->nullable();
            $table->string('status', 20)->default('waiting')->index();
            $table->string('sheet_path')->nullable();
            $table->char('sheet_sha256', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['irev_election_id', 'irev_pu_code']);
        });

        Schema::table('official_results', function (Blueprint $table) {
            $table->boolean('needs_check')->default(false);
            $table->string('irev_document_url', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('official_results', function (Blueprint $table) {
            $table->dropColumn(['needs_check', 'irev_document_url']);
        });

        Schema::dropIfExists('irev_documents');
    }
};
