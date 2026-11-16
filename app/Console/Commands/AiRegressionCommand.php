<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Ai\AnswerReviewService;
use Illuminate\Console\Command;

/** Retrieval regression for every company's approved corrections. No AI calls, no cost. Safe for cPanel cron. */
class AiRegressionCommand extends Command
{
    protected $signature = 'chatbox:ai-regression {--tenant= : Only this company id}';

    protected $description = 'Check that every approved correction is still found for its question';

    public function handle(): int
    {
        $failed = 0;
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))->get(['id', 'name']);
        foreach ($tenants as $tenant) {
            $r = AnswerReviewService::runRegression($tenant->id);
            if ($r['total'] === 0) {
                continue;
            }
            $this->line("{$tenant->name}: {$r['passed']}/{$r['total']} passed");
            foreach ($r['failures'] as $f) {
                $this->error('  FAIL: ' . mb_substr($f['question'], 0, 100));
            }
            $failed += $r['failed'];
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
