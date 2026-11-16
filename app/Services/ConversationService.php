<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AI / human handling state of a conversation (Phase 2 §4).
 *
 * Once a conversation is handed to humans (agent reply, take-over, ticket, customer asking for a
 * person) the AI stops replying. It resumes only through an explicit, audited staff action.
 * The AI engine re-reads `handler` under a row lock right before it saves a reply.
 */
class ConversationService
{
    public static function markHuman(Conversation $conversation, ?User $by): void
    {
        if ($conversation->handler === 'human') {
            return;
        }
        $conversation->forceFill([
            'handler' => 'human',
            'handover_at' => now(),
            'handover_by' => $by?->id,
            'ai_pending_since' => null,
        ])->save();
    }

    /** Staff member explicitly takes over (also assigns the chat to them if unassigned). */
    public static function takeOver(Conversation $conversation, User $user): void
    {
        DB::transaction(function () use ($conversation, $user) {
            $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->first();
            $locked->forceFill([
                'handler' => 'human',
                'handover_at' => now(),
                'handover_by' => $user->id,
                'ai_pending_since' => null,
                'assigned_agent_id' => $locked->assigned_agent_id ?: $user->id,
                'status' => $locked->status === 'unassigned' ? 'open' : $locked->status,
            ])->save();
        });
        $conversation->refresh();

        $displayName = \App\Http\Controllers\Api\AgentChatController::formatAgentDisplayName($user, $conversation->property);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'sender_id' => $user->id,
            'message_type' => 'text',
            'is_automated' => true,
            'content' => "🟢 {$displayName} has joined the chat.",
            'is_read' => true,
        ]);
        Audit::record('conversation.taken_over', $conversation, [], $user, $conversation->property?->tenant_id);
    }

    /** Explicit, authorised re-activation of automatic AI replies. */
    public static function resumeAi(Conversation $conversation, User $user): void
    {
        $conversation->forceFill([
            'handler' => 'ai',
            'ai_resumed_at' => now(),
            'ai_resumed_by' => $user->id,
            'clarifications_asked' => 0,
        ])->save();
        Audit::record('conversation.ai_resumed', $conversation, [], $user, $conversation->property?->tenant_id);
        if ($ticket = $conversation->openTicket) {
            TicketService::event($ticket, 'ai_resumed', [], $user);
        }
    }

    /** First human reply bookkeeping (sales SLA ignores automated greetings). */
    public static function recordHumanReply(Conversation $conversation, User $user): void
    {
        $updates = ['last_message_at' => now()];
        if (! $conversation->first_human_response_at) {
            $updates['first_human_response_at'] = now();
        }
        if (! $conversation->assigned_agent_id) {
            $updates['assigned_agent_id'] = $user->id;
        }
        if ($conversation->status === 'unassigned') {
            $updates['status'] = 'open';
        }
        $conversation->forceFill($updates)->save();
        self::markHuman($conversation, $user);
        Notifier::cancelSalesAlert($conversation);
    }

    /**
     * Customer asked for a person. If someone is online within working hours the chat is handed
     * over live; otherwise a ticket is created (idempotent). Returns what actually happened.
     *
     * @return array{mode: string, ticket: ?\App\Models\Ticket, created: bool}
     */
    public static function requestHuman(Conversation $conversation, ?string $idempotencyKey, string $reason = 'customer_request'): array
    {
        $conversation->loadMissing('property');
        self::markHuman($conversation, null);

        $online = BusinessHours::isOpen($conversation->property)
            && User::where('tenant_id', $conversation->property->tenant_id)->where('is_online', true)
                ->where('last_active_at', '>=', now()->subMinutes(15))->exists();

        if ($online && ! $conversation->openTicket) {
            Notifier::humanRequested($conversation);

            return ['mode' => 'live', 'ticket' => null, 'created' => false];
        }

        [$ticket, $created] = TicketService::createOrReuse($conversation, [
            'source' => $reason,
            'idempotency_key' => $idempotencyKey,
        ]);
        if (! $created) {
            Notifier::humanRequested($conversation);
        }

        return ['mode' => 'ticket', 'ticket' => $ticket, 'created' => $created];
    }
}
