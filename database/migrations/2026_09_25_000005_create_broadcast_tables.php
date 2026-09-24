<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Supporters and other people we message. Agents come from the
        // USSD sync (agents table) and are not duplicated here.
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone', 20)->unique();
            $table->string('type', 20)->default('supporter')->index(); // supporter, coordinator, other
            $table->string('lga')->nullable()->index();
            $table->string('ward')->nullable();
            $table->timestamp('sms_opt_in_at')->nullable();
            $table->timestamp('whatsapp_opt_in_at')->nullable();
            $table->string('source', 40)->nullable(); // join form, import, manual
            $table->timestamps();
        });

        // STOP from anyone, supporter or agent, on any channel we can see.
        Schema::create('opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('channel', 20); // sms, whatsapp
            $table->string('source', 40)->nullable();
            $table->timestamp('created_at');

            $table->unique(['phone', 'channel']);
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('channel', 20); // sms, whatsapp
            $table->text('message')->nullable();
            $table->string('template')->nullable(); // WhatsApp template name
            $table->string('template_language', 10)->nullable();
            $table->json('audience');
            $table->string('status', 20)->default('draft')->index(); // draft, scheduled, sending, sent, cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('created_by')->nullable();
            $table->string('sent_by')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcast_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('name')->nullable();
            // queued, sent, delivered, failed, skipped
            $table->string('status', 20)->default('queued')->index();
            $table->string('provider_id')->nullable()->index();
            $table->string('cost', 30)->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Never the same number twice in one broadcast.
            $table->unique(['broadcast_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_messages');
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('opt_outs');
        Schema::dropIfExists('contacts');
    }
};
