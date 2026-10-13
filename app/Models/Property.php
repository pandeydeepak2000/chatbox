<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Property extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'site_name',
        'site_url',
        'public_key',
        'secret_key',
        'allowed_domains',
        'widget_config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'widget_config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Property $property) {
            if (empty($property->public_key)) {
                $property->public_key = 'pk_live_' . Str::random(32);
            }
            if (empty($property->secret_key)) {
                $property->secret_key = 'sk_live_' . Str::random(40);
            }
            if (empty($property->widget_config)) {
                $property->widget_config = [
                    'primary_color' => '#4f46e5',
                    'welcome_message' => 'Hello! How can we help you today?',
                    'logo_url' => null,
                    'position' => 'right',
                    'require_email' => true,
                    'require_name' => true,
                ];
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function cannedResponses(): HasMany
    {
        return $this->hasMany(CannedResponse::class);
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class)->orderBy('order', 'asc');
    }

    /**
     * Check if a request origin/domain is allowed.
     */
    public function isOriginAllowed(?string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        $host = parse_url($origin, PHP_URL_HOST) ?: $origin;
        $host = strtolower(trim($host));

        $allowed = $this->allowed_domains ?? [];

        // If no domains are configured, allow host from site_url or localhost during local dev
        if (empty($allowed)) {
            $defaultHost = parse_url($this->site_url, PHP_URL_HOST);
            if ($defaultHost && strtolower($defaultHost) === $host) {
                return true;
            }
            if (app()->environment('local') && in_array($host, ['localhost', '127.0.0.1'])) {
                return true;
            }
            return false;
        }

        foreach ($allowed as $domain) {
            $domainTrimmed = trim($domain);
            if ($domainTrimmed === '*' || $domainTrimmed === '') {
                return true;
            }
            $normalized = strtolower(trim(parse_url($domainTrimmed, PHP_URL_HOST) ?: $domainTrimmed));
            if ($normalized === $host || str_ends_with($host, '.' . $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Regenerate API keys
     */
    public function regenerateKeys(): void
    {
        $this->update([
            'public_key' => 'pk_live_' . Str::random(32),
            'secret_key' => 'sk_live_' . Str::random(40),
        ]);
    }
}
