<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visitor extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'property_id',
        'visitor_token',
        'name',
        'email',
        'phone',
        'ip_address',
        'city',
        'country_code',
        'browser',
        'os',
        'current_page_url',
        'last_seen_at',
        'is_blocked',
        'metadata',
    ];

    protected $appends = [
        'is_online',
        'last_seen_human',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'is_blocked' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Real-time Visitor Presence (Active within last 3 minutes).
     */
    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(3));
    }

    /**
     * Human readable last seen format.
     */
    public function getLastSeenHumanAttribute(): string
    {
        if (! $this->last_seen_at) {
            return 'Never';
        }
        return $this->last_seen_at->diffForHumans();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function activeConversation()
    {
        return $this->conversations()
            ->whereIn('status', ['unassigned', 'open', 'pending'])
            ->latest('last_message_at')
            ->first();
    }
}
