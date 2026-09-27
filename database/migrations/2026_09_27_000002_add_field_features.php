<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents' web submissions and the situation-room pop-ups:
 * - channel ("ussd" or "web") on every kind of report;
 * - results can be acknowledged, like incidents;
 * - photos and videos attached to incidents and results;
 * - per-person "remind me later" for pop-ups.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['results', 'incidents', 'presences', 'material_reports'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('channel', 10)->default('ussd');
            });
        }

        Schema::table('results', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by')->nullable();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->index();
            $table->string('kind', 10);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('original_name')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('review_status', 20)->default('unreviewed');
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['reference', 'sha256']);
        });

        Schema::create('alert_snoozes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);
            $table->timestamp('until');
            $table->timestamps();

            $table->unique(['user_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_snoozes');
        Schema::dropIfExists('attachments');

        Schema::table('results', function (Blueprint $table) {
            $table->dropColumn(['acknowledged_at', 'acknowledged_by']);
        });

        foreach (['results', 'incidents', 'presences', 'material_reports'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('channel');
            });
        }
    }
};
