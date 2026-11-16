<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Customer-support state machine (internal names, never shown to customers).
 *
 *   AUTO_HANDLING -> ESCALATION_REQUESTED -> QUEUED_FOR_TEAM -> AGENT_ASSIGNED -> AGENT_ACTIVE
 *   AGENT_ACTIVE <-> WAITING_FOR_CUSTOMER -> RESOLVED -> CLOSED   (RESOLVED/CLOSED can reopen)
 *
 * Rules:
 *  - every transition runs under a row lock, so two requests can never both win a race;
 *  - an illegal transition is refused (returns false) and changes nothing;
 *  - repeating the current state is a harmless no-op (double taps, retries, duplicate polls);
 *  - an agent actor must belong to the conversation's company and be allowed on its website
 *    (the platform super admin is deliberately NOT allowed to work inside a company's inbox);
 *  - every real change is written to conversation_state_logs.
 *
 * `conversations.handler` stays the single switch that stops automated replies:
 * it becomes 'human' on the first escalation and only an explicit, audited staff action
 * (ConversationService::resumeAi) sets it back to 'ai'.
 */
class ConversationStateMachine
{
    public const AUTO_HANDLING = 'AUTO_HANDLING';
    public const ESCALATION_REQUESTED = 'ESCALATION_REQUESTED';
    public const QUEUED_FOR_TEAM = 'QUEUED_FOR_TEAM';
    public const AGENT_ASSIGNED = 'AGENT_ASSIGNED';
    public const AGENT_ACTIVE = 'AGENT_ACTIVE';
    public const WAITING_FOR_CUSTOMER = 'WAITING_FOR_CUSTOMER';
    public const RESOLVED = 'RESOLVED';
    public const CLOSED = 'CLOSED';

    /** @var array<string, string[]> */
    private const ALLOWED = [
        self::AUTO_HANDLING => [self::ESCALATION_REQUESTED, self::QUEUED_FOR_TEAM, self::AGENT_ASSIGNED, self::AGENT_ACTIVE, self::RESOLVED, self::CLOSED],
        self::ESCALATION_REQUESTED => [self::QUEUED_FOR_TEAM, self::AGENT_ASSIGNED, self::AGENT_ACTIVE, self::AUTO_HANDLING, self::RESOLVED, self::CLOSED],
        self::QUEUED_FOR_TEAM => [self::AGENT_ASSIGNED, self::AGENT_ACTIVE, self::AUTO_HANDLING, self::RESOLVED, self::CLOSED],
        self::AGENT_ASSIGNED => [self::AGENT_ACTIVE, self::QUEUED_FOR_TEAM, self::AUTO_HANDLING, self::RESOLVED, self::CLOSED],
        self::AGENT_ACTIVE => [self::WAITING_FOR_CUSTOMER, self::QUEUED_FOR_TEAM, self::AGENT_ASSIGNED, self::AUTO_HANDLING, self::RESOLVED, self::CLOSED],
        self::WAITING_FOR_CUSTOMER => [self::AGENT_ACTIVE, self::QUEUED_FOR_TEAM, self::AUTO_HANDLING, self::RESOLVED, self::CLOSED],
        self::RESOLVED => [self::AGENT_ACTIVE, self::QUEUED_FOR_TEAM, self::AUTO_HANDLING, self::CLOSED],
        self::CLOSED => [self::AGENT_ACTIVE, self::QUEUED_FOR_TEAM, self::AUTO_HANDLING],
    ];

    /** States in which a request is with the team but no agent has joined yet. */
    public const WAITING_FOR_AGENT = [self::ESCALATION_REQUESTED, self::QUEUED_FOR_TEAM, self::AGENT_ASSIGNED];

    public static function states(): array
    {
        return array_keys(self::ALLOWED);
    }

    public static function can(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /** Server-side authorization for staff actors. System/customer actors are always allowed (they are the app itself). */
    public static function actorMayAct(?User $actor, Conversation $conversation): bool
    {
        if ($actor === null) {
            return true;
        }
        $conversation->loadMissing('property');
        if ($actor->isSuperAdmin()) {
            return false; // platform admin never works inside a company's private conversations
        }

        return $actor->canAccessProperty($conversation->property);
    }

    public const CHANGED = 'changed';
    public const SAME = 'same';
    public const REFUSED = 'refused';

    /** @return bool true when the conversation is now in $to (changed or already there); false when refused */
    public static function transition(Conversation $conversation, string $to, ?User $actor = null, string $reason = '', string $actorType = 'system'): bool
    {
        return self::moveTo($conversation, $to, $actor, $reason, $actorType) !== self::REFUSED;
    }

    /**
     * Same as transition() but says what happened, so callers can send a notice/alert exactly once
     * (only on CHANGED), however many duplicate requests arrive.
     *
     * @param string $actorType system | customer | agent (derived from $actor when given)
     *
     * @return string CHANGED | SAME | REFUSED
     */
    public static function moveTo(Conversation $conversation, string $to, ?User $actor = null, string $reason = '', string $actorType = 'system'): string
    {
        if (! isset(self::ALLOWED[$to])) {
            return self::REFUSED;
        }
        if (! self::actorMayAct($actor, $conversation)) {
            Audit::record('conversation.state_refused', $conversation, ['to' => $to], $actor, $conversation->property?->tenant_id);

            return self::REFUSED;
        }

        $result = DB::transaction(function () use ($conversation, $to, $actor, $reason, $actorType) {
            $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->first();
            if (! $locked) {
                return self::REFUSED;
            }
            $from = $locked->support_state ?: self::AUTO_HANDLING;
            if ($from === $to) {
                return self::SAME; // already there: idempotent
            }
            if (! self::can($from, $to)) {
                return self::REFUSED;
            }

            $updates = ['support_state' => $to, 'support_state_changed_at' => now()];
            if ($to === self::ESCALATION_REQUESTED && ! $locked->escalation_requested_at) {
                $updates['escalation_requested_at'] = now();
            }
            $locked->forceFill($updates)->save();

            DB::table('conversation_state_logs')->insert([
                'conversation_id' => $locked->id,
                'from_state' => $from,
                'to_state' => $to,
                'actor_type' => $actor ? 'agent' : $actorType,
                'actor_id' => $actor?->id,
                'reason' => mb_substr($reason, 0, 80) ?: null,
                'created_at' => now(),
            ]);

            return self::CHANGED;
        });

        $conversation->refresh();

        return $result;
    }
    /** True while the request waits for a team member (nobody has accepted it yet). */
    public static function isWaitingForAgent(Conversation $conversation): bool
    {
        return in_array($conversation->support_state, self::WAITING_FOR_AGENT, true);
    }
}
