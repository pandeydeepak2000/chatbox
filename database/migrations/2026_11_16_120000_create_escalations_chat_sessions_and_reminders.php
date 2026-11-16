<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Real 60-second senior-support window, durable chat-start records, idempotent Telegram events and
 * per-period ticket reminders. Everything is database backed (cPanel friendly: no Redis, no workers beyond cron).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A live chat the moment the visitor opens it (before e-mail / PIN / first question).
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('property_id')->index();
            $table->uuid('visitor_id')->index();
            $table->uuid('department_id')->nullable();
            $table->uuid('customer_account_id')->nullable();
            $table->uuid('conversation_id')->nullable()->index();
            $table->string('reference', 24)->unique();
            $table->dateTime('started_at');
            $table->timestamps();
            $table->index(['visitor_id', 'property_id', 'started_at']);
        });

        // One senior-support escalation: server-side escalated_at and an authoritative deadline_at (+60 s).
        Schema::create('escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('property_id')->index();
            $table->uuid('conversation_id')->index();
            $table->string('reason', 40);
            $table->json('ticket_options')->nullable();                 // category / summary / source for the timeout ticket
            $table->dateTime('escalated_at');
            $table->dateTime('deadline_at');
            $table->string('status', 12)->default('waiting');           // waiting | joined | ticketed | cancelled
            $table->uuid('open_key')->nullable()->unique();             // = conversation_id while waiting, NULL afterwards: one open escalation per chat
            $table->uuid('joined_by')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->uuid('ticket_id')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('finalized_via', 24)->nullable();            // agent | widget | cron | agent_late | closed
            $table->timestamps();
            $table->index(['status', 'deadline_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('chat_reference', 24)->nullable()->after('support_state_changed_at')->index();
        });

        // Durable idempotency for Telegram events: the same event is never queued twice for a channel.
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->string('dedupe_key', 120)->nullable()->after('event');
            $table->unique(['channel_id', 'dedupe_key']);
        });

        // Pending period of a ticket (waiting for the team) and its reminder history.
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('team_pending_since')->nullable()->after('last_reminded_at');
            $table->unsignedInteger('reminder_cycle')->default(0)->after('team_pending_since');
        });
        Schema::create('ticket_reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('ticket_id')->index();
            $table->unsignedInteger('cycle');
            $table->unsignedInteger('sequence');                        // 1 = after 30 min, 2 = after 60 min, ...
            $table->dateTime('queued_at');
            $table->unique(['ticket_id', 'cycle', 'sequence']);
        });

        // Backfill: tickets already waiting for the team start their pending period at creation.
        DB::table('tickets')->whereIn('status', ['open', 'pending_team'])->whereNull('team_pending_since')
            ->update(['team_pending_since' => DB::raw('created_at'), 'reminder_cycle' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reminders');
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn(['team_pending_since', 'reminder_cycle']));
        // MySQL/MariaDB may have used the composite unique index for the channel_id foreign key: give the FK its own
        // index first, otherwise dropping the unique index fails (error 1553).
        Schema::table('notification_deliveries', fn (Blueprint $t) => $t->index('channel_id', 'notification_deliveries_channel_id_idx'));
        Schema::table('notification_deliveries', function (Blueprint $t) {
            $t->dropUnique(['channel_id', 'dedupe_key']);
            $t->dropColumn('dedupe_key');
        });
        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('chat_reference'));
        Schema::dropIfExists('escalations');
        Schema::dropIfExists('chat_sessions');
    }
};
