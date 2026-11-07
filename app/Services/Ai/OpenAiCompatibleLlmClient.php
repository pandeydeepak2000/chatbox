<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Support\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;

/**
 * Any OpenAI-compatible Chat Completions endpoint (base URL set by the company admin).
 * The base URL passes the SSRF guard and the vetted IP is pinned.
 */
class OpenAiCompatibleLlmClient implements LlmClient
{
    public function complete(AiSetting $settings, string $system, array $messages, array $schema): LlmResult
    {
        $key = (string) $settings->api_key;
        $base = rtrim((string) ($settings->base_url ?: 'https://api.openai.com/v1'), '/');
        if ($key === '') {
            return LlmResult::failed('No API key configured.');
        }
        $url = $base . '/chat/completions';
        $check = OutboundUrlGuard::check($url);
        if (! $check['ok']) {
            return LlmResult::failed('AI base URL rejected: ' . $check['reason']);
        }

        $instructions = $system . "\n\nRespond ONLY with a JSON object that matches this JSON schema:\n" . json_encode($schema);
        try {
            $res = Http::timeout(25)->withOptions(OutboundUrlGuard::httpOptions($check))->withToken($key)->asJson()->post($url, [
                'model' => $settings->model,
                'messages' => array_merge([['role' => 'system', 'content' => $instructions]], $messages),
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => max(256, (int) $settings->max_output_tokens),
            ]);
        } catch (\Throwable $e) {
            return LlmResult::failed('Could not reach the AI provider.');
        }
        if (! $res->successful()) {
            return LlmResult::failed('Provider error ' . $res->status());
        }
        $json = $res->json();
        $text = (string) ($json['choices'][0]['message']['content'] ?? '');
        $finish = $json['choices'][0]['finish_reason'] ?? null;
        $in = (int) ($json['usage']['prompt_tokens'] ?? 0);
        $out = (int) ($json['usage']['completion_tokens'] ?? 0);
        $cache = (int) ($json['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
        $data = json_decode($text, true);

        return is_array($data)
            ? new LlmResult($data, $in, $out, $cache, $finish, null, $json['model'] ?? $settings->model)
            : new LlmResult(null, $in, $out, $cache, $finish, 'Model did not return valid JSON.', $settings->model);
    }
}
