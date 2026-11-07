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
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(null, error: $error);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->data !== null;
    }
}
