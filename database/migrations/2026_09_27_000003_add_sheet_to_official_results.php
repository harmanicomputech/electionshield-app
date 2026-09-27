<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IReV result sheet an official result was read from (kept as evidence,
 * with its SHA-256).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('official_results', function (Blueprint $table) {
            $table->string('sheet_path')->nullable();
            $table->char('sheet_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('official_results', function (Blueprint $table) {
            $table->dropColumn(['sheet_path', 'sheet_sha256']);
        });
    }
};
