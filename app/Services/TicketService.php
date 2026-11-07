<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ticket lifecycle (Phase 2 §5).
 *
 * Duplicate prevention is enforced by the database, not by a "check then insert":
 *  - unique (property_id, idempotency_key): retries/double clicks return the same ticket
 *  - unique open_conversation_id: at most one unresolved ticket per conversation
 * A ticket reference is shown to anyone only after the transaction committed.
 */
class TicketService
{
    public const REOPEN_WINDOW_DAYS = 7;

    /**
     * @param  array{source?:string, subject?:string, summary?:array, priority?:string, category?:string,
     *               idempotency_key?:?string, department_id?:?string, actor?:?User, handover?:bool}  $o
     * @return array{0: Ticket, 1: bool}  [ticket, createdNow]
     */
    public static function createOrReuse(Conversation $conversation, array $o = []): array
    {
        $conversation->loadMissing('property');
        $property = $conversation->property;
        $key = $o['idempotency_key'] ?? null;

        if ($key && ($existing = Ticket::where('property_id', $property->id)->where('idempotency_key', $key)->first())) {
            return [$existing, false];
        }
        if ($existing = Ticket::where('open_conversation_id', $conversation->id)->first()) {
            return [$existing, false];
        }

        try {
            $ticket = DB::transaction(function () use ($conversation, $property, $o, $key) {
                $tenant = DB::table('tenants')->where('id', $property->tenant_id)->lockForUpdate()->first(['ticket_counter', 'ticket_prefix']);
                $number = (int) $tenant->ticket_counter + 1;
                DB::table('tenants')->where('id', $property->tenant_id)->update(['ticket_counter' => $number]);

                $ticket = Ticket::create([
                    'tenant_id' => $property->tenant_id,
                    'property_id' => $property->id,
                    'number' => $number,
                    'reference' => ($tenant->ticket_prefix ?: 'TKT') . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                    'conversation_id' => $conversation->id,
                    'open_conversation_id' => $conversation->id,
                    'customer_account_id' => $conversation->customer_account_id,
                    'visitor_id' => $conversation->visitor_id,
                    'department_id' => $o['department_id'] ?? $conversation->department_id,
                    'assigned_to' => $conversation->assigned_agent_id,
                    'status' => 'open',
                    'priority' => $o['priority'] ?? 'normal',
                    'category' => $o['category'] ?? 'general',
                    'subject' => mb_substr($o['subject'] ?? self::subjectFrom($conversation), 0, 200),
                    'summary' => $o['summary'] ?? self::summaryFrom($conversation),
                    'source' => $o['source'] ?? 'staff',
                    'idempotency_key' => $key,
                ]);

                if ($ticket->assigned_to) {
                    TicketAssignment::create(['ticket_id' => $ticket->id, 'user_id' => $ticket->assigned_to, 'assigned_at' => now()]);
                }
                self::event($ticket, 'created', ['source' => $ticket->source], $o['actor'] ?? null);

                if ($o['handover'] ?? true) {
                    ConversationService::markHuman($conversation, $o['actor'] ?? null);
                }

                return $ticket;
            });
        } catch (QueryException $e) {
            // Lost a race with a concurrent request: return the ticket that won.
            $winner = ($key ? Ticket::where('property_id', $property->id)->where('idempotency_key', $key)->first() : null)
                ?? Ticket::where('open_conversation_id', $conversation->id)->first();
            if ($winner) {
                return [$winner, false];
            }
            throw $e;
        }

        // Only after commit: customer-visible confirmation (automatic escalations are announced by the
        // bot in the customer's language) and notifications.
        if ($ticket->source === 'staff') Message::create([
            'conversation_id' => $conversation->id,
            'ticket_id' => $ticket->id,
            'sender_type' => 'system',
            'message_type' => 'text',
            'is_automated' => true,
            'content' => "Support ticket {$ticket->reference} has been created. Our team will reply here — you can also follow it under \"My Tickets\".",
            'is_read' => true,
        ]);
        Notifier::ticketCreated($ticket);

        return [$ticket, true];
    }

    public static function reply(Ticket $ticket, User $user, string $content, ?string $attachmentRef = null, ?string $messageType = null): Message
    {
        $conversation = $ticket->conversation;
        if (! $conversation) {
            throw ValidationException::withMessages(['content' => ['This ticket has no conversation to reply in.']]);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'ticket_id' => $ticket->id,
            'sender_type' => 'agent',
            'sender_id' => $user->id,
            'message_type' => $messageType ?? 'text',
            'content' => $content,
            'attachment_url' => $attachmentRef,
            'is_read' => true,
        ]);

        ConversationService::recordHumanReply($conversation, $user);
        self::staffReplied($ticket, $user, $message);

        return $message;
    }

    /**
     * Bookkeeping after a customer-visible staff reply (from the ticket page or the live inbox):
     * response times, waiting-on-customer status, history, and an unassigned ticket is claimed by
     * the person who answered it, so ticket and conversation ownership never disagree.
     */
    public static function staffReplied(Ticket $ticket, User $user, Message $message): void
    {
        $ticket->forceFill([
            'first_response_at' => $ticket->first_response_at ?? now(),
            'last_staff_reply_at' => now(),
            'status' => $ticket->isUnresolved() ? 'pending_customer' : $ticket->status,
        ])->save();
        self::event($ticket, 'replied', ['message_id' => $message->id], $user);
        if (! $ticket->assigned_to && $user->tenant_id === $ticket->tenant_id && $user->canAccessProperty($ticket->property)) {
            self::assign($ticket, $user, $user);
        }
    }

    /** Private internal note: stored as an internal message, never visible in the widget. */
    public static function note(Ticket $ticket, User $user, string $body): Message
    {
        $message = Message::create([
            'conversation_id' => $ticket->conversation_id,
            'ticket_id' => $ticket->id,
            'sender_type' => 'agent',
            'sender_id' => $user->id,
            'message_type' => 'text',
            'visibility' => 'internal',
            'content' => $body,
            'is_read' => true,
        ]);
        self::event($ticket, 'note_added', ['message_id' => $message->id], $user);

        return $message;
    }

    public static function changeStatus(Ticket $ticket, string $status, ?User $user, ?string $reason = null): Ticket
    {
        if (! isset(Ticket::STATUSES[$status]) || $status === $ticket->status) {
            return $ticket;
        }
        $from = $ticket->status;

        DB::transaction(function () use ($ticket, $status, $user) {
            if (in_array($status, ['resolved', 'closed'], true)) {
                $ticket->forceFill([
                    'status' => $status,
                    'open_conversation_id' => null,
                    'resolved_at' => $ticket->resolved_at ?? now(),
                    'resolved_by' => $user?->id,
                ])->save();
            } else {
                if (! $ticket->isUnresolved() && $ticket->conversation_id) {
                    // Reopen: only possible if the conversation has no other open ticket.
                    $other = Ticket::where('open_conversation_id', $ticket->conversation_id)->where('id', '!=', $ticket->id)->first();
                    if ($other) {
                        throw ValidationException::withMessages(['status' => ["This conversation already has an open ticket ({$other->reference})."]]);
                    }
                }
                $ticket->forceFill([
                    'status' => $status,
                    'open_conversation_id' => $ticket->conversation_id,
                    'resolved_at' => null,
                    'resolved_by' => null,
                ])->save();
            }
        });

        self::event($ticket, 'status_changed', ['from' => $from, 'to' => $status, 'reason' => $reason], $user);
        if (in_array($status, ['resolved', 'closed'], true)) {
            Notifier::cancelTicketReminders($ticket);
        }

        return $ticket;
    }

    public static function assign(Ticket $ticket, ?User $assignee, User $by): Ticket
    {
        if ($assignee && ($assignee->tenant_id !== $ticket->tenant_id || ! $assignee->canAccessProperty($ticket->property))) {
            throw ValidationException::withMessages(['assigned_to' => ['This staff member cannot access this website.']]);
        }
        if (($assignee?->id) === $ticket->assigned_to) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $assignee, $by) {
            TicketAssignment::where('ticket_id', $ticket->id)->whereNull('unassigned_at')->update(['unassigned_at' => now()]);
            if ($assignee) {
                TicketAssignment::create(['ticket_id' => $ticket->id, 'user_id' => $assignee->id, 'assigned_by' => $by->id, 'assigned_at' => now()]);
            }
            $ticket->forceFill(['assigned_to' => $assignee?->id])->save();
        });
        self::event($ticket, 'assigned', ['to' => $assignee?->id, 'to_name' => $assignee?->name], $by);
        if ($assignee && $assignee->id !== $by->id) {
            Notifier::ticketAssigned($ticket, $assignee, $by);
        }

        return $ticket;
    }

    /**
     * A customer wrote in a conversation: the open ticket goes back to the team; a recently
     * resolved ticket is reopened so the reply returns to the team's attention.
     */
    public static function customerReplied(Conversation $conversation): ?Ticket
    {
        $ticket = Ticket::where('open_conversation_id', $conversation->id)->first();
        if ($ticket) {
            $ticket->forceFill(['status' => 'pending_team', 'last_customer_reply_at' => now()])->save();

            return $ticket;
        }

        $recent = Ticket::where('conversation_id', $conversation->id)
            ->whereIn('status', ['resolved', 'closed'])
            ->where('resolved_at', '>=', now()->subDays(self::REOPEN_WINDOW_DAYS))
            ->latest('resolved_at')
            ->first();
        if ($recent) {
            try {
                self::changeStatus($recent, 'pending_team', null, 'customer replied');
                $recent->forceFill(['last_customer_reply_at' => now()])->save();
                ConversationService::markHuman($conversation, null);
                Notifier::ticketReopened($recent);

                return $recent;
            } catch (ValidationException) {
                return null;
            }
        }

        return null;
    }

    public static function event(Ticket $ticket, string $type, array $data = [], ?User $actor = null, ?string $actorType = null): void
    {
        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'actor_type' => $actorType ?? ($actor ? 'user' : 'system'),
            'actor_id' => $actor?->id,
            'type' => $type,
            'data' => array_filter($data, fn ($v) => $v !== null) ?: null,
        ]);
    }

    /**
     * The ticket is about what is still unresolved, so the subject is the most recent customer
     * message that describes a problem (pure "talk to a human" requests are skipped).
     */
    private static function subjectFrom(Conversation $conversation): string
    {
        $issue = self::issueMessages($conversation)['issue'];

        return $issue !== null ? mb_substr(preg_replace('/\s+/', ' ', $issue), 0, 120) : 'Support request';
    }

    /** Factual summary built from the conversation (used when no AI summary is available). */
    private static function summaryFrom(Conversation $conversation): array
    {
        ['issue' => $issue, 'earlier' => $earlier] = self::issueMessages($conversation);

        return [
            'issue' => $issue !== null ? mb_substr($issue, 0, 300) : null,
            'affected_service' => null,
            'steps_tried' => null,
            'results' => $earlier ? 'Earlier in the chat: ' . implode(' | ', array_map(fn ($c) => mb_substr($c, 0, 200), $earlier)) : null,
            'unresolved' => $issue !== null ? mb_substr($issue, 0, 300) : null,
        ];
    }

    /** @return array{issue: ?string, earlier: list<string>} */
    private static function issueMessages(Conversation $conversation): array
    {
        $all = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', 'visitor')->where('visibility', 'public')
            ->orderByDesc('seq')->limit(10)->pluck('content')
            ->map(fn ($c) => trim((string) $c))->filter()->values()->all();

        $issue = null;
        foreach ($all as $i => $text) {
            if (! \App\Services\Ai\SupportBot::wantsHuman($text) || str_word_count($text) > 8) {
                $issue = $text;
                unset($all[$i]);
                break;
            }
        }
        $issue ??= $all[0] ?? null;
        $earlier = array_values(array_filter(array_reverse(array_values($all)), fn ($t) => $t !== $issue && ! \App\Services\Ai\SupportBot::wantsHuman($t)));

        return ['issue' => $issue, 'earlier' => array_slice($earlier, -4)];
    }
}
