<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3: tickets, AI/human handling state, internal notes, idempotent messages.
 * Additive; existing conversations get a handler value derived from current assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('ticket_prefix', 8)->default('TKT')->after('timezone');
            $table->unsignedInteger('ticket_counter')->default(0)->after('ticket_prefix');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('handler', 10)->default('ai')->after('status'); // ai | human
            $table->timestamp('handover_at')->nullable();
            $table->uuid('handover_by')->nullable();
            $table->timestamp('ai_resumed_at')->nullable();
            $table->uuid('ai_resumed_by')->nullable();
            $table->string('language', 10)->nullable();
            $table->unsignedTinyInteger('clarifications_asked')->default(0);
            $table->timestamp('first_human_response_at')->nullable();
            $table->timestamp('ai_pending_since')->nullable()->index();
            $table->timestamp('sales_alert_sent_at')->nullable();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('visibility', 10)->default('public')->after('message_type'); // public | internal
            $table->boolean('is_automated')->default(false)->after('visibility');
            $table->string('client_message_id', 64)->nullable()->after('is_automated');
            $table->uuid('ticket_id')->nullable()->after('conversation_id')->index();
            $table->unique(['conversation_id', 'client_message_id']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('property_id')->constrained('properties')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('reference', 24);
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            // equals conversation_id while unresolved, NULL afterwards: DB-level "one open ticket per conversation"
            $table->uuid('open_conversation_id')->nullable()->unique();
            $table->foreignUuid('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->foreignUuid('visitor_id')->nullable()->constrained('visitors')->nullOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open'); // open, pending_team, pending_customer, resolved, closed
            $table->string('priority', 10)->default('normal'); // low, normal, high, urgent
            $table->string('category', 30)->default('general');
            $table->string('subject', 200);
            $table->json('summary')->nullable(); // issue, affected_service, steps_tried, results, unresolved
            $table->string('source', 20)->default('staff'); // ai_escalation, customer_request, staff
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('last_customer_reply_at')->nullable();
            $table->timestamp('last_staff_reply_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['property_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status']);
            $table->index(['property_id', 'status']);
            $table->index('assigned_to');
        });

        Schema::create('ticket_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->index(['ticket_id', 'unassigned_at']);
            $table->index('user_id');
        });

        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('actor_type', 20); // user | customer | system | ai
            $table->uuid('actor_id')->nullable();
            $table->string('type', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'created_at']);
        });

        // Existing conversations that already have an agent are human-handled (today's behaviour).
        DB::table('conversations')->whereNotNull('assigned_agent_id')->update(['handler' => 'human']);
        foreach (DB::table('tenants')->get(['id', 'name']) as $t) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $t->name), 0, 3)) ?: 'TKT';
            DB::table('tenants')->where('id', $t->id)->update(['ticket_prefix' => strlen($prefix) >= 2 ? $prefix : 'TKT']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_assignments');
        Schema::dropIfExists('tickets');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'client_message_id']);
            $table->dropIndex(['ticket_id']);
            $table->dropColumn(['visibility', 'is_automated', 'client_message_id', 'ticket_id']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['ai_pending_since']);
            $table->dropColumn(['handler', 'handover_at', 'handover_by', 'ai_resumed_at', 'ai_resumed_by', 'language',
                'clarifications_asked', 'first_human_response_at', 'ai_pending_since', 'sales_alert_sent_at']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['ticket_prefix', 'ticket_counter']);
        });
    }
};
