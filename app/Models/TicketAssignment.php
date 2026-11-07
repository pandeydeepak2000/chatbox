<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ownership history: previous and current assignees keep visibility; current = unassigned_at NULL. */
class TicketAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id', 'assigned_by', 'assigned_at', 'unassigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
