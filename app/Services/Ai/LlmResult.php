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
    ) {
    }

    public static function failed(string $error, ?int $status = null): self
    {
        return new self(null, error: $error, status: $status);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->data !== null;
    }
}
