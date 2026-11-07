<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Staff follow-up task. Completing a task does not resolve a ticket or mark a sale as won. */
class Task extends Model
{
    use HasUuids;

    public const TYPES = [
        'quotation_followup' => 'Quotation follow-up',
        'sales_first_response' => 'Sales enquiry awaiting first reply',
        'ticket_followup' => 'Ticket follow-up',
        'manual' => 'Task',
    ];

    protected $fillable = ['tenant_id', 'property_id', 'type', 'title', 'conversation_id', 'ticket_id', 'owner_id', 'status', 'due_at', 'completed_at', 'completed_by', 'created_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(TaskReminder::class)->orderBy('remind_at');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
