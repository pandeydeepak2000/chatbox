<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A real 60-second opportunity for a team member to join (deadline_at is authoritative, server side). */
class Escalation extends Model
{
    use HasUuids;

    public const WINDOW_SECONDS = 60;

    protected $fillable = [
        'tenant_id', 'property_id', 'conversation_id', 'reason', 'ticket_options', 'escalated_at', 'deadline_at', 'status',
        'open_key', 'joined_by', 'joined_at', 'ticket_id', 'finalized_at', 'finalized_via',
    ];

    protected function casts(): array
    {
        return [
            'ticket_options' => 'array',
            'escalated_at' => 'datetime',
            'deadline_at' => 'datetime',
            'joined_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    /** Seconds left, always computed from the server clock and the stored deadline (never from a client). */
    public function remainingSeconds(): int
    {
        if (! $this->isWaiting()) {
            return 0;
        }

        return max(0, (int) ceil(now()->floatDiffInSeconds($this->deadline_at, false)));
    }

    public function isDue(): bool
    {
        return $this->isWaiting() && $this->deadline_at->lte(now());
    }
}
