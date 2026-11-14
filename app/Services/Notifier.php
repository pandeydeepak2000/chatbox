<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Property;
use App\Models\StaffNotification;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard notifications + Telegram (Phase 2 §9).
 *
 * Rules:
 *  - a notification/Telegram message is created only AFTER the underlying record was saved
 *  - every Telegram send is logged in notification_deliveries; failures are retried with backoff
 *    by the scheduler and are visible in the dashboard (never reported as delivered)
 *  - messages contain minimal data (reference, website, subject) — no PINs, tokens or secrets
 */
class Notifier
{
    public const MAX_ATTEMPTS = 5;

    // ------------------------------------------------------------------ events

    public static function ticketCreated(Ticket $ticket): void
    {
        $ticket->loadMissing(['property', 'visitor']);
        $title = "New ticket {$ticket->reference}";
        $body = "{$ticket->property?->site_name}: {$ticket->subject}";
        self::toUsers(self::staffFor($ticket->property, $ticket->assigned_to), 'ticket.created', $title, $body, ['ticket_id' => $ticket->id]);
        self::telegram($ticket->tenant_id, 'ticket.created', implode("\n", [
            "🎫 {$title} (" . (Ticket::PRIORITIES[$ticket->priority] ?? $ticket->priority) . ')',
            "Website: {$ticket->property?->site_name}",
            "Subject: {$ticket->subject}",
            'Source: ' . str_replace('_', ' ', $ticket->source),
            'Open: ' . url('/app/tickets?id=' . $ticket->id),
        ]));
    }

    public static function chatStarted(Conversation $conversation, ?Message $firstMessage = null): void
    {
        $conversation->loadMissing(['property.tenant', 'visitor', 'customerAccount', 'department']);
        $tenantId = $conversation->property?->tenant_id;
        if (! $tenantId) {
            return;
        }

        $customerId = $conversation->customer_account_id;
        $visitorId = $conversation->visitor_id;
        $propId = $conversation->property_id;

        $baseQuery = Conversation::where('property_id', $propId)
            ->where(function ($q) use ($customerId, $visitorId) {
                if ($customerId) {
                    $q->where('customer_account_id', $customerId)->orWhere('visitor_id', $visitorId);
                } else {
                    $q->where('visitor_id', $visitorId);
                }
            });

        $totalChats = (clone $baseQuery)->count();
        $monthChats = (clone $baseQuery)->where('created_at', '>=', now()->startOfMonth())->count();

        $ordinal = match ($totalChats) {
            1 => 'first chat',
            2 => '2nd chat',
            3 => '3rd chat',
            default => "{$totalChats}th chat",
        };

        $email = $conversation->customerAccount?->email ?: $conversation->visitor?->email;
        $name = $conversation->visitor?->name;
        $username = $email ? (explode('@', $email)[0] ?: $email) : ($name ?: 'Visitor');
        $deptName = $conversation->department?->name ?? 'General Support';
        $propName = $conversation->property?->site_name ?? 'Live Chat';
        $text = $firstMessage?->content ?? 'Started a new live chat';

        $title = 'Urgent Action Needed';
        $body = "{$username} ({$ordinal}) needs support on {$propName} live chat";

        self::toUsers(self::staffFor($conversation->property), 'chat.started', $title, $body, ['conversation_id' => $conversation->id]);

        $telegramLines = [
            '🚨 Urgent Action Needed',
            '',
            "{$username} ({$ordinal}) needs support on {$propName} live chat",
            '',
            '📧 Email: ' . ($email ?: 'Not provided'),
            "📊 Chats this month: {$monthChats} (Total: {$totalChats})",
            "🏢 Department: {$deptName}",
            '💬 Message: ' . mb_substr($text, 0, 500),
            '🔗 Open: ' . url('/app/inbox?id=' . $conversation->id),
        ];

        self::telegram($tenantId, 'chat.started', implode("\n", $telegramLines));
    }

    public static function humanRequested(Conversation $conversation): void
    {
        // Security (logic F7): at most one "wants a human" alert per conversation every 10 minutes,
        // so repeated button presses cannot flood staff dashboards and the Telegram group.
        if (! \Illuminate\Support\Facades\Cache::add('notify:human:' . $conversation->id, 1, 600)) {
            return;
        }
        $conversation->loadMissing(['property', 'visitor']);
        $who = $conversation->visitor?->name ?: 'A visitor';
        self::toUsers(self::staffFor($conversation->property), 'human.requested', 'Customer wants a human', "{$who} on {$conversation->property?->site_name} asked to talk to a person.", ['conversation_id' => $conversation->id]);
        self::telegram($conversation->property->tenant_id, 'human.requested', implode("\n", [
            '🙋 Customer asked for a human',
            "Website: {$conversation->property?->site_name}",
            "Visitor: {$who}",
            'Open: ' . url('/app/inbox?id=' . $conversation->id),
        ]));
    }

    public static function ticketAssigned(Ticket $ticket, User $assignee, User $by): void
    {
        self::toUsers(collect([$assignee]), 'ticket.assigned', "Ticket {$ticket->reference} assigned to you", "{$by->name} assigned: {$ticket->subject}", ['ticket_id' => $ticket->id]);
        self::telegram($ticket->tenant_id, 'ticket.assigned', "👤 {$ticket->reference} assigned to {$assignee->name} by {$by->name}\n{$ticket->subject}");
    }

    public static function ticketReopened(Ticket $ticket): void
    {
        $ticket->loadMissing('property');
        $users = $ticket->assigned_to ? User::whereKey($ticket->assigned_to)->get() : self::staffFor($ticket->property);
        self::toUsers($users, 'ticket.reopened', "Customer replied to {$ticket->reference}", $ticket->subject, ['ticket_id' => $ticket->id]);
        self::telegram($ticket->tenant_id, 'ticket.reopened', "↩️ Customer replied to resolved ticket {$ticket->reference} — back to the team.\n{$ticket->subject}");
    }

    /** New conversation in a Sales department (or tagged as sales): alert + 30-working-minute watch. */
    public static function salesInquiry(Conversation $conversation): void
    {
        $conversation->loadMissing(['property', 'visitor', 'department']);
        $tenantId = $conversation->property->tenant_id;
        $owner = self::salesOwner($conversation->property);
        Task::create([
            'tenant_id' => $tenantId,
            'property_id' => $conversation->property_id,
            'type' => 'sales_first_response',
            'title' => 'Reply to new sales enquiry from ' . ($conversation->visitor?->name ?: 'visitor'),
            'conversation_id' => $conversation->id,
            'owner_id' => $owner?->id,
            'due_at' => now(),
        ]);
        self::toUsers($owner ? collect([$owner]) : self::staffFor($conversation->property), 'sales.inquiry', 'New sales enquiry', ($conversation->visitor?->name ?: 'A visitor') . ' on ' . $conversation->property->site_name, ['conversation_id' => $conversation->id]);
        self::telegram($tenantId, 'sales.inquiry', "💼 New sales enquiry on {$conversation->property->site_name}\nVisitor: " . ($conversation->visitor?->name ?: 'unknown') . "\nOpen: " . url('/app/inbox?id=' . $conversation->id));
    }

    public static function cancelSalesAlert(Conversation $conversation): void
    {
        Task::where('conversation_id', $conversation->id)->where('type', 'sales_first_response')->where('status', 'pending')
            ->update(['status' => 'done', 'completed_at' => now()]);
    }

    public static function cancelTicketReminders(Ticket $ticket): void
    {
        $taskIds = Task::where('ticket_id', $ticket->id)->where('status', 'pending')->pluck('id');
        TaskReminder::whereIn('task_id', $taskIds)->whereNull('sent_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
        Task::whereIn('id', $taskIds)->update(['status' => 'cancelled']);
    }

    /**
     * Staff marked a message as a sales quotation: reminders to the sales follow-up owner at
     * +10 min, +30 min and +2 h from the ORIGINAL quotation time (staff reminders only — the
     * customer is never messaged automatically).
     */
    public static function quotationSent(Message $quotation, User $by): Task
    {
        $conversation = $quotation->conversation()->with('property')->first();
        $owner = self::salesOwner($conversation->property) ?? $by;
        $base = $quotation->created_at ?? now();

        $task = Task::create([
            'tenant_id' => $conversation->property->tenant_id,
            'property_id' => $conversation->property_id,
            'type' => 'quotation_followup',
            'title' => 'Follow up on quotation sent to ' . ($conversation->visitor?->name ?: 'customer'),
            'conversation_id' => $conversation->id,
            'owner_id' => $owner->id,
            'due_at' => $base->copy()->addHours(2),
            'created_by' => $by->id,
        ]);
        foreach ([10, 30, 120] as $minutes) {
            TaskReminder::create(['task_id' => $task->id, 'remind_at' => $base->copy()->addMinutes($minutes)]);
        }
        $quotation->forceFill(['metadata' => array_merge($quotation->metadata ?? [], ['quotation' => true, 'task_id' => $task->id])])->save();

        return $task;
    }

    /** A customer reply cancels the remaining quotation reminders (the task itself stays open). */
    public static function customerReplied(Conversation $conversation): void
    {
        $taskIds = Task::where('conversation_id', $conversation->id)->where('type', 'quotation_followup')->where('status', 'pending')->pluck('id');
        if ($taskIds->isNotEmpty()) {
            TaskReminder::whereIn('task_id', $taskIds)->whereNull('sent_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
        }
    }

    public static function aiBudget(string $tenantId, string $text): void
    {
        $admins = User::where('tenant_id', $tenantId)->where('role', 'admin')->get();
        self::toUsers($admins, 'ai.budget', 'AI budget alert', $text, []);
        self::telegram($tenantId, 'ai.budget', "🤖 {$text}");
    }

    // ------------------------------------------------------------------ scheduled processing

    /** Due quotation reminders (every minute). */
    public static function sendDueReminders(): int
    {
        $count = 0;
        TaskReminder::with('task')->whereNull('sent_at')->whereNull('cancelled_at')->where('remind_at', '<=', now())
            ->orderBy('remind_at')->limit(200)->get()
            ->each(function (TaskReminder $r) use (&$count) {
                $task = $r->task;
                if (! $task || $task->status !== 'pending') {
                    $r->forceFill(['cancelled_at' => now()])->save();

                    return;
                }
                if ($task->owner_id) {
                    self::toUsers(User::whereKey($task->owner_id)->get(), 'task.reminder', 'Reminder: ' . $task->title, 'Scheduled ' . $r->remind_at->setTimezone('Asia/Kolkata')->format('d M H:i') . ' IST', ['task_id' => $task->id, 'conversation_id' => $task->conversation_id]);
                }
                $r->forceFill(['sent_at' => now()])->save();
                $count++;
            });

        return $count;
    }

    /** Sales enquiries without a human first reply after 30 WORKING minutes => one Telegram alert. */
    public static function checkSalesFirstResponse(): int
    {
        $count = 0;
        Task::where('type', 'sales_first_response')->where('status', 'pending')
            ->with('property.tenant')->limit(200)->get()
            ->each(function (Task $task) use (&$count) {
                $conversation = Conversation::find($task->conversation_id);
                if (! $conversation || $conversation->first_human_response_at) {
                    $task->forceFill(['status' => 'done', 'completed_at' => now()])->save();

                    return;
                }
                if ($conversation->sales_alert_sent_at) {
                    return;
                }
                if (BusinessHours::workingMinutesBetween($task->property, $task->created_at, now()) < 30) {
                    return;
                }
                $conversation->forceFill(['sales_alert_sent_at' => now()])->save();
                self::telegram($task->tenant_id, 'sales.overdue', "⏰ Sales enquiry on {$task->property?->site_name} has waited more than 30 working minutes for a first human reply.\nOpen: " . url('/app/inbox?id=' . $conversation->id));
                self::toUsers(User::where('tenant_id', $task->tenant_id)->where('role', 'admin')->get(), 'sales.overdue', 'Sales enquiry waiting > 30 working minutes', $task->title, ['conversation_id' => $conversation->id]);
                $count++;
            });

        return $count;
    }

    /** 10:00 IST daily: one digest per staff member of their unresolved tickets and pending tasks. */
    public static function dailyDigest(): int
    {
        $sent = 0;
        $tickets = Ticket::whereIn('status', Ticket::UNRESOLVED)->get()->groupBy('tenant_id');
        foreach ($tickets as $tenantId => $list) {
            foreach ($list->groupBy(fn ($t) => $t->assigned_to ?? 'unassigned') as $owner => $own) {
                $users = $owner === 'unassigned'
                    ? User::where('tenant_id', $tenantId)->where('role', 'admin')->get()
                    : User::whereKey($owner)->get();
                $lines = $own->take(20)->map(fn ($t) => "{$t->reference} — {$t->subject}")->implode("\n");
                self::toUsers($users, 'tickets.digest', $own->count() . ' unresolved ticket(s)', $lines, ['ticket_ids' => $own->pluck('id')->all()]);
                $sent += $users->count();
            }
            self::telegram($tenantId, 'tickets.pending_digest', "📋 {$list->count()} unresolved ticket(s) at 10 AM:\n" . $list->take(15)->map(fn ($t) => "• {$t->reference} — {$t->subject}")->implode("\n"));
            Ticket::whereIn('id', $list->pluck('id'))->update(['last_reminded_at' => now()]);
        }

        Task::where('status', 'pending')->whereNotNull('owner_id')->get()->groupBy('owner_id')->each(function ($tasks, $ownerId) use (&$sent) {
            self::toUsers(User::whereKey($ownerId)->get(), 'tasks.digest', $tasks->count() . ' pending follow-up task(s)', $tasks->take(20)->pluck('title')->implode("\n"), ['task_ids' => $tasks->pluck('id')->all()]);
            $sent++;
        });

        return $sent;
    }

    /** 30-minute reminder for unresolved pending tickets until the team responds. */
    public static function checkPendingTicketReminders(): int
    {
        $count = 0;
        $pendingTickets = Ticket::whereIn('status', ['unassigned', 'open', 'in_progress', 'pending_customer'])
            ->where('created_at', '<=', now()->subMinutes(30))
            ->where(function ($q) {
                $q->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', now()->subMinutes(30));
            })
            ->with(['property.tenant'])
            ->get()
            ->groupBy('tenant_id');

        foreach ($pendingTickets as $tenantId => $tickets) {
            $ticketLines = $tickets->take(10)->map(function ($t) {
                $mins = round($t->created_at->diffInMinutes(now()));

                return "• {$t->reference} — {$t->subject} (waiting {$mins}m)";
            })->implode("\n");

            $tenantName = $tickets->first()?->property?->tenant?->name ?? 'Company';
            $msg = implode("\n", [
                '⚠️ Pending Support Tickets Alert (>30 mins without resolution)',
                "Company: {$tenantName}",
                $ticketLines,
                '',
                '🔗 Open tickets: ' . url('/app/tickets'),
            ]);

            self::telegram($tenantId, 'tickets.reminders', $msg);
            Ticket::whereIn('id', $tickets->pluck('id'))->update(['last_reminded_at' => now()]);
            $count += $tickets->count();
        }

        return $count;
    }

    /** Retry failed Telegram deliveries with exponential backoff. */
    public static function retryFailedDeliveries(): int
    {
        $n = 0;
        NotificationDelivery::where('status', 'failed')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->limit(50)->get()->each(function ($d) use (&$n) {
                self::deliver($d);
                $n++;
            });

        return $n;
    }

    // ------------------------------------------------------------------ plumbing

    /** @param Collection<int, User> $users */
    public static function toUsers(Collection $users, string $type, string $title, ?string $body, array $data): void
    {
        foreach ($users->unique('id') as $user) {
            if (! $user->tenant_id) {
                continue;
            }
            StaffNotification::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'type' => $type,
                'title' => mb_substr($title, 0, 200),
                'body' => $body,
                'data' => $data ?: null,
            ]);
        }
    }

    public static function telegram(string $tenantId, string $event, string $text): void
    {
        $channels = NotificationChannel::where('tenant_id', $tenantId)->where('is_enabled', true)->get()
            ->filter(fn ($c) => in_array($event, $c->events ?? [], true));

        foreach ($channels as $channel) {
            $delivery = NotificationDelivery::create([
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'event' => $event,
                'status' => 'pending',
                'message' => mb_substr($text, 0, 3500),
            ]);
            if (app()->runningInConsole()) {
                self::deliver($delivery);
            } else {
                // cPanel friendly: sent right after the HTTP response; the cron retries failures.
                dispatch(fn () => Notifier::deliver(NotificationDelivery::find($delivery->id)))->afterResponse();
            }
        }
    }

    public static function deliver(?NotificationDelivery $delivery): void
    {
        if (! $delivery || $delivery->status === 'sent') {
            return;
        }
        $channel = NotificationChannel::find($delivery->channel_id);
        if (! $channel || ! $channel->is_enabled) {
            $delivery->forceFill(['status' => 'failed', 'attempts' => self::MAX_ATTEMPTS, 'last_error' => 'Channel disabled or removed'])->save();

            return;
        }

        $result = TelegramService::send($channel, $delivery->message);
        $attempts = $delivery->attempts + 1;
        if ($result['ok']) {
            $delivery->forceFill(['status' => 'sent', 'attempts' => $attempts, 'sent_at' => now(), 'last_error' => null])->save();
            $channel->forceFill(['last_success_at' => now(), 'last_error' => null])->save();
        } else {
            $delivery->forceFill([
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => mb_substr($result['error'], 0, 500),
                'next_attempt_at' => now()->addMinutes(min(60, 2 ** $attempts)),
            ])->save();
            $channel->forceFill(['last_error' => mb_substr($result['error'], 0, 500)])->save();
            Log::warning('Telegram delivery failed', ['delivery' => $delivery->id, 'error' => $result['error']]);
        }
    }

    /** Staff who can work on this website (admins + agents with access), optionally plus an assignee. */
    public static function staffFor(?Property $property, ?string $plusUserId = null): Collection
    {
        if (! $property) {
            return collect();
        }
        $users = User::where('tenant_id', $property->tenant_id)->whereIn('role', ['admin', 'agent'])->get()
            ->filter(fn (User $u) => $u->canAccessProperty($property));
        if ($plusUserId && ! $users->contains('id', $plusUserId)) {
            $users = $users->merge(User::whereKey($plusUserId)->get());
        }

        return $users->values();
    }

    /** Company setting "sales follow-up owner" (e.g. Chandrika). */
    public static function salesOwner(?Property $property): ?User
    {
        $id = $property?->tenant?->settings['sales_followup_user_id'] ?? null;

        return $id ? User::where('id', $id)->where('tenant_id', $property->tenant_id)->first() : null;
    }
}
