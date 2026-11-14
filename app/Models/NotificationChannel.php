<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A company's Telegram destination. The bot token is encrypted at rest and never returned to browsers. */
class NotificationChannel extends Model
{
    use HasUuids;

    public const EVENTS = [
        'chat.started' => 'Live chat started (customer waiting)',
        'ticket.created' => 'New ticket created',
        'human.requested' => 'Customer asked for senior department',
        'sales.inquiry' => 'New sales enquiry',
        'sales.overdue' => 'Sales enquiry waiting > 30 working minutes',
        'ticket.assigned' => 'Ticket assigned to staff',
        'ticket.reopened' => 'Customer replied to a resolved ticket',
        'tickets.pending_digest' => 'Daily 10 AM pending ticket digest',
        'tickets.reminders' => 'Pending ticket reminders (every 30 minutes)',
        'ai.budget' => 'AI budget alerts (80% / limit reached)',
    ];

    protected $fillable = ['tenant_id', 'type', 'name', 'bot_token', 'chat_id', 'events', 'is_enabled', 'last_success_at', 'last_error'];

    protected $hidden = ['bot_token'];

    protected function casts(): array
    {
        return ['bot_token' => 'encrypted', 'events' => 'array', 'is_enabled' => 'boolean', 'last_success_at' => 'datetime'];
    }

    public function toSafeArray(): array
    {
        $token = (string) $this->bot_token;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'chat_id' => $this->chat_id,
            'events' => $this->events ?? [],
            'is_enabled' => $this->is_enabled,
            'bot_token_hint' => $token !== '' ? '…' . substr($token, -4) : null,
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
