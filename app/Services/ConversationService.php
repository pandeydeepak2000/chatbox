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
        if ($conversation->handler !== 'human') {
            $conversation->forceFill([
                'handler' => 'human',
                'handover_at' => now(),
                'handover_by' => $by?->id,
                'ai_pending_since' => null,
            ])->save();
        }
        if ($by) {
            ConversationStateMachine::moveTo($conversation, ConversationStateMachine::AGENT_ACTIVE, $by, 'staff_action');
        } else {
            self::queueForTeam($conversation, 'escalation');
        }
    }

    /**
     * A request needs the team: ESCALATION_REQUESTED, then QUEUED_FOR_TEAM (the ticket/alert exist).
     * Chats a person already works on (AGENT_*) or that wait for the customer are not pushed back.
     */
    public static function queueForTeam(Conversation $conversation, string $reason): bool
    {
        if (in_array($conversation->support_state, [ConversationStateMachine::AGENT_ASSIGNED, ConversationStateMachine::AGENT_ACTIVE, ConversationStateMachine::WAITING_FOR_CUSTOMER], true)) {
            return false;
        }
        $first = ConversationStateMachine::moveTo($conversation, ConversationStateMachine::ESCALATION_REQUESTED, null, $reason);
        ConversationStateMachine::moveTo($conversation, ConversationStateMachine::QUEUED_FOR_TEAM, null, $reason);

        return $first === ConversationStateMachine::CHANGED; // false = a duplicate request, nothing new to announce
    }

    /** Is a team member actually online inside working hours? Never assumed: checked from real data. */
    public static function teamAvailable(?\App\Models\Property $property): bool
    {
        if (! $property) {
            return false;
        }

        return BusinessHours::isOpen($property)
            && User::where('tenant_id', $property->tenant_id)->where('is_online', true)
                ->where('last_active_at', '>=', now()->subMinutes(15))->exists();
    }

    /** Staff member explicitly takes over (also assigns the chat to them if unassigned). */
    public static function takeOver(Conversation $conversation, User $user): void
    {
        $conversation->loadMissing('property');
        if (! ConversationStateMachine::actorMayAct($user, $conversation)) {
            abort(403, 'You are not allowed to join this conversation.');
        }
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

        // The join notice is real: it is written only when this call actually moved the chat to AGENT_ACTIVE,
        // so a double click or a second agent can never produce a duplicate or invented "joined" message.
        $moved = ConversationStateMachine::moveTo($conversation, ConversationStateMachine::AGENT_ACTIVE, $user, 'agent_joined');
        if ($moved === ConversationStateMachine::CHANGED) {
            $displayName = \App\Http\Controllers\Api\AgentChatController::formatAgentDisplayName($user, $conversation->property);
            $personal = $displayName === trim((string) $user->name);
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => $user->id,
                'message_type' => 'text',
                'is_automated' => true,
                'content' => $personal ? "{$displayName} from the support team has joined the conversation." : "{$displayName} has joined the conversation.",
                'is_read' => true,
            ]);
        }
        Audit::record('conversation.taken_over', $conversation, [], $user, $conversation->property?->tenant_id);
    }

    /** Explicit, authorised re-activation of automatic AI replies. */
    public static function resumeAi(Conversation $conversation, User $user): void
    {
        ConversationStateMachine::moveTo($conversation, ConversationStateMachine::AUTO_HANDLING, $user, 'ai_resumed');
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

        $online = self::teamAvailable($conversation->property);

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
