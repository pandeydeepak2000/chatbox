<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiBudget;
use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\AiUsageLog;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\Ai\AiBudgetService;
use App\Services\Audit;
use App\Services\Knowledge\KnowledgeSearch;
use App\Services\Knowledge\KnowledgeService;
use App\Support\OutboundUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Knowledge base (company-wide + website-specific, with approval) and AI settings/budget/usage.
 */
class KnowledgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'status' => ['nullable', Rule::in(KnowledgeDocument::STATUSES)],
            'property_id' => ['nullable', 'string', 'max:64'], // 'company' = company-wide only
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $q = KnowledgeDocument::visibleTo($user)->with(['property:id,site_name', 'currentVersion:id,version,original_name']);
        if (! empty($v['status'])) {
            $q->where('status', $v['status']);
        } else {
            $q->where('status', '!=', 'archived');
        }
        if (! empty($v['property_id'])) {
            $v['property_id'] === 'company' ? $q->whereNull('property_id') : $q->where('property_id', $v['property_id']);
        }
        if (! empty($v['search'])) {
            $q->where('title', 'like', '%' . addcslashes($v['search'], '%_\\') . '%');
        }
        $docs = $q->orderByRaw("CASE status WHEN 'pending_approval' THEN 0 WHEN 'processing' THEN 1 WHEN 'failed' THEN 2 ELSE 3 END")->orderByDesc('updated_at')->limit(300)->get();

        return response()->json(['success' => true, 'data' => $docs->map(fn ($d) => $this->row($d))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $doc = KnowledgeDocument::visibleTo($request->user())->where('knowledge_documents.id', $id)->firstOrFail();
        $versions = KnowledgeVersion::where('document_id', $doc->id)->orderByDesc('version')->get();
        $review = $versions->first(fn ($v) => $v->status === 'ready');

        return response()->json(['success' => true, 'data' => array_merge($this->row($doc->load('property')), [
            'versions' => $versions->map(fn ($v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'error' => $v->error,
                'original_name' => $v->original_name, 'size' => $v->size, 'created_at' => $v->created_at?->toIso8601String(),
                'is_current' => $v->id === $doc->current_version_id,
            ])->values(),
            'review_text' => $review ? mb_substr((string) $review->extracted_text, 0, 200000) : null,
            'review_version' => $review?->version,
            'chunks' => DB::table('knowledge_chunks')->where('document_id', $doc->id)->count(),
        ])]);
    }

    public function storeManual(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:100000'],
            'property_id' => ['nullable', 'string', 'max:64'],
        ]);
        $propertyId = $this->authorizeScope($user, $v['property_id'] ?? null);
        $doc = KnowledgeService::createManual($user, $propertyId, $v['title'], $v['content']);

        return response()->json(['success' => true, 'data' => $this->row($doc)], 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'title' => ['nullable', 'string', 'max:200'],
            'property_id' => ['nullable', 'string', 'max:64'],
            'document_id' => ['nullable', 'string', 'max:64'], // new version of an existing document
        ]);
        $existing = null;
        if (! empty($v['document_id'])) {
            $existing = KnowledgeDocument::visibleTo($user)->where('knowledge_documents.id', $v['document_id'])->firstOrFail();
            $this->authorizeScope($user, $existing->property_id);
        }
        $propertyId = $existing ? $existing->property_id : $this->authorizeScope($user, $v['property_id'] ?? null);
        $doc = KnowledgeService::upload($user, $request->file('file'), $propertyId, $v['title'] ?? null, $existing);

        return response()->json(['success' => true, 'data' => $this->row($doc)], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $doc = KnowledgeDocument::visibleTo($user)->where('knowledge_documents.id', $id)->firstOrFail();
        $this->authorizeScope($user, $doc->property_id);
        $v = $request->validate(['content' => ['nullable', 'string', 'max:1500000']]);
        $doc = KnowledgeService::approve($doc, $user, $v['content'] ?? null);

        return response()->json(['success' => true, 'data' => $this->row($doc)]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $doc = KnowledgeDocument::visibleTo($user)->where('knowledge_documents.id', $id)->firstOrFail();
        $this->authorizeScope($user, $doc->property_id);
        $v = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['success' => true, 'data' => $this->row(KnowledgeService::reject($doc, $user, $v['note'] ?? null))]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $doc = KnowledgeDocument::visibleTo($user)->where('knowledge_documents.id', $id)->firstOrFail();
        $this->authorizeScope($user, $doc->property_id);
        KnowledgeService::archive($doc);

        return response()->json(['success' => true]);
    }

    /** "What would the bot find?" — test retrieval without calling the LLM (free). */
    public function testSearch(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate(['query' => ['required', 'string', 'max:500'], 'property_id' => ['required', 'string', 'max:64']]);
        $property = Property::where('id', $v['property_id'])->where('tenant_id', $user->tenant_id)->firstOrFail();
        abort_unless($user->canAccessProperty($property), 404);

        return response()->json(['success' => true, 'data' => KnowledgeSearch::search($property->tenant_id, $property->id, $v['query'], 5)]);
    }

    // ------------------------------------------------------------- AI settings (company admins)

    public function aiSettings(Request $request): JsonResponse
    {
        $tenantId = $this->adminTenant($request);
        $settings = AiSetting::forTenant($tenantId);
        $budget = AiBudget::forTenant($tenantId);

        return response()->json(['success' => true, 'data' => [
            'settings' => $settings->toSafeArray(),
            'budget' => $budget->only(['monthly_cap_usd', 'daily_cap_usd', 'per_visitor_daily_messages', 'alert_threshold_pct']),
            'providers' => collect(AiSetting::PROVIDERS)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            'prices' => AiModelPrice::orderBy('provider')->orderBy('model')->get(['provider', 'model', 'input_per_million', 'output_per_million']),
            'price_known' => AiBudgetService::price($settings) !== null,
            'status' => AiBudgetService::blockReason($settings, null),
        ]]);
    }

    public function saveAiSettings(Request $request): JsonResponse
    {
        $tenantId = $this->adminTenant($request);
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::in(array_keys(AiSetting::PROVIDERS))],
            'base_url' => ['nullable', 'url', 'max:255'],
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'effort' => ['nullable', Rule::in(AiSetting::EFFORTS)],
            'api_key' => ['nullable', 'string', 'max:300'],
            'clear_api_key' => ['nullable', 'boolean'],
            'max_output_tokens' => ['nullable', 'integer', 'min:256', 'max:8000'],
            'instructions' => ['nullable', 'string', 'max:8000'],
            'monthly_cap_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
            'daily_cap_usd' => ['required', 'numeric', 'min:0', 'max:10000'],
            'per_visitor_daily_messages' => ['required', 'integer', 'min:0', 'max:1000'],
            'alert_threshold_pct' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        if ($v['provider'] === 'openai_compatible' && ! empty($v['base_url']) && ! OutboundUrlGuard::check(rtrim($v['base_url'], '/') . '/chat/completions')['ok']) {
            return response()->json(['success' => false, 'message' => 'The AI base URL points to a private or invalid address.', 'errors' => ['base_url' => ['Invalid base URL.']]], 422);
        }

        $settings = AiSetting::forTenant($tenantId);
        $settings->fill([
            'enabled' => $v['enabled'],
            'provider' => $v['provider'],
            'base_url' => $v['provider'] === 'openai_compatible' ? ($v['base_url'] ?? null) : null,
            'model' => $v['model'],
            'effort' => $v['effort'] ?? 'low',
            'max_output_tokens' => $v['max_output_tokens'] ?? 1500,
            'instructions' => $v['instructions'] ?? null,
        ]);
        if (! empty($v['api_key'])) {
            $settings->api_key = trim($v['api_key']);
        } elseif (! empty($v['clear_api_key'])) {
            $settings->api_key = null;
        }
        $settings->save();

        AiBudget::forTenant($tenantId)->update([
            'monthly_cap_usd' => $v['monthly_cap_usd'],
            'daily_cap_usd' => $v['daily_cap_usd'],
            'per_visitor_daily_messages' => $v['per_visitor_daily_messages'],
            'alert_threshold_pct' => $v['alert_threshold_pct'] ?? 80,
        ]);
        Audit::record('ai.settings_updated', $settings, ['provider' => $v['provider'], 'model' => $v['model'], 'enabled' => $v['enabled'], 'key_changed' => ! empty($v['api_key'])]);

        return $this->aiSettings($request);
    }

    public function aiUsage(Request $request): JsonResponse
    {
        $tenantId = $this->adminTenant($request);
        $budget = AiBudget::forTenant($tenantId);
        $daily = AiUsageLog::where('tenant_id', $tenantId)->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cost_usd) as cost_usd')
            ->groupBy('day')->orderBy('day')->get();
        $outcomes = AiUsageLog::where('tenant_id', $tenantId)->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('outcome, COUNT(*) as n')->groupBy('outcome')->pluck('n', 'outcome');

        return response()->json(['success' => true, 'data' => [
            'month_spent_usd' => round(AiBudgetService::spent($tenantId, 'm:' . now()->format('Y-m')), 4),
            'today_spent_usd' => round(AiBudgetService::spent($tenantId, 'd:' . now()->format('Y-m-d')), 4),
            'monthly_cap_usd' => $budget->monthly_cap_usd,
            'daily_cap_usd' => $budget->daily_cap_usd,
            'daily' => $daily,
            'outcomes' => $outcomes,
            'recent' => AiUsageLog::where('tenant_id', $tenantId)->orderByDesc('id')->limit(30)
                ->get(['id', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'latency_ms', 'outcome', 'error', 'created_at']),
        ]]);
    }

    /** Platform admin: editable model price table used for cost tracking and budgets. */
    public function savePrice(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $v = $request->validate([
            'provider' => ['required', Rule::in(array_keys(AiSetting::PROVIDERS))],
            'model' => ['required', 'string', 'max:100'],
            'input_per_million' => ['required', 'numeric', 'min:0', 'max:1000'],
            'output_per_million' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);
        $price = AiModelPrice::updateOrCreate(['provider' => $v['provider'], 'model' => $v['model']], $v);
        Audit::record('ai.price_saved', $price, $v);

        return response()->json(['success' => true, 'data' => $price]);
    }

    // ------------------------------------------------------------- helpers

    /** Company-wide knowledge needs a company admin; website knowledge needs website-admin rights. */
    private function authorizeScope(User $user, ?string $propertyId): ?string
    {
        if ($propertyId === null || $propertyId === '' || $propertyId === 'company') {
            abort_unless($user->isAdmin() || $user->isSuperAdmin(), 403, 'Only company administrators can manage company-wide knowledge.');

            return null;
        }
        $property = Property::find($propertyId);
        abort_unless($property && $user->canManageProperty($property), 403, 'You cannot manage knowledge for this website.');

        return $property->id;
    }

    private function adminTenant(Request $request): string
    {
        $user = $request->user();
        abort_unless($user->tenant_id && $user->isAdmin(), 403, 'Only company administrators can change AI settings.');

        return $user->tenant_id;
    }

    private function row(KnowledgeDocument $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'source_type' => $d->source_type,
            'status' => $d->status,
            'scope' => $d->property_id ? 'website' : 'company',
            'property' => $d->property ? ['id' => $d->property->id, 'site_name' => $d->property->site_name] : null,
            'current_version' => $d->currentVersion?->version,
            'approved_at' => $d->approved_at?->toIso8601String(),
            'review_note' => $d->review_note,
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
