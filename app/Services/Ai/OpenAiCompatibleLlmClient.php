<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Support\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Chat Completions client for OpenAI, Google Gemini (OpenAI-compatible endpoint), OrvAPI and approved custom endpoints.
 *
 * One attempt per request (no automatic retries: no duplicate billing). The destination is validated at REQUEST time
 * (DNS, public IPs only, redirects disabled, vetted IP pinned), so a host that changes its DNS later cannot be used to
 * reach an internal address. Custom endpoints must be https.
 */
class OpenAiCompatibleLlmClient implements LlmClient
{
    public function complete(AiSetting $settings, string $system, array $messages, array $schema): LlmResult
    {
        $key = (string) $settings->api_key;
        $base = rtrim((string) ($settings->effectiveBaseUrl() ?: 'https://api.openai.com/v1'), '/');
        if ($key === '') {
            return LlmResult::failed('No API key configured.');
        }
        $url = $base . '/chat/completions';
        if ($settings->provider === 'openai_compatible' && ! str_starts_with(strtolower($url), 'https://')) {
            return LlmResult::failed('Custom providers must use https.');
        }
        $check = OutboundUrlGuard::check($url);
        if (! $check['ok']) {
            return LlmResult::failed('AI base URL rejected: ' . $check['reason']);
        }

        $instructions = $system . "\n\nRespond ONLY with a JSON object that matches this JSON schema (no prose, no code fences):\n" . json_encode($schema);
        $payload = [
            'model' => $settings->model,
            'messages' => array_merge([['role' => 'system', 'content' => $instructions]], $messages),
            'max_tokens' => max(256, min(8000, (int) $settings->max_output_tokens)),
        ];
        if ($settings->jsonModeSupported()) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $timeout = max(5, min(60, (int) ($settings->timeout_seconds ?: 25)));

        try {
            $res = Http::timeout($timeout)->withOptions(OutboundUrlGuard::httpOptions($check))->withToken($key)->asJson()->post($url, $payload);
        } catch (ConnectionException $e) {
            return LlmResult::failed('Could not reach the AI provider.', 0);
        } catch (\Throwable $e) {
            return LlmResult::failed('AI request failed.', 0);
        }
        if (! $res->successful()) {
            return LlmResult::failed('Provider error ' . $res->status(), $res->status());
        }
        $json = $res->json();
        $text = (string) ($json['choices'][0]['message']['content'] ?? '');
        $finish = $json['choices'][0]['finish_reason'] ?? null;
        $in = (int) ($json['usage']['prompt_tokens'] ?? 0);
        $out = (int) ($json['usage']['completion_tokens'] ?? 0);
        $cache = (int) ($json['usage']['prompt_tokens_details']['cached_tokens'] ?? 0);
        $data = self::extractJson($text);

        return is_array($data)
            ? new LlmResult($data, $in, $out, $cache, $finish, null, $json['model'] ?? $settings->model)
            : new LlmResult(null, $in, $out, $cache, $finish, 'Model did not return valid JSON.', $json['model'] ?? $settings->model);
    }

    /** Providers without a JSON mode may wrap the object in code fences or a sentence: take the first {...} block. */
    public static function extractJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $data = json_decode(substr($text, $a, $b - $a + 1), true);

            return is_array($data) ? $data : null;
        }

        return null;
    }
}