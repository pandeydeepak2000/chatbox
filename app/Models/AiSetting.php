<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-company AI provider configuration. The API key is encrypted and never returned to a browser. */
class AiSetting extends Model
{
    public const PROVIDERS = ['anthropic' => 'Anthropic (Claude)', 'openai_compatible' => 'OpenAI-compatible API'];
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
            'model' => $this->model,
            'effort' => $this->effort,
            'max_output_tokens' => $this->max_output_tokens,
            'instructions' => $this->instructions,
            'api_key_set' => $key !== '',
            'api_key_hint' => $key !== '' ? '…' . substr($key, -4) : null,
        ];
    }
}
