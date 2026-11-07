<?php

namespace App\Jobs;

use App\Models\KnowledgeVersion;
use App\Services\Knowledge\KnowledgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Extracts text from an uploaded knowledge file. Runs on the database queue (cPanel cron worker). */
class ProcessKnowledgeVersion implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 45;

    public function __construct(public string $versionId)
    {
    }

    public function handle(): void
    {
        $version = KnowledgeVersion::with('document')->find($this->versionId);
        if ($version && $version->status === 'processing') {
            KnowledgeService::process($version);
        }
    }
}
