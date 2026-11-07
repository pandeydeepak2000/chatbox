<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 4: dashboard notifications, Telegram channels + delivery log, follow-up tasks and reminders.
 * (Reading a notification never resolves a task; completing a task never resolves a ticket.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 20)->default('telegram');
            $table->string('name', 100);
            $table->text('bot_token'); // encrypted cast
            $table->string('chat_id', 64);
            $table->json('events');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'is_enabled']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('channel_id')->nullable()->constrained('notification_channels')->nullOnDelete();
            $table->string('event', 40);
            $table->string('status', 10)->default('pending'); // pending | sent | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('message');
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
            $table->string('type', 40); // quotation_followup | sales_first_response | ticket_followup | manual
            $table->string('title', 200);
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets')->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending'); // pending | done | cancelled
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['owner_id', 'status']);
        });

        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamp('remind_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->index(['remind_at', 'sent_at', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('staff_notifications');
    }
};
