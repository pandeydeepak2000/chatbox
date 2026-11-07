<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Lead extends Model
{
    use HasUuids;

    public const STATUSES = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'interested' => 'Interested',
        'follow_up' => 'Follow-up',
        'converted' => 'Converted',
        'closed' => 'Closed',
    ];

    public const SERVICES = [
        'hosting' => 'Hosting',
        'domain' => 'Domain',
        'vps' => 'VPS',
        'dedicated' => 'Dedicated Server',
        'reseller' => 'Reseller Hosting',
        'email' => 'Email Hosting',
        'ssl' => 'SSL Certificate',
        'wordpress' => 'WordPress',
        'other' => 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'property_id', 'lead_number', 'visitor_id', 'name', 'email', 'email_normalized',
        'phone', 'website', 'interested_service', 'inquiry_summary', 'status', 'assigned_to',
        'source', 'last_interaction_at',
    ];

    protected $appends = ['reference'];

    protected function casts(): array
    {
        return [
            'lead_number' => 'integer',
            'last_interaction_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            if (empty($lead->lead_number)) {
                $lead->lead_number = self::nextNumber($lead->tenant_id);
            }
            if ($lead->email) {
                $lead->email_normalized = mb_strtolower(trim($lead->email));
            }
        });
        static::updating(function (Lead $lead) {
            if ($lead->isDirty('email')) {
                $lead->email_normalized = $lead->email ? mb_strtolower(trim($lead->email)) : null;
            }
        });
    }

    /** Per-company sequential number, allocated under a row lock (no duplicates under concurrency). */
    public static function nextNumber(string $tenantId): int
    {
        return DB::transaction(function () use ($tenantId) {
            $current = (int) DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('lead_counter');
            DB::table('tenants')->where('id', $tenantId)->update(['lead_counter' => $current + 1]);

            return $current + 1;
        });
    }

    public function getReferenceAttribute(): string
    {
        return 'L-' . str_pad((string) $this->lead_number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Leads the given staff member may see: own company only, and for website-restricted
     * staff only their assigned websites (enforced server-side for list, detail and export).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        $query->where('leads.tenant_id', $user->tenant_id);
        $restricted = $user->restrictedPropertyIds();
        if ($restricted !== null) {
            $query->whereIn('leads.property_id', $restricted);
        }

        return $query;
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'lead_conversation')->withPivot('created_at');
    }
}
