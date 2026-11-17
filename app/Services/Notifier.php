<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Escalation;
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

    /** "1 hour 30 minutes" for customer/staff facing messages (Telegram reminders). */
    public static function humanDuration(int|float $mins): string
    {
        $mins = max(0, (int) round($mins));
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        $parts = [];
        if ($h > 0) {
            $parts[] = $h . ($h === 1 ? ' hour' : ' hours');
        }
        if ($m > 0 || ! $parts) {
            $parts[] = $m . ($m === 1 ? ' minute' : ' minutes');
        }

        return implode(' ', $parts);
    }

    public static function formatWaitDuration(int|float $mins): string
    {
        $mins = (int) round($mins);
        if ($mins < 60) {
            return "{$mins}m";
        }
        $hours = floor($mins / 60);
        $remMins = $mins % 60;

        return $remMins > 0 ? "{$hours}h {$remMins}m" : "{$hours}h";
    }

    public static function customerEmailForTicket(Ticket $ticket): ?string
    {
        return $ticket->customerAccount?->email
            ?: $ticket->visitor?->email
            ?: $ticket->conversation?->customerAccount?->email
            ?: $ticket->conversation?->visitor?->email;
    }

    public static function ticketCreated(Ticket $ticket): void
    {
        $ticket->loadMissing(['property', 'visitor', 'customerAccount', 'conversation.customerAccount', 'conversation.visitor']);
        $title = "New ticket {$ticket->reference}";
        $body = "{$ticket->property?->site_name}: {$ticket->subject}";
        self::toUsers(self::staffFor($ticket->property, $ticket->assigned_to), 'ticket.created', $title, $body, ['ticket_id' => $ticket->id]);

        $customerEmail = self::customerEmailForTicket($ticket);
        $lines = [
            "🎫 {$title} (" . (Ticket::PRIORITIES[$ticket->priority] ?? $ticket->priority) . ')',
            "Website: {$ticket->property?->site_name}",
            '📧 Customer: ' . ($customerEmail ?: 'Not provided'),
            "Subject: {$ticket->subject}",
            'Source: ' . str_replace('_', ' ', $ticket->source),
            'Open: ' . self::dashboardUrl($ticket->tenant_id, 'tickets', $ticket->id),
        ];

        self::telegram($ticket->tenant_id, 'ticket.created', implode("\n", $lines));
    }

    /**
     * Telegram alert the moment a visitor opens a live chat: BEFORE e-mail, PIN or any question. The event row is
     * written to the database first (durable); delivery is retried by cron if Telegram is unavailable. The dedupe key
     * makes refreshes, retries and double clicks harmless: one chat session = one alert per channel.
     */
    public static function liveChatStarted(\App\Models\ChatSession $session): void
    {
        $session->loadMissing(['property.tenant', 'visitor', 'department', 'customerAccount']);
        $property = $session->property;
        if (! $property) {
            return;
        }
        $cfg = BusinessHours::config($property);
        $email = $session->customerAccount?->email;
        $who = $email ? self::maskEmail($email) . ($session->customerAccount?->email_verified_at ? '' : ' (e-mail not verified yet)') : 'Visitor — verification pending';
        $started = $session->started_at->copy()->timezone($cfg['timezone'])->format('d M Y, h:i:s A');
        $url = self::dashboardUrl($session->tenant_id, 'inbox');

        self::toUsers(self::staffFor($property), 'chat.started', 'New live chat', "{$session->reference} on {$property->site_name}", ['chat_session_id' => $session->id]);
        self::telegram($session->tenant_id, 'chat.started', implode("\n", [
            '💬 New live chat started',
            'Company: ' . ($property->tenant?->name ?? '—'),
            "Website: {$property->site_name}",
            "Chat: {$session->reference}",
            'Department: ' . ($session->department?->name ?? 'Not selected yet'),
            "Customer: {$who}",
            "Started: {$started} {$cfg['timezone']}",
            "Open (sign-in required): {$url}",
        ]), 'chat-session:' . $session->id);
    }

    /**
     * The senior-support window opened: ONE Telegram alert per escalation (durable dedupe key). Minimal customer
     * data, no chat history, and a dashboard link that still requires staff sign-in and website authorisation.
     */
    public static function escalationRequested(Conversation $conversation, \App\Models\Escalation $escalation): void
    {
        $conversation->loadMissing(['property.tenant', 'visitor', 'customerAccount', 'department']);
        $property = $conversation->property;
        if (! $property) {
            return;
        }
        $cfg = BusinessHours::config($property);
        $email = $conversation->customerAccount?->email ?: $conversation->visitor?->email;
        $who = $email ? self::maskEmail($email) : 'Visitor — verification pending';
        $last = Message::where('conversation_id', $conversation->id)->where('sender_type', 'visitor')->orderByDesc('seq')->value('content');
        $excerpt = trim(preg_replace('/\s+/', ' ', (string) $last));
        $excerpt = $excerpt !== '' ? mb_substr($excerpt, 0, 160) . (mb_strlen($excerpt) > 160 ? '…' : '') : '—';
        $reason = str_replace('_', ' ', $escalation->reason);
        $deadline = $escalation->deadline_at->copy()->timezone($cfg['timezone'])->format('h:i:s A');
        $when = $escalation->escalated_at->copy()->timezone($cfg['timezone'])->format('h:i:s A');

        // Staff in-app notification: once per escalation (throttle against repeated calls); Telegram is deduplicated by key below.
        if (\Illuminate\Support\Facades\Cache::add('notify:escalation:' . $escalation->id, 1, 3600)) {
            self::toUsers(self::staffFor($property), 'human.requested', 'Senior support requested', "{$who} on {$property->site_name} is waiting (" . Escalation::WINDOW_SECONDS . ' s window).', ['conversation_id' => $conversation->id]);
        }
        self::telegram($property->tenant_id, 'human.requested', implode("\n", array_filter([
            '🚨 Senior support needed — customer is waiting',
            'Company: ' . ($property->tenant?->name ?? '—'),
            "Website: {$property->site_name}",
            'Chat: ' . ($conversation->chat_reference ?: '—'),
            'Department: ' . ($conversation->department?->name ?? 'General Support'),
            "Customer: {$who}",
            "Reason: {$reason}",
            "Question: {$excerpt}",
            "Escalated at {$when} · join before {$deadline} (" . Escalation::WINDOW_SECONDS . ' s window)',
            'Take over (sign-in required): ' . self::dashboardUrl($property->tenant_id, 'inbox', $conversation->id),
        ])), 'escalation:' . $escalation->id);
    }

    /** ra***@example.com: enough to recognise a customer, not enough to harvest addresses from a chat group. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return mb_substr($email, 0, 2) . '***';
        }

        return mb_substr($local, 0, 2) . '***@' . $domain;
    }
    public static function ticketAssigned(Ticket $ticket, User $assignee, User $by): void
    {
        $ticket->loadMissing(['property', 'visitor', 'customerAccount', 'conversation.customerAccount', 'conversation.visitor']);
        $email = self::customerEmailForTicket($ticket);
        $emailLine = $email ? "\n📧 Customer: {$email}" : '';

        self::toUsers(collect([$assignee]), 'ticket.assigned', "Ticket {$ticket->reference} assigned to you", "{$by->name} assigned: {$ticket->subject}", ['ticket_id' => $ticket->id]);
        self::telegram($ticket->tenant_id, 'ticket.assigned', "👤 {$ticket->reference} assigned to {$assignee->name} by {$by->name}{$emailLine}\nSubject: {$ticket->subject}");
    }

    public static function ticketReopened(Ticket $ticket): void
    {
        $ticket->loadMissing(['property', 'visitor', 'customerAccount', 'conversation.customerAccount', 'conversation.visitor']);
        $email = self::customerEmailForTicket($ticket);
        $emailLine = $email ? "\n📧 Customer: {$email}" : '';

        $users = $ticket->assigned_to ? User::whereKey($ticket->assigned_to)->get() : self::staffFor($ticket->property);
        self::toUsers($users, 'ticket.reopened', "Customer replied to {$ticket->reference}", $ticket->subject, ['ticket_id' => $ticket->id]);
        self::telegram($ticket->tenant_id, 'ticket.reopened', "↩️ Customer replied to resolved ticket {$ticket->reference} — back to the team.{$emailLine}\nSubject: {$ticket->subject}");
    }

    /** New conversation in a Sales department (or tagged as sales): alert + 30-working-minute watch. */
    public static function salesInquiry(Conversation $conversation): void
    {
        $conversation->loadMissing(['property', 'visitor', 'customerAccount', 'department']);
        $tenantId = $conversation->property->tenant_id;
        $email = $conversation->customerAccount?->email ?: $conversation->visitor?->email;
        $emailLine = $email ? "\n📧 Customer: {$email}" : '';
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
        self::telegram($tenantId, 'sales.inquiry', "💼 New sales enquiry on {$conversation->property->site_name}\nVisitor: " . ($conversation->visitor?->name ?: 'unknown') . "{$emailLine}\nOpen: " . self::dashboardUrl($tenantId, 'inbox', $conversation->id));
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
                $conversation = Conversation::with(['customerAccount', 'visitor'])->find($task->conversation_id);
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
                $email = $conversation->customerAccount?->email ?: $conversation->visitor?->email;
                $emailLine = $email ? "\n📧 Customer: {$email}" : '';
                self::telegram($task->tenant_id, 'sales.overdue', "⏰ Sales enquiry on {$task->property?->site_name} has waited more than 30 working minutes for a first human reply.{$emailLine}\nOpen: " . self::dashboardUrl($task->tenant_id, 'inbox', $conversation->id));
                self::toUsers(User::where('tenant_id', $task->tenant_id)->where('role', 'admin')->get(), 'sales.overdue', 'Sales enquiry waiting > 30 working minutes', $task->title, ['conversation_id' => $conversation->id]);
                $count++;
            });

        return $count;
    }

    /** 10:00 IST daily: one digest per staff member of their unresolved tickets and pending tasks. */
    public static function dailyDigest(): int
    {
        $sent = 0;
        $tickets = Ticket::whereIn('status', Ticket::UNRESOLVED)
            ->with(['customerAccount', 'visitor', 'conversation.customerAccount', 'conversation.visitor'])
            ->get()
            ->groupBy('tenant_id');
        foreach ($tickets as $tenantId => $list) {
            foreach ($list->groupBy(fn ($t) => $t->assigned_to ?? 'unassigned') as $owner => $own) {
                $users = $owner === 'unassigned'
                    ? User::where('tenant_id', $tenantId)->where('role', 'admin')->get()
                    : User::whereKey($owner)->get();
                $lines = $own->take(20)->map(fn ($t) => "{$t->reference} — {$t->subject}")->implode("\n");
                self::toUsers($users, 'tickets.digest', $own->count() . ' unresolved ticket(s)', $lines, ['ticket_ids' => $own->pluck('id')->all()]);
                $sent += $users->count();
            }
            $digestLines = $list->take(15)->map(function ($t) {
                $email = self::customerEmailForTicket($t);
                $line = "• {$t->reference} — {$t->subject}";
                if ($email) {
                    $line .= "\n  📧 {$email}";
                }

                return $line;
            })->implode("\n");

            self::telegram($tenantId, 'tickets.pending_digest', "📋 {$list->count()} unresolved ticket(s) at 10 AM:\n" . $digestLines);
            Ticket::whereIn('id', $list->pluck('id'))->update(['last_reminded_at' => now()]);
        }

        Task::where('status', 'pending')->whereNotNull('owner_id')->get()->groupBy('owner_id')->each(function ($tasks, $ownerId) use (&$sent) {
            self::toUsers(User::whereKey($ownerId)->get(), 'tasks.digest', $tasks->count() . ' pending follow-up task(s)', $tasks->take(20)->pluck('title')->implode("\n"), ['task_ids' => $tasks->pluck('id')->all()]);
            $sent++;
        });

        return $sent;
    }

    public const REMINDER_INTERVAL_MINUTES = 30;

    /** Dashboard link: the company's own address (/salute/inbox) when it has one, else /app/inbox. Sign-in is always required. */
    public static function dashboardUrl(?string $tenantId, string $view, ?string $id = null): string
    {
        static $slugs = [];
        $slug = $tenantId ? ($slugs[$tenantId] ??= (string) \App\Models\Tenant::whereKey($tenantId)->value('slug')) : '';

        return url(($slug !== '' ? '/' . $slug : '/app') . '/' . $view . ($id ? '?id=' . $id : ''));
    }

    /**
     * Telegram reminder every 30 minutes while a ticket waits for SUPPORT action (status open / waiting for team).
     * Ticket created 10:00 -> reminders 10:30, 11:00, 11:30 ... until it is resolved or closed.
     *
     * - Idempotent: ticket_reminders is unique on (ticket, pending cycle, sequence), and the Telegram event carries
     *   the same dedupe key, so cron overlaps or reruns never send the same reminder twice.
     * - A ticket waiting only for the customer is not reminded (config chatbox.reminders.include_waiting_customer).
     * - Reopened tickets begin a new cycle (TicketService::startPendingPeriodIfNeeded), counting from the reopen.
     * - If cron was down, only the latest due reminder is sent (no burst of stale alerts).
     */
    public static function checkPendingTicketReminders(): int
    {
        $statuses = ['open', 'pending_team'];
        if (config('chatbox.reminders.include_waiting_customer', false)) {
            $statuses[] = 'pending_customer';
        }
        $count = 0;
        Ticket::whereIn('status', $statuses)->whereNotNull('team_pending_since')
            ->where('team_pending_since', '<=', now()->subMinutes(self::REMINDER_INTERVAL_MINUTES))
            ->with(['property.tenant', 'department'])->orderBy('team_pending_since')->limit(300)->get()
            ->each(function (Ticket $t) use (&$count) {
                $sequence = intdiv((int) $t->team_pending_since->diffInMinutes(now()), self::REMINDER_INTERVAL_MINUTES);
                if ($sequence < 1) {
                    return;
                }
                try {
                    \Illuminate\Support\Facades\DB::table('ticket_reminders')->insert([
                        'ticket_id' => $t->id, 'cycle' => (int) $t->reminder_cycle, 'sequence' => $sequence, 'queued_at' => now(),
                    ]);
                } catch (\Illuminate\Database\QueryException) {
                    return; // this reminder period was already queued
                }
                $minutes = (int) $t->team_pending_since->diffInMinutes(now());
                self::telegram($t->tenant_id, 'tickets.reminders', implode("\n", [
                    '🚨 Pending Support Ticket',
                    '',
                    'Company: ' . ($t->property?->tenant?->name ?? '—'),
                    "Ticket: {$t->reference}",
                    "Website: {$t->property?->site_name}",
                    'Department: ' . ($t->department?->name ?? 'General Support'),
                    'Priority: ' . (Ticket::PRIORITIES[$t->priority] ?? ucfirst((string) $t->priority)),
                    'Status: Waiting for Team',
                    'Pending: ' . self::humanDuration($minutes),
                    '',
                    'Customer is still waiting for support.',
                    '',
                    'Open Ticket (sign-in required): ' . self::dashboardUrl($t->tenant_id, 'tickets', $t->id),
                ]), "ticket-reminder:{$t->id}:{$t->reminder_cycle}:{$sequence}");
                $count++;
            });

        return $count;
    }
    /** Retry failed Telegram deliveries with exponential backoff. */
    public static function retryFailedDeliveries(): int
    {
        $n = 0;
        NotificationDelivery::where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($p) => $p->where('status', 'pending')->where('created_at', '<=', now()->subMinute())))
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

    /**
     * Record the notification event for every subscribed channel, then try to deliver.
     *
     * The row is written first, so the event is durable even if Telegram is down or the process dies; failures are
     * stored with their error and retried by cron with back-off (up to MAX_ATTEMPTS). Delivery is NOT guaranteed:
     * only that the event is persisted and retried.
     *
     * $dedupeKey makes the call idempotent per channel (unique channel_id + dedupe_key): the same chat session,
     * escalation or ticket reminder is never queued twice, whatever retries, refreshes or reruns happen.
     */
    public static function telegram(string $tenantId, string $event, string $text, ?string $dedupeKey = null): void
    {
        $channels = NotificationChannel::where('tenant_id', $tenantId)->where('is_enabled', true)->get()
            ->filter(fn ($c) => in_array($event, $c->events ?? [], true));

        foreach ($channels as $channel) {
            try {
                $delivery = NotificationDelivery::create([
                    'tenant_id' => $tenantId,
                    'channel_id' => $channel->id,
                    'event' => $event,
                    'dedupe_key' => $dedupeKey,
                    'status' => 'pending',
                    'message' => mb_substr($text, 0, 3500),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                continue; // already recorded for this channel
            }
            if (app()->runningInConsole()) {
                self::deliver($delivery);
            } else {
                // cPanel friendly: sent right after the HTTP response; cron retries anything that did not go out.
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
