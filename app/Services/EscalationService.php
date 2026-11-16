<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\SupportBot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The real 60-second senior-support window.
 *
 *   request()     escalated_at = now (server), deadline_at = escalated_at + 60 s, Telegram alert, customer countdown.
 *   onAgentJoin() an authorised agent takes over: inside the window -> joined, NO ticket; after the deadline but before
 *                 anything finalised it -> the ticket is registered first (exactly once), then the agent continues
 *                 in the same chat and the ticket is linked to it.
 *   finalize()    nobody joined: exactly one ticket, one customer message, whoever gets there first
 *                 (widget poll while the visitor is online, otherwise the scheduler `chatbox:escalations`).
 *
 * Concurrency: every path locks the conversation row first and the escalation row second, and re-reads the status
 * under the lock, so a takeover racing a timeout always ends in ONE consistent state. `open_key` (unique) also
 * guarantees one open escalation per conversation at database level.
 *
 * cPanel note: the scheduler runs once a minute, so for an offline visitor the ticket appears 0-60 s after the
 * deadline (up to ~2 minutes after escalation). While the widget is open it is finalised within a poll (~2.5 s).
 */
class EscalationService
{
    /**
     * Start (or return) the senior-support process for a conversation.
     *
     * @param array{category?:string, summary?:array, source?:string, priority?:string, prefix?:string} $options
     *
     * @return array{escalation: ?Escalation, created: bool, ticket: ?Ticket}
     */
    public static function request(Conversation $conversation, string $reason, array $options = [], ?string $lang = null): array
    {
        $conversation->loadMissing('property.tenant');
        $lang ??= $conversation->language ?? 'en';
        $property = $conversation->property;
        $open = BusinessHours::isOpen($property);

        $result = DB::transaction(function () use ($conversation, $reason, $options, $open) {
            $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->first();
            if (! $locked) {
                return ['escalation' => null, 'created' => false, 'ticket' => null];
            }
            if ($existing = Escalation::where('conversation_id', $locked->id)->where('status', 'waiting')->lockForUpdate()->first()) {
                return ['escalation' => $existing, 'created' => false, 'ticket' => null]; // duplicate click / retry / refresh
            }
            if ($ticket = Ticket::where('open_conversation_id', $locked->id)->first()) {
                return ['escalation' => null, 'created' => false, 'ticket' => $ticket]; // already with the team
            }
            if (in_array($locked->support_state, [ConversationStateMachine::AGENT_ACTIVE, ConversationStateMachine::AGENT_ASSIGNED], true)) {
                return ['escalation' => null, 'created' => false, 'ticket' => null]; // a person is already on it
            }

            $now = now();
            $escalation = Escalation::create([
                'tenant_id' => $locked->property->tenant_id,
                'property_id' => $locked->property_id,
                'conversation_id' => $locked->id,
                'reason' => mb_substr($reason, 0, 40),
                'ticket_options' => array_intersect_key($options, array_flip(['category', 'summary', 'source', 'priority'])) ?: null,
                'escalated_at' => $now,
                // Outside working hours nobody can answer in 60 s, so no countdown is staged: the ticket follows at once.
                'deadline_at' => $open ? $now->copy()->addSeconds(Escalation::WINDOW_SECONDS) : $now,
                'status' => 'waiting',
                'open_key' => $locked->id,
            ]);

            $locked->forceFill(['handler' => 'human', 'handover_at' => $locked->handover_at ?? $now, 'ai_pending_since' => null])->save();
            ConversationService::queueForTeam($locked, $reason);

            return ['escalation' => $escalation, 'created' => true, 'ticket' => null];
        });

        $escalation = $result['escalation'];
        if ($result['created'] && $escalation) {
            $conversation->refresh();
            if ($open) {
                $prefix = trim((string) ($options['prefix'] ?? ''));
                $wait = SupportBot::t('escalation_wait', $lang);
                SupportBot::botReply($conversation, $prefix !== '' ? $prefix . "\n\n" . $wait : $wait, [
                    'source' => 'handover', 'escalation_id' => $escalation->id, 'waiting_senior' => true,
                ], true);
            }
            // Durable and idempotent: the event row is written now, delivery is retried by cron if Telegram is down.
            Notifier::escalationRequested($conversation, $escalation);

            if (! $open) {
                $escalation = self::finalize($escalation, 'closed_hours');
                $result['ticket'] = $escalation->ticket_id ? Ticket::find($escalation->ticket_id) : null;
            }
        }

        return $result;
    }

    /** An authorised agent joins (take over or first staff reply). Never creates a second ticket. */
    public static function onAgentJoin(Conversation $conversation, User $agent): string
    {
        return DB::transaction(function () use ($conversation, $agent) {
            Conversation::whereKey($conversation->id)->lockForUpdate()->first();
            $esc = Escalation::where('conversation_id', $conversation->id)->where('status', 'waiting')->lockForUpdate()->first();
            if (! $esc) {
                return 'no_escalation';
            }
            if ($esc->deadline_at->lte(now())) {
                // The deadline passed and nobody had finalised it yet: the ticket is registered first (exactly once),
                // then the agent carries on in the same chat with the ticket linked.
                self::finalizeLocked($esc, 'agent_late');

                return 'joined_after_ticket';
            }
            $esc->forceFill(['status' => 'joined', 'open_key' => null, 'joined_by' => $agent->id, 'joined_at' => now(), 'finalized_at' => now(), 'finalized_via' => 'agent'])->save();

            return 'joined_in_time';
        });
    }

    /** Create/reuse the single ticket for a due escalation and tell the customer. Safe to call repeatedly. */
    public static function finalize(Escalation $escalation, string $via): Escalation
    {
        return DB::transaction(function () use ($escalation, $via) {
            Conversation::whereKey($escalation->conversation_id)->lockForUpdate()->first();
            $fresh = Escalation::whereKey($escalation->id)->lockForUpdate()->first();
            if (! $fresh || ! $fresh->isWaiting()) {
                return $fresh ?? $escalation; // someone joined, or another worker already finalised: nothing to do
            }

            return self::finalizeLocked($fresh, $via);
        });
    }

    /** Caller holds the conversation + escalation row locks and has verified status = waiting. */
    private static function finalizeLocked(Escalation $esc, string $via): Escalation
    {
        $conversation = Conversation::with('property')->find($esc->conversation_id);
        if (! $conversation || $conversation->status === 'closed') {
            $esc->forceFill(['status' => 'cancelled', 'open_key' => null, 'finalized_at' => now(), 'finalized_via' => 'closed'])->save();

            return $esc;
        }

        $opts = $esc->ticket_options ?? [];
        [$ticket, $created] = TicketService::createOrReuse($conversation, array_filter([
            'source' => $opts['source'] ?? $esc->reason,
            'category' => $opts['category'] ?? 'general',
            'summary' => $opts['summary'] ?? null,
            'priority' => $opts['priority'] ?? null,
            'idempotency_key' => 'esc-' . $esc->id,
        ], fn ($v) => $v !== null));

        $esc->forceFill(['status' => 'ticketed', 'open_key' => null, 'ticket_id' => $ticket->id, 'finalized_at' => now(), 'finalized_via' => $via])->save();

        if ($created || ! Message::where('conversation_id', $conversation->id)->where('metadata->escalation_ticket', $esc->id)->exists()) {
            $lang = $conversation->language ?? 'en';
            $key = $conversation->customer_account_id ? 'ticket_registered' : 'ticket_registered_guest';
            SupportBot::botReply($conversation, SupportBot::t($key, $lang, ['ref' => $ticket->reference]) . SupportBot::hoursNote($conversation->property, $lang), [
                'source' => 'ticket', 'ticket_id' => $ticket->id, 'escalation_ticket' => $esc->id,
            ], true);
        }

        return $esc;
    }

    /** Widget poll: finalise this conversation's escalation if it is due. */
    public static function finalizeIfDue(Conversation $conversation): void
    {
        $esc = Escalation::where('conversation_id', $conversation->id)->where('status', 'waiting')->first();
        if ($esc && $esc->isDue()) {
            try {
                self::finalize($esc, 'widget');
            } catch (\Throwable $e) {
                Log::error('Escalation finalize (widget) failed: ' . $e->getMessage(), ['escalation' => $esc->id]);
            }
        }
    }

    /** Scheduler (`chatbox:escalations`, every minute): finalise every due escalation even if the visitor is gone. */
    public static function finalizeDue(int $limit = 200): int
    {
        $n = 0;
        Escalation::where('status', 'waiting')->where('deadline_at', '<=', now())->orderBy('deadline_at')->limit($limit)->get()
            ->each(function (Escalation $esc) use (&$n) {
                try {
                    if (self::finalize($esc, 'cron')->status === 'ticketed') {
                        $n++;
                    }
                } catch (\Throwable $e) {
                    Log::error('Escalation finalize (cron) failed: ' . $e->getMessage(), ['escalation' => $esc->id]);
                }
            });

        return $n;
    }

    /**
     * What the widget needs, from the server clock only.
     *
     * @return array{waiting: bool, remaining: int, deadline_at: ?string, server_time: string}
     */
    public static function clientState(Conversation $conversation): array
    {
        $esc = Escalation::where('conversation_id', $conversation->id)->where('status', 'waiting')->first();

        return [
            'waiting' => (bool) $esc,
            'remaining' => $esc ? $esc->remainingSeconds() : 0,
            'deadline_at' => $esc?->deadline_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
