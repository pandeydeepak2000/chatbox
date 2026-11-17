<?php

namespace App\Services\Ai;

/** Provider-independent result of one structured LLM call. */
final class LlmResult
{
    public function __construct(
        public readonly ?array $data,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly ?string $stopReason = null,
        public readonly ?string $error = null,
        public readonly ?string $model = null,
        /** HTTP status of a failed provider call (0 = unreachable/timeout); lets the admin UI tell bad key / bad model / outage apart. */
        public readonly ?int $status = null,
        /** auth | model | rate | null: derived from the status and keywords of the provider answer, which is then discarded. */
        public readonly ?string $kind = null,
    ) {
    }

    public static function failed(string $error, ?int $status = null, ?string $kind = null): self
    {
        return new self(null, error: $error, status: $status, kind: $kind);
    }

    /**
     * Classify a failed provider answer without keeping or relaying it. Needed because providers disagree: OpenAI,
     * OrvAPI and Anthropic answer a bad key with 401, Google Gemini with 400 "Please pass a valid API key".
     */
    public static function kindOf(int $status, string $body): ?string
    {
        $b = strtolower(mb_substr($body, 0, 2000));
        if (in_array($status, [401, 403], true) || str_contains($b, 'api key') || str_contains($b, 'api_key') || str_contains($b, 'invalid x-api-key')) {
            return 'auth';
        }
        if ($status === 429) {
            return 'rate';
        }
        if ($status === 404 || preg_match('/model[^"]{0,60}(not found|does not exist|not supported|unknown|not available)|unknown model|invalid model|no such model/', $b)) {
            return 'model';
        }

        return null;
    }

    public function ok(): bool
    {
        return $this->error === null && $this->data !== null;
    }
}
