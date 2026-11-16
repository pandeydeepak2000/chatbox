<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: adds the customer-support state machine columns and its audit trail.
 * Existing `handler` / `status` columns keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('support_state', 32)->default('AUTO_HANDLING')->after('handler')->index();
            $table->timestamp('support_state_changed_at')->nullable()->after('support_state');
            $table->timestamp('escalation_requested_at')->nullable()->after('support_state_changed_at');
        });

        Schema::create('conversation_state_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('from_state', 32);
            $table->string('to_state', 32);
            $table->string('actor_type', 16)->default('system'); // system | customer | agent
            $table->foreignUuid('actor_id')->nullable();
            $table->string('reason', 80)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });

        // Backfill: chats already handled by staff are AGENT_ACTIVE, closed chats CLOSED.
        \Illuminate\Support\Facades\DB::table('conversations')->where('handler', 'human')->update(['support_state' => 'AGENT_ACTIVE']);
        \Illuminate\Support\Facades\DB::table('conversations')->where('status', 'closed')->update(['support_state' => 'CLOSED']);
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_state_logs');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['support_state', 'support_state_changed_at', 'escalation_requested_at']);
        });
    }
};
