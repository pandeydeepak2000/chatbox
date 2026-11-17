<?php

namespace App\Services\Ai;

/**
 * What each provider type is and can do. One protocol engine per wire format is reused (OpenAI chat completions,
 * Anthropic SDK); nothing here is a second engine. Capabilities are explicit because "OpenAI-compatible" is not one
 * contract: for example OrvAPI's chat completions rejects response_format (per https://www.orvapi.com/docs/), so the
 * client only sends it where it is known to work.
 */
final class AiProviderCatalog
{
    /** Documentation each integration was written against (checked 2026-11-17). */
    public const TYPES = [
        'openai' => [
            'label' => 'OpenAI', 'subtitle' => 'Official OpenAI API', 'protocol' => 'openai',
            'base_url' => 'https://api.openai.com/v1', 'json_mode' => true, 'models_path' => '/models',
            'docs' => 'https://platform.openai.com/docs/api-reference',
        ],
        'gemini' => [
            'label' => 'Google Gemini', 'subtitle' => 'Official Google Gemini API', 'protocol' => 'openai',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'json_mode' => false, 'models_path' => '/models',
            'strip_model_prefix' => 'models/',
            'docs' => 'https://aistudio.google.com/docs/openai',
        ],
        'orvapi' => [
            'label' => 'OrvAPI', 'subtitle' => 'OrvAPI gateway', 'protocol' => 'openai',
            'base_url' => 'https://api.orvapi.com/v1', 'json_mode' => false, 'models_path' => '/models',
            'docs' => 'https://www.orvapi.com/docs/',
        ],
        'anthropic' => [
            'label' => 'Anthropic (Claude)', 'subtitle' => 'Official Anthropic API', 'protocol' => 'anthropic',
            'base_url' => 'https://api.anthropic.com', 'json_mode' => false, 'models_path' => '/v1/models',
            'docs' => 'https://platform.claude.com/docs/en/api/models-list',
        ],
        'custom' => [
            'label' => 'Custom Provider', 'subtitle' => 'Administrator-approved OpenAI-compatible API', 'protocol' => 'openai',
            'base_url' => null, 'json_mode' => false, 'models_path' => null,
        ],
    ];

    /** Types a company configures with just a key (fixed official endpoint). */
    public const NAMED = ['openai', 'gemini', 'orvapi', 'anthropic'];

    /** Version header of the Anthropic REST API used for the model list. */
    public const ANTHROPIC_VERSION = '2023-06-01';

    /** The value stored in ai_settings.provider (the engine switch). */
    public static function settingProvider(string $type): string
    {
        return $type === 'custom' ? 'openai_compatible' : $type;
    }

    public static function typeFromSettingProvider(string $provider): string
    {
        return $provider === 'openai_compatible' ? 'custom' : $provider;
    }

    public static function isNamed(string $type): bool
    {
        return in_array($type, self::NAMED, true);
    }

    /** Dropdown entries for the dashboard, in the required order. */
    public static function options(): array
    {
        return collect(['openai', 'gemini', 'orvapi', 'anthropic', 'custom'])->map(fn ($t) => [
            'type' => $t, 'label' => self::TYPES[$t]['label'], 'subtitle' => self::TYPES[$t]['subtitle'], 'base_url' => self::TYPES[$t]['base_url'],
            'docs' => self::TYPES[$t]['docs'] ?? null,
        ])->all();
    }
}
