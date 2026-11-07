<?php

namespace App\Services\Ai;

use App\Models\AiSetting;

interface LlmClient
{
    /**
     * One request that must return a JSON object matching $schema.
     *
     * @param  array<int, array{role: string, content: string}>  $messages  user/assistant turns only
     */
    public function complete(AiSetting $settings, string $system, array $messages, array $schema): LlmResult;
}
