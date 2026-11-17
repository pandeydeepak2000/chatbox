<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    /** Views reachable at /{company}/{view}. A company address can never equal one of them (or an application route). */
    public const DASHBOARD_VIEWS = ['overview', 'inbox', 'tickets', 'leads', 'customers', 'knowledge', 'ai', 'aireview', 'websites', 'team', 'notifications', 'analytics', 'company'];

    protected $fillable = [
        'name',
        'slug',
        'email',
        'plan',
        'status',
        'settings',
        'owner_user_id',
        'timezone',
        'ticket_prefix',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $t) {
            if (empty($t->slug)) {
                $t->slug = self::uniqueSlug((string) $t->name);
            }
        });
    }

    /** null when the address is acceptable, else the reason (shown to the administrator). */
    public static function slugProblem(string $slug, ?string $ignoreId = null): ?string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug)) {
            return 'Use 3–40 lowercase letters, numbers or hyphens (not at the start or end).';
        }
        if (in_array($slug, array_merge(Property::RESERVED_SLUGS, self::DASHBOARD_VIEWS, ['legacy-dashboard']), true)) {
            return 'This address is reserved. Please choose another.';
        }
        if (self::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            return 'This address is already used by another company.';
        }

        return null;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = substr(\Illuminate\Support\Str::slug($name) ?: 'company', 0, 34);
        $base = strlen($base) < 3 ? str_pad($base, 3, 'x') : $base;
        $slug = $base;
        for ($i = 2; self::slugProblem($slug) !== null; $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

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
