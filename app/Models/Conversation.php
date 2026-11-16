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
        'customer_account_id',
        'pre_verification',
        'handler',
        'support_state',
        'support_state_changed_at',
        'escalation_requested_at',
        'handover_at',
        'handover_by',
        'ai_resumed_at',
        'ai_resumed_by',
        'language',
        'clarifications_asked',
        'first_human_response_at',
        'ai_pending_since',
        'sales_alert_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'transcript_emailed_at' => 'datetime',
            'handover_at' => 'datetime',
            'support_state_changed_at' => 'datetime',
            'escalation_requested_at' => 'datetime',
            'ai_resumed_at' => 'datetime',
            'first_human_response_at' => 'datetime',
            'ai_pending_since' => 'datetime',
            'sales_alert_sent_at' => 'datetime',
            'pre_verification' => 'boolean',
            'last_seq' => 'integer',
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
        return $this->hasMany(Message::class)->orderBy('seq', 'asc')->orderBy('created_at', 'asc');
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function openTicket()
    {
        return $this->hasOne(Ticket::class, 'open_conversation_id');
    }

    public function isHumanHandled(): bool
    {
        return ($this->handler ?? 'ai') === 'human';
    }

    public function latestMessage()
    {
        // UUID keys are random, so "latest" must use the per-conversation sequence, not the id.
        return $this->hasOne(Message::class)->ofMany('seq', 'max');
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
