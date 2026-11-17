<?php

namespace App\Models;

use App\Services\Ai\AiProviderCatalog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One company's connection to one AI provider (own encrypted key). The key is write-only: it is never part of any
 * array/JSON the application returns (see $hidden and toSafeArray()).
 */
class AiProvider extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'slug', 'type', 'name', 'base_url', 'models_path', 'api_key', 'timeout_seconds', 'json_mode', 'enabled',
        'approval_status', 'approval_note', 'approved_by', 'approved_at', 'connection_status', 'connection_message', 'connection_checked_at',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
            'json_mode' => 'boolean',
            'timeout_seconds' => 'integer',
            'approved_at' => 'datetime',
            'connection_checked_at' => 'datetime',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }

    public function isCustom(): bool
    {
        return $this->type === 'custom';
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->approval_status === 'approved' && (string) $this->api_key !== '';
    }

    /** The value stored in ai_settings.provider for this provider. */
    public function settingProvider(): string
    {
        return AiProviderCatalog::settingProvider($this->type);
    }

    public function host(): ?string
    {
        return $this->base_url ? strtolower((string) parse_url($this->base_url, PHP_URL_HOST)) : null;
    }

    public function toSafeArray(): array
    {
        $key = (string) $this->api_key;
        $meta = AiProviderCatalog::TYPES[$this->type] ?? [];

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'type' => $this->type,
            'name' => $this->name,
            'label' => $this->isCustom() ? $this->name : ($meta['label'] ?? $this->name),
            'subtitle' => $meta['subtitle'] ?? '',
            'base_url' => $this->isCustom() ? $this->base_url : ($meta['base_url'] ?? null),
            'models_path' => $this->models_path,
            'timeout_seconds' => $this->timeout_seconds,
            'json_mode' => $this->json_mode,
            'enabled' => $this->enabled,
            'approval_status' => $this->approval_status,
            'approval_note' => $this->approval_note,
            'connection_status' => $this->connection_status,
            'connection_message' => $this->connection_message,
            'connection_checked_at' => $this->connection_checked_at?->toIso8601String(),
            'api_key_set' => $key !== '',
            'api_key_hint' => $key !== '' ? '…' . substr($key, -4) : null,
        ];
    }
}
