<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\AiSetting;

/**
 * Claude via the official Anthropic PHP SDK (anthropic-ai/sdk).
 *  - structured outputs (output_config.format json_schema) for reliable JSON
 *  - effort is the cost/latency control (thinking stays at the model default)
 *  - server-side refusal fallback "default" (beta) is enabled; a remaining refusal escalates to staff
 *  - the stable system prompt is marked for prompt caching (it only takes effect above the model's
 *    minimum cacheable prefix length and is harmless below it)
 */
class AnthropicLlmClient implements LlmClient
{
    public function complete(AiSetting $settings, string $system, array $messages, array $schema): LlmResult
    {
        $key = (string) $settings->api_key;
        if ($key === '') {
            return LlmResult::failed('No API key configured.');
        }

        try {
            $client = new Client(apiKey: $key, requestOptions: ['timeout' => (float) max(5, min(60, (int) ($settings->timeout_seconds ?: 25))), 'maxRetries' => 0]);
            $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
            if (preg_match('/^claude-(opus|sonnet|haiku|fable)-5/', (string) $settings->model)) {
                $outputConfig['effort'] = $settings->effort ?: 'low';
            }

            $message = $client->beta->messages->create(
                maxTokens: max(256, (int) $settings->max_output_tokens),
                messages: $messages,
                model: $settings->model,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                outputConfig: $outputConfig,
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );

            $usage = $message->usage;
            $in = (int) $usage->inputTokens + (int) ($usage->cacheCreationInputTokens ?? 0);
            $out = (int) $usage->outputTokens;
            $cache = (int) ($usage->cacheReadInputTokens ?? 0);

            if ($message->stopReason === 'refusal') {
                return new LlmResult(null, $in, $out, $cache, 'refusal', 'The model declined this request.', $message->model);
            }
            if ($message->stopReason === 'max_tokens') {
                return new LlmResult(null, $in, $out, $cache, 'max_tokens', 'Response was cut off (max tokens).', $message->model);
            }

            $text = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }
            $data = json_decode($text, true);

            return is_array($data)
                ? new LlmResult($data, $in, $out, $cache, $message->stopReason, null, $message->model)
                : new LlmResult(null, $in, $out, $cache, $message->stopReason, 'Model did not return valid JSON.', $message->model);
        } catch (APIStatusException $e) {
            return LlmResult::failed('Provider error ' . $e->getCode() . ' (' . ($e->type?->value ?? 'api_error') . ')', (int) $e->getCode());
        } catch (APIConnectionException $e) {
            return LlmResult::failed('Could not reach the AI provider.', 0);
        } catch (\Throwable $e) {
            return LlmResult::failed('AI request failed: ' . mb_substr(str_replace($key, '[key]', $e->getMessage()), 0, 200));
        }
    }
}
