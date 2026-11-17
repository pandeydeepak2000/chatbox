<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-company AI provider configuration. The API key is encrypted and never returned to a browser. */
class AiSetting extends Model
{
    public const PROVIDERS = [
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'orvapi' => 'OrvAPI',
        'anthropic' => 'Anthropic (Claude)',
        'openai_compatible' => 'Custom Provider',
    ];

    /** Fixed endpoints of the named providers (the admin cannot point these at another host). */
    public const PRESET_BASE_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'orvapi' => 'https://api.orvapi.com/v1', // https://www.orvapi.com/docs/
    ];

    /** True for every provider spoken to through the OpenAI Chat Completions protocol. */
    public function usesOpenAiProtocol(): bool
    {
        return in_array($this->provider, ['openai', 'gemini', 'orvapi', 'openai_compatible'], true);
    }

    /**
     * Only send response_format where it is known to be accepted. OrvAPI's chat completions explicitly rejects it and
     * Gemini's OpenAI layer documents it only through SDK helpers, so those rely on the prompt + tolerant JSON parsing.
     */
    public function jsonModeSupported(): bool
    {
        return $this->provider === 'openai' || ($this->provider === 'openai_compatible' && (bool) $this->json_mode);
    }
    public function effectiveBaseUrl(): ?string
    {
        return self::PRESET_BASE_URLS[$this->provider] ?? $this->base_url;
    }
    public const EFFORTS = ['low', 'medium', 'high'];

    protected $fillable = ['tenant_id', 'enabled', 'provider', 'base_url', 'model', 'effort', 'api_key', 'max_output_tokens', 'instructions', 'provider_id', 'active_model_id', 'service_scope', 'timeout_seconds', 'json_mode'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'api_key' => 'encrypted', 'max_output_tokens' => 'integer', 'json_mode' => 'boolean', 'timeout_seconds' => 'integer'];
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
            'service_scope' => $this->service_scope,
            'provider_id' => $this->provider_id,
            'active_model_id' => $this->active_model_id,
            'timeout_seconds' => $this->timeout_seconds,
            'api_key_set' => $key !== '',
            'api_key_hint' => $key !== '' ? '…' . substr($key, -4) : null,
        ];
    }
}
