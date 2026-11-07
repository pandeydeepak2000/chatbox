<?php

namespace App\Services;

use App\Models\KnowledgeDocument;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan limits per company (SaaS foundation). A company without a subscription row is treated
 * as unlimited so internal installs are never blocked by missing billing data.
 */
class PlanLimits
{
    public const KEYS = ['websites', 'staff', 'conversations_per_month', 'knowledge_documents', 'ai_monthly_usd'];

    public static function limits(string $tenantId): array
    {
        $row = DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.tenant_id', $tenantId)->first(['plans.limits', 'plans.code', 'plans.name', 'subscriptions.status']);
        if (! $row) {
            return ['plan' => null, 'status' => null, 'limits' => array_fill_keys(self::KEYS, null)];
        }

        return ['plan' => ['code' => $row->code, 'name' => $row->name], 'status' => $row->status, 'limits' => array_merge(array_fill_keys(self::KEYS, null), json_decode($row->limits, true) ?: [])];
    }

    public static function usage(string $tenantId): array
    {
        return [
            'websites' => Property::where('tenant_id', $tenantId)->count(),
            'staff' => User::where('tenant_id', $tenantId)->count(),
            'conversations_per_month' => DB::table('conversations')->join('properties', 'properties.id', '=', 'conversations.property_id')
                ->where('properties.tenant_id', $tenantId)->where('conversations.created_at', '>=', now()->startOfMonth())->count(),
            'knowledge_documents' => KnowledgeDocument::where('tenant_id', $tenantId)->where('status', '!=', 'archived')->count(),
            'ai_monthly_usd' => round(\App\Services\Ai\AiBudgetService::spent($tenantId, 'm:' . now()->format('Y-m')), 2),
        ];
    }

    /** Throws a validation error when adding one more item would exceed the plan. */
    /**
     * Security (plan-limit race): check the limit and create the item while holding a short
     * per-company lock, so two parallel requests cannot both pass the check and exceed the plan.
     *
     * @template T
     * @param  \Closure(): T  $create
     * @return T
     */
    public static function withCapacity(?string $tenantId, string $key, \Closure $create): mixed
    {
        if (! $tenantId) {
            return $create();
        }
        $lock = \Illuminate\Support\Facades\Cache::lock("plan-add:{$tenantId}:{$key}", 20);
        if (! $lock->block(10)) {
            throw ValidationException::withMessages(['plan' => ['Another change is being saved. Please try again.']]);
        }
        try {
            self::assertCanAdd($tenantId, $key);

            return $create();
        } finally {
            $lock->release();
        }
    }

    public static function assertCanAdd(?string $tenantId, string $key): void
    {
        if (! $tenantId) {
            return;
        }
        $info = self::limits($tenantId);
        if ($info['status'] === 'cancelled') {
            throw ValidationException::withMessages(['plan' => ['This workspace subscription is cancelled. Please contact the platform administrator.']]);
        }
        $limit = $info['limits'][$key] ?? null;
        if ($limit === null) {
            return;
        }
        if ((self::usage($tenantId)[$key] ?? 0) + 1 > $limit) {
            $label = str_replace('_', ' ', $key);
            throw ValidationException::withMessages(['plan' => ["Your {$info['plan']['name']} plan allows {$limit} {$label}. Please upgrade to add more."]]);
        }
    }
}
