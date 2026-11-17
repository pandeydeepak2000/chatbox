<?php

namespace App\Services\Ai;

use App\Models\AiBudget;
use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\Message;
use App\Services\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * AI spending control (Phase 2 §6.5):
 *  - hard monthly and daily caps per company, checked BEFORE every call against an estimate
 *  - per-visitor daily AI reply limit
 *  - one 80% alert per month and one "limit reached" alert per day/month (dashboard + Telegram)
 *  - when blocked, the bot falls back to FAQ answers and human support (never silent failure)
 *  - a model without a price row is not called: spending could not be controlled
 */
class AiBudgetService
{
    /** Rough upper estimate for one support reply (input with knowledge + output). */
    private const EST_INPUT_TOKENS = 4000;

    /**
     * The price used for cost tracking and budget caps: the rate the COMPANY administrator confirmed for this model,
     * else the platform price table. No price = AI stays off (spend could not be controlled); nothing is invented.
     */
    public static function price(AiSetting $s): ?AiModelPrice
    {
        $own = \App\Models\AiModel::where('tenant_id', $s->tenant_id)->where('provider_key', $s->provider)->where('model_id', $s->model)
            ->whereNotNull('input_per_million')->whereNotNull('output_per_million')->first();
        if ($own) {
            return new AiModelPrice(['provider' => $s->provider, 'model' => $s->model, 'input_per_million' => $own->input_per_million, 'output_per_million' => $own->output_per_million]);
        }

        return AiModelPrice::where('provider', $s->provider)->where('model', $s->model)->first();
    }

    /** @return string|null reason the AI may not be used now, or null if allowed */
    public static function blockReason(AiSetting $s, ?string $visitorId): ?string
    {
        return self::blockReasonFor($s, $visitorId, null);
    }

    private static function blockReasonFor(AiSetting $s, ?string $visitorId, ?AiBudget $lockedBudget): ?string
    {
        if (! $s->enabled) {
            return 'disabled';
        }
        if ((string) $s->api_key === '') {
            return 'no_key';
        }
        if ($s->provider_id && ($p = \App\Models\AiProvider::find($s->provider_id))) {
            if (! $p->enabled) {
                return 'provider_disabled';
            }
            if ($p->approval_status !== 'approved') {
                return 'provider_not_approved';
            }
        }
        if ($s->active_model_id && ($m = \App\Models\AiModel::find($s->active_model_id)) && ! $m->enabled) {
            return 'model_disabled';
        }
        $price = self::price($s);
        if (! $price) {
            return 'no_price';
        }
        $budget = $lockedBudget ?? AiBudget::forTenant($s->tenant_id);
        $estimate = self::cost($price, self::EST_INPUT_TOKENS, (int) $s->max_output_tokens);

        $planCap = \App\Services\PlanLimits::limits($s->tenant_id)['limits']['ai_monthly_usd'] ?? null;
        $monthlyCap = $planCap === null ? $budget->monthly_cap_usd : min($budget->monthly_cap_usd, (float) $planCap);
        $reserved = self::activeReservation($budget);
        if (self::spent($s->tenant_id, 'm:' . now()->format('Y-m')) + $reserved + $estimate > $monthlyCap) {
            self::limitReached($budget, 'month');

            return 'budget_month';
        }
        if (self::spent($s->tenant_id, 'd:' . now()->format('Y-m-d')) + $reserved + $estimate > $budget->daily_cap_usd) {
            self::limitReached($budget, 'day');

            return 'budget_day';
        }
        if ($visitorId) {
            $today = Message::query()
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.visitor_id', $visitorId)
                ->where('messages.sender_type', 'bot')
                ->where('messages.metadata->ai', true)
                ->where('messages.created_at', '>=', now()->startOfDay())
                ->count();
            if ($today >= $budget->per_visitor_daily_messages) {
                return 'visitor_limit';
            }
        }

        return null;
    }

    /** Money reserved by AI calls that are still running (expired reservations count as zero). */
    private static function activeReservation(AiBudget $budget): float
    {
        return $budget->reserved_until && $budget->reserved_until->isFuture() ? (float) $budget->reserved_usd : 0.0;
    }

    /**
     * Security S12: reserve the estimated cost of one AI call atomically (row lock). Returns the
     * reserved amount, or null when the call would exceed a cap — then no paid call is made.
     */
    public static function reserve(AiSetting $s, ?string $visitorId): ?float
    {
        $price = self::price($s);
        if (! $price) {
            return null;
        }
        $estimate = self::cost($price, self::EST_INPUT_TOKENS, (int) $s->max_output_tokens);
        AiBudget::forTenant($s->tenant_id); // make sure the row exists before locking it

        return \Illuminate\Support\Facades\DB::transaction(function () use ($s, $visitorId, $estimate) {
            $budget = AiBudget::where('tenant_id', $s->tenant_id)->lockForUpdate()->first();
            if (! $budget || self::blockReasonFor($s, $visitorId, $budget) !== null) {
                return null;
            }
            $budget->forceFill([
                'reserved_usd' => self::activeReservation($budget) + $estimate,
                'reserved_until' => now()->addMinutes(5),
            ])->save();

            return $estimate;
        });
    }

    /** Give back a reservation once the real cost has been recorded (or the call failed). */
    public static function release(string $tenantId, float $amount): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId, $amount) {
            $budget = AiBudget::where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($budget) {
                $budget->forceFill(['reserved_usd' => max(0, self::activeReservation($budget) - $amount)])->save();
            }
        });
    }

    public static function record(AiSetting $s, LlmResult $r, string $outcome, int $latencyMs, ?string $propertyId, ?string $conversationId): AiUsageLog
    {
        // Price the model that actually answered (a fallback model may have served the reply).
        $price = ($r->model && $r->model !== $s->model
            ? AiModelPrice::where('provider', $s->provider)->where('model', $r->model)->first()
            : null) ?? self::price($s);
        $cost = $price ? self::cost($price, $r->inputTokens, $r->outputTokens, $r->cacheReadTokens) : 0.0;

        $log = AiUsageLog::create([
            'tenant_id' => $s->tenant_id,
            'property_id' => $propertyId,
            'conversation_id' => $conversationId,
            'provider' => $s->provider,
            'model' => $r->model ?? $s->model,
            'input_tokens' => $r->inputTokens,
            'output_tokens' => $r->outputTokens,
            'cache_read_tokens' => $r->cacheReadTokens,
            'cost_usd' => $cost,
            'latency_ms' => $latencyMs,
            'outcome' => $outcome,
            'error' => $r->error ? mb_substr($r->error, 0, 300) : null,
        ]);

        foreach (['d:' . now()->format('Y-m-d'), 'm:' . now()->format('Y-m')] as $period) {
            DB::table('ai_usage_counters')->insertOrIgnore(['tenant_id' => $s->tenant_id, 'period' => $period, 'requests' => 0, 'cost_usd' => 0]);
            DB::table('ai_usage_counters')->where('tenant_id', $s->tenant_id)->where('period', $period)
                ->update(['requests' => DB::raw('requests + 1'), 'cost_usd' => DB::raw('cost_usd + ' . number_format($cost, 6, '.', ''))]);
        }

        self::maybeAlert($s->tenant_id);

        return $log;
    }

    public static function spent(string $tenantId, string $period): float
    {
        return (float) DB::table('ai_usage_counters')->where('tenant_id', $tenantId)->where('period', $period)->value('cost_usd');
    }

    public static function cost(AiModelPrice $p, int $in, int $out, int $cacheRead = 0): float
    {
        // Cache reads are billed far below normal input; counting them at 10% keeps estimates conservative.
        $billableIn = max(0, $in - $cacheRead) + (int) round($cacheRead * 0.1);

        return round(($billableIn * $p->input_per_million + $out * $p->output_per_million) / 1_000_000, 6);
    }

    private static function maybeAlert(string $tenantId): void
    {
        $budget = AiBudget::forTenant($tenantId);
        $month = now()->format('Y-m');
        $spent = self::spent($tenantId, 'm:' . $month);
        if ($budget->monthly_cap_usd > 0 && $spent >= $budget->monthly_cap_usd * $budget->alert_threshold_pct / 100 && $budget->alert_sent_month !== $month) {
            $budget->forceFill(['alert_sent_month' => $month])->save();
            Notifier::aiBudget($tenantId, sprintf('AI spending reached %d%% of the monthly budget ($%.2f of $%.2f).', $budget->alert_threshold_pct, $spent, $budget->monthly_cap_usd));
        }
    }

    private static function limitReached(AiBudget $budget, string $which): void
    {
        $month = now()->format('Y-m');
        $day = now()->format('Y-m-d');
        if ($which === 'month' && $budget->limit_sent_month !== $month) {
            $budget->forceFill(['limit_sent_month' => $month])->save();
            Notifier::aiBudget($budget->tenant_id, 'Monthly AI budget reached. ChatBox now answers from FAQs only and hands other questions to your team until next month or until the budget is raised.');
        }
        if ($which === 'day' && $budget->limit_sent_day !== $day) {
            $budget->forceFill(['limit_sent_day' => $day])->save();
            Notifier::aiBudget($budget->tenant_id, 'Daily AI budget reached. ChatBox now answers from FAQs only and hands other questions to your team until tomorrow.');
        }
    }
}
