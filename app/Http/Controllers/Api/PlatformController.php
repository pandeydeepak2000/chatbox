<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Audit;
use App\Services\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Platform super admin: companies, plans, subscriptions and usage (SaaS foundation). */
class PlatformController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->guard($request);
        $tenants = Tenant::withCount(['users', 'properties'])->orderByDesc('created_at')->get();
        $subs = DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->get(['subscriptions.tenant_id', 'subscriptions.status', 'subscriptions.current_period_end', 'plans.code', 'plans.name', 'plans.price_monthly'])->keyBy('tenant_id');

        return response()->json(['success' => true, 'data' => [
            'tenants' => $tenants->map(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'email' => $t->email,
                'status' => $t->status,
                'users_count' => $t->users_count,
                'properties_count' => $t->properties_count,
                'plan' => isset($subs[$t->id]) ? ['code' => $subs[$t->id]->code, 'name' => $subs[$t->id]->name, 'price_monthly' => (float) $subs[$t->id]->price_monthly] : null,
                'subscription_status' => $subs[$t->id]->status ?? null,
                'usage' => PlanLimits::usage($t->id),
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'plans' => $this->plans(),
        ]]);
    }

    public function setTenantStatus(Request $request, string $id): JsonResponse
    {
        $this->guard($request);
        $v = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        $tenant = Tenant::findOrFail($id);
        $tenant->forceFill(['status' => $v['status']])->save();
        if ($v['status'] === 'suspended') {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $tenant->users()->pluck('id'))->delete();
        }
        // Widgets of a suspended company stop working immediately (no stale cached lookup).
        foreach (\App\Models\Property::where('tenant_id', $tenant->id)->pluck('public_key') as $key) {
            \Illuminate\Support\Facades\Cache::forget("prop_pub_{$key}");
        }
        Audit::record('platform.tenant_status', $tenant, $v);

        return response()->json(['success' => true]);
    }

    public function setSubscription(Request $request, string $tenantId): JsonResponse
    {
        $this->guard($request);
        $v = $request->validate([
            'plan_code' => ['required', 'string', Rule::exists('plans', 'code')],
            'status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'cancelled'])],
            'current_period_end' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $tenant = Tenant::findOrFail($tenantId);
        $planId = DB::table('plans')->where('code', $v['plan_code'])->value('id');
        $existing = DB::table('subscriptions')->where('tenant_id', $tenant->id)->first();
        $data = [
            'plan_id' => $planId,
            'status' => $v['status'],
            'current_period_end' => $v['current_period_end'] ?? null,
            'notes' => $v['notes'] ?? null,
            'updated_at' => now(),
        ];
        $existing
            ? DB::table('subscriptions')->where('tenant_id', $tenant->id)->update($data)
            : DB::table('subscriptions')->insert($data + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'current_period_start' => now(), 'created_at' => now()]);
        if (in_array($v['plan_code'], ['free', 'pro', 'enterprise'], true)) {
            $tenant->forceFill(['plan' => $v['plan_code']])->save();
        }
        Audit::record('platform.subscription_changed', $tenant, $v);

        return response()->json(['success' => true, 'data' => PlanLimits::limits($tenant->id)]);
    }

    public function savePlan(Request $request, ?string $code = null): JsonResponse
    {
        $this->guard($request);
        $v = $request->validate([
            'code' => [$code ? 'prohibited' : 'required', 'string', 'regex:/^[a-z0-9_-]{2,40}$/', Rule::unique('plans', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'limits' => ['required', 'array'],
            'limits.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $limits = array_intersect_key($v['limits'], array_flip(PlanLimits::KEYS));
        $row = [
            'name' => $v['name'], 'price_monthly' => $v['price_monthly'], 'currency' => strtoupper($v['currency']),
            'is_active' => $v['is_active'], 'is_public' => $v['is_public'], 'limits' => json_encode($limits), 'updated_at' => now(),
        ];
        if ($code) {
            abort_unless(DB::table('plans')->where('code', $code)->exists(), 404);
            DB::table('plans')->where('code', $code)->update($row);
        } else {
            DB::table('plans')->insert($row + ['code' => $v['code'], 'created_at' => now(), 'sort' => 50]);
        }
        Audit::record('platform.plan_saved', null, ['code' => $code ?? $v['code']]);

        return response()->json(['success' => true, 'data' => $this->plans()]);
    }

    private function plans(): array
    {
        return DB::table('plans')->orderBy('sort')->get()->map(fn ($p) => [
            'code' => $p->code, 'name' => $p->name, 'price_monthly' => (float) $p->price_monthly, 'currency' => $p->currency,
            'limits' => json_decode($p->limits, true), 'is_active' => (bool) $p->is_active, 'is_public' => (bool) $p->is_public,
            'subscribers' => DB::table('subscriptions')->where('plan_id', $p->id)->count(),
        ])->all();
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }

    /** Called when a company is created (self-registration or platform panel). */
    public static function createSubscription(string $tenantId, string $planCode): void
    {
        $planId = DB::table('plans')->where('code', $planCode)->value('id') ?? DB::table('plans')->where('code', 'free')->value('id');
        if ($planId && ! DB::table('subscriptions')->where('tenant_id', $tenantId)->exists()) {
            DB::table('subscriptions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'plan_id' => $planId,
                'status' => 'active', 'current_period_start' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
