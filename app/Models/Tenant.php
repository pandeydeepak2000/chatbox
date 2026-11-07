<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'email',
        'plan',
        'status',
        'settings',
        'owner_user_id',
        'timezone',
        'ticket_prefix',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'admin');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'agent');
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** Company setting helper, e.g. setting('sales_followup_user_id'). */
    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
