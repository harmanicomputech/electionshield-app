<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos of the EC8A result sheet, tied to the USSD result reference.
     * The file is kept exactly as received, with its SHA-256, as evidence.
     */
    public function up(): void
    {
        Schema::create('ec8a_photos', function (Blueprint $table) {
            $table->id();
            $table->string('result_reference', 20)->index();
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type', 50);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->char('sha256', 64);
            // coordinator (logged in) or agent (upload link)
            $table->string('uploaded_via', 20);
            $table->string('uploaded_by')->nullable();
            $table->text('note')->nullable();
            // unchecked, matches, mismatch
            $table->string('review_status', 20)->default('unchecked')->index();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            // The same file sent twice (a queued upload replayed) is stored once.
            $table->unique(['result_reference', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec8a_photos');
    }
};
