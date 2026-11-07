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
        'slug',
        'identity_mode',
        'site_url',
        'public_key',
        'secret_key',
        'allowed_domains',
        'widget_config',
        'webhook_secret',
        'is_active',
    ];

    /** Never serialised by default (audit H5 / C3). Admin-only views add what they need explicitly. */
    protected $hidden = ['secret_key', 'webhook_secret'];

    /** widget_config keys that must never leave the server in a public (widget) response. */
    private const PRIVATE_CONFIG_KEYS = ['webhook_url', 'webhook_secret', 'webhook_events'];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'widget_config' => 'array',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Strip the legacy webhook secret from widget_config in every serialisation.
     * Staff see only whether a secret is configured.
     */
    public function toArray()
    {
        $data = parent::toArray();
        if (isset($data['widget_config']) && is_array($data['widget_config'])) {
            unset($data['widget_config']['webhook_secret'], $data['widget_config']['webhook_url'], $data['widget_config']['webhook_events']);
            $data['widget_config']['webhook_secret_set'] = $this->effectiveWebhookSecret() !== null;
        }

        return $data;
    }

    /** Serialisation for a staff member: admins also get secret_key and webhook settings. */
    public function toArrayFor(?User $viewer): array
    {
        $data = $this->toArray();
        if ($viewer && ($viewer->isAdmin() || $viewer->isSuperAdmin())) {
            $data['secret_key'] = $this->secret_key;
            $config = $this->widget_config ?? [];
            if (isset($data['widget_config'])) {
                $data['widget_config']['webhook_url'] = $config['webhook_url'] ?? null;
                if (isset($config['webhook_events'])) {
                    $data['widget_config']['webhook_events'] = $config['webhook_events'];
                }
            }
        }

        return $data;
    }

    /** widget_config as safe for anonymous website visitors. */
    /**
     * Widget lookup by public key (security F7 + F5): the website must be active, its company must
     * not be suspended, and the key must match exactly (MySQL compares case-insensitively).
     */
    public static function activeByPublicKey(string $key): ?self
    {
        if ($key === '' || strlen($key) > 100) {
            return null;
        }
        $property = \Illuminate\Support\Facades\Cache::remember("prop_pub_{$key}", 120, fn () => self::where('public_key', $key)
            ->where('is_active', true)
            ->whereHas('tenant', fn ($t) => $t->where('status', 'active'))
            ->first());

        return $property && hash_equals((string) $property->public_key, $key) ? $property : null;
    }

    public function publicWidgetConfig(): array
    {
        $config = $this->widget_config ?? [];
        foreach (array_keys($config) as $key) {
            if (in_array($key, self::PRIVATE_CONFIG_KEYS, true) || preg_match('/secret|token|password|api_?key/i', (string) $key)) {
                unset($config[$key]);
            }
        }

        return $config;
    }

    /** Secret from the dedicated encrypted column, falling back to the legacy widget_config value. */
    public function effectiveWebhookSecret(): ?string
    {
        $secret = $this->webhook_secret ?: (($this->widget_config ?? [])['webhook_secret'] ?? null);

        return $secret !== '' ? $secret : null;
    }

    protected static function booted(): void
    {
        static::creating(function (Property $property) {
            if (empty($property->slug)) {
                $property->slug = self::uniqueSlug($property->site_name ?: 'website');
            }
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

    /** Paths that can never be a website admin slug (they are application routes). */
    public const RESERVED_SLUGS = ['admin', 'api', 'app', 'widget', 'login', 'register', 'platform', 'dashboard', 'workspace', 'demo', 'up', 'storage', 'uploads', 'public', 'assets', 'static', '_next', 'widget-js', 'favicon-ico', 'robots-txt'];

    public static function slugProblem(string $slug, ?string $ignoreId = null): ?string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug)) {
            return 'Use 3–40 lowercase letters, numbers or hyphens (not at the start or end).';
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return 'This address is reserved. Please choose another.';
        }
        if (self::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            return 'This address is already used by another website.';
        }

        return null;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = substr(Str::slug($name) ?: 'website', 0, 34);
        if (strlen($base) < 3 || in_array($base, self::RESERVED_SLUGS, true)) {
            $base = 'site-' . $base;
        }
        $slug = $base;
        $i = 2;
        while (self::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /** Website-specific admin login URL, e.g. https://chat.example.com/website-a/admin */
    public function adminUrl(): string
    {
        return url('/' . $this->slug . '/admin');
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
            $domainTrimmed = trim((string) $domain);
            if ($domainTrimmed === '') {
                continue;
            }
            if ($domainTrimmed === '*') {
                // Explicit opt-in by an admin: any website may embed this widget.
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
