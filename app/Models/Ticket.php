<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasUuids;

    public const STATUSES = [
        'open' => 'Open',
        'pending_team' => 'Waiting for team',
        'pending_customer' => 'Waiting for customer',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];
    public const UNRESOLVED = ['open', 'pending_team', 'pending_customer'];
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
    public const CATEGORIES = [
        'general' => 'General', 'technical' => 'Technical', 'billing' => 'Billing', 'sales' => 'Sales',
        'domain' => 'Domains', 'hosting' => 'Hosting', 'account' => 'Account', 'other' => 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'property_id', 'number', 'reference', 'conversation_id', 'open_conversation_id',
        'customer_account_id', 'visitor_id', 'department_id', 'assigned_to', 'status', 'priority', 'category',
        'subject', 'summary', 'source', 'idempotency_key', 'first_response_at', 'resolved_at', 'resolved_by',
        'last_customer_reply_at', 'last_staff_reply_at', 'last_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'number' => 'integer',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_customer_reply_at' => 'datetime',
            'last_staff_reply_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function isUnresolved(): bool
    {
        return in_array($this->status, self::UNRESOLVED, true);
    }

    /**
     * Tickets a staff member may open: own company; website-restricted staff only their websites;
     * plus any ticket they are or were assigned to (previous owners keep visibility).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }
        $query->where('tickets.tenant_id', $user->tenant_id);
        $restricted = $user->restrictedPropertyIds();
        if ($restricted !== null) {
            $query->where(function ($q) use ($restricted, $user) {
                $q->whereIn('tickets.property_id', $restricted)
                    ->orWhereIn('tickets.id', TicketAssignment::where('user_id', $user->id)->select('ticket_id'));
            });
        }

        return $query;
    }

    /** What the customer may see in the widget (no internal fields). */
    public function toCustomerArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'conversation_id' => $this->conversation_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class)->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class)->orderBy('id');
    }
}
