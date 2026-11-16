<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-company AI provider configuration. The API key is encrypted and never returned to a browser. */
class AiSetting extends Model
{
    public const PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI (official API)',
        'orvapi' => 'OrvAPI',
        'openai_compatible' => 'Other OpenAI-compatible API',
    ];

    /** Fixed endpoints of the named providers (the admin cannot point these at another host). */
    public const PRESET_BASE_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'orvapi' => 'https://api.orvapi.com/v1', // from https://www.orvapi.com/docs/ (checked 2026-11-16)
    ];

    /**
     * Model ids listed on https://www.orvapi.com/Model (checked 2026-11-16). Suggestions only: live availability
     * (Active / Unavailable, paid-plan-only) is shown on that page and can change, so the admin types or picks one
     * and uses "Test connection".
     */
    public const ORVAPI_MODEL_SUGGESTIONS = [
        'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-4-5', 'gpt-5.5', 'gpt-5.4-mini', 'deepseek-v4-flash', 'deepseek-v4-pro',
        'gemini-3.5-flash-lite', 'gemini-3.1-pro', 'glm-5.3-flash', 'grok-4.7', 'kimi-k3', 'qwen3.8-flash', 'qwen-plus',
    ];

    /** True for every provider spoken to through the OpenAI Chat Completions protocol. */
    public function usesOpenAiProtocol(): bool
    {
        return in_array($this->provider, ['openai', 'orvapi', 'openai_compatible'], true);
    }

    public function effectiveBaseUrl(): ?string
    {
        return self::PRESET_BASE_URLS[$this->provider] ?? $this->base_url;
    }
    public const EFFORTS = ['low', 'medium', 'high'];

    protected $fillable = ['tenant_id', 'enabled', 'provider', 'base_url', 'model', 'effort', 'api_key', 'max_output_tokens', 'instructions'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'api_key' => 'encrypted', 'max_output_tokens' => 'integer'];
    }

    public static function forTenant(string $tenantId): self
    {
        return self::firstOrCreate(['tenant_id' => $tenantId], ['enabled' => false, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'low', 'max_output_tokens' => 1500]);
    }

    public function toSafeArray(): array
    {
        $key = (string) $this->api_key;

        return [
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'base_url' => $this->base_url,
            'effective_base_url' => $this->effectiveBaseUrl(),
            'model' => $this->model,
            'effort' => $this->effort,
            'max_output_tokens' => $this->max_output_tokens,
            'instructions' => $this->instructions,
            'api_key_set' => $key !== '',
            'api_key_hint' => $key !== '' ? '…' . substr($key, -4) : null,
        ];
    }
}
