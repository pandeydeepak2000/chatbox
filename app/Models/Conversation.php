<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'property_id',
        'visitor_id',
        'assigned_agent_id',
        'department_id',
        'status',
        'rating',
        'rating_feedback',
        'last_message_at',
        'closed_at',
        'transcript_emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'transcript_emailed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Scopes
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->where('status', 'unassigned')->orWhereNull('assigned_agent_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['unassigned', 'open', 'pending']);
    }

    public function scopeAssignedTo(Builder $query, string $agentId): Builder
    {
        return $query->where('assigned_agent_id', $agentId);
    }
}
