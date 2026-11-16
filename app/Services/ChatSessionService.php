<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Models\Property;
use App\Models\Visitor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * A live chat exists from the moment the visitor opens it: before e-mail, PIN or the first question.
 * Opening it queues ONE Telegram event (durable, deduplicated). Refreshes and repeated opens reuse the session.
 */
class ChatSessionService
{
    /** A visitor who comes back within this window continues the same session (no second alert). */
    public const REUSE_MINUTES = 30;

    /** @return array{0: ChatSession, 1: bool} [session, createdNow] */
    public static function begin(Property $property, Visitor $visitor, ?string $departmentId = null, ?CustomerAccount $customer = null): array
    {
        $session = ChatSession::where('property_id', $property->id)->where('visitor_id', $visitor->id)
            ->where('started_at', '>=', now()->subMinutes(self::REUSE_MINUTES))
            ->where(function ($q) {
                $q->whereNull('conversation_id')->orWhereIn('conversation_id', Conversation::whereIn('status', ['unassigned', 'open', 'pending'])->select('id'));
            })
            ->latest('started_at')->first();

        if ($session) {
            // Later knowledge (department chosen, customer signed in) is stored, but never triggers a second alert.
            $session->forceFill(array_filter([
                'department_id' => $session->department_id ?: $departmentId,
                'customer_account_id' => $session->customer_account_id ?: $customer?->id,
            ]))->save();

            return [$session, false];
        }

        $session = null;
        for ($i = 0; $i < 5 && ! $session; $i++) {
            try {
                $session = ChatSession::create([
                    'tenant_id' => $property->tenant_id,
                    'property_id' => $property->id,
                    'visitor_id' => $visitor->id,
                    'department_id' => $departmentId,
                    'customer_account_id' => $customer?->id,
                    'reference' => self::newReference($property),
                    'started_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // reference collision: try another one
            }
        }
        abort_unless($session, 503, 'Could not start the chat. Please try again.');

        Notifier::liveChatStarted($session);

        return [$session, true];
    }

    /** The visitor's running session, if any (used when the first question arrives). */
    public static function current(Property $property, Visitor $visitor): ?ChatSession
    {
        return ChatSession::where('property_id', $property->id)->where('visitor_id', $visitor->id)->whereNull('conversation_id')
            ->where('started_at', '>=', now()->subMinutes(self::REUSE_MINUTES))->latest('started_at')->first();
    }

    /** First question sent: the session becomes this conversation (shows the reference in the dashboard). */
    public static function attach(ChatSession $session, Conversation $conversation): void
    {
        $session->forceFill(['conversation_id' => $conversation->id, 'customer_account_id' => $conversation->customer_account_id ?: $session->customer_account_id, 'department_id' => $conversation->department_id ?: $session->department_id])->save();
        $conversation->forceFill(['chat_reference' => $session->reference])->save();
    }

    private static function newReference(Property $property): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($property->tenant?->ticket_prefix ?: 'CHT'))) ?: 'CHT';

        return 'CH-' . mb_substr($prefix, 0, 6) . '-' . strtoupper(Str::random(6));
    }
}
