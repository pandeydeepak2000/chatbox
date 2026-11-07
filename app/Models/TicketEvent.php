<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable ticket history entry (status changes, assignment, AI pause/resume, reopen ...). */
class TicketEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'actor_type', 'actor_id', 'type', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
