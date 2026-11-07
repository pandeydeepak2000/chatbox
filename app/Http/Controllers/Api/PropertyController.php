<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit;
use App\Support\OutboundUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PropertyController extends Controller
{
    /**
     * List all properties of the authenticated tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Property::with(['departments']);

        if (! $user->isSuperAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $properties = $query->latest()->get()->map(fn (Property $p) => $p->toArrayFor($user))->values();

        return response()->json([
            'success' => true,
            'data' => $properties,
        ]);
    }

    /**
     * Create a new property/website for the tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only administrators can create new properties.',
            ], 403);
        }

        $validated = $request->validate([
            'site_name' => ['required', 'string', 'max:255'],
            'site_url' => ['required', 'string', 'max:255'],
            'allowed_domains' => ['nullable', 'array'],
            'allowed_domains.*' => ['string'],
            'widget_config' => ['nullable', 'array'],
        ]);

        if (! $user->tenant_id) {
            return response()->json(['success' => false, 'message' => 'Create websites from the platform panel for a specific company.'], 422);
        }

        \App\Services\PlanLimits::assertCanAdd($user->tenant_id, 'websites');

        $domains = $validated['allowed_domains'] ?? [];
        $host = parse_url($validated['site_url'], PHP_URL_HOST);
        if ($host && ! in_array($host, $domains)) {
            $domains[] = $host;
        }

        $property = Property::create([
            'tenant_id' => $user->tenant_id,
            'site_name' => $validated['site_name'],
            'site_url' => $validated['site_url'],
            'allowed_domains' => array_values(array_unique($domains)),
            'widget_config' => null,
            'is_active' => true,
        ]);
        if (! empty($validated['widget_config'])) {
            if ($error = $this->applyWidgetConfig($property, $validated['widget_config'])) {
                $property->delete();
                return $error;
            }
            $property->save();
        }
        Audit::record('property.created', $property, ['site_url' => $property->site_url]);

        // Create default Support department
        Department::create([
            'property_id' => $property->id,
            'name' => 'General Support',
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Property created successfully.',
            'data' => $this->formatPropertyResponse($property->load('departments'), $user),
        ], 201);
    }

    /**
     * Show single property details with embed code.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $property = $this->findTenantProperty($request, $id);

        return response()->json([
            'success' => true,
            'data' => $this->formatPropertyResponse($property->load(['departments', 'cannedResponses']), $request->user()),
        ]);
    }

    /**
     * Update property configuration or allowed domains.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $property = $this->findTenantProperty($request, $id);
        abort_unless($user->canManageProperty($property), 403, 'Forbidden: You cannot modify the settings of this website.');

        $validated = $request->validate([
            'site_name' => ['sometimes', 'string', 'max:255'],
            'site_url' => ['sometimes', 'string', 'max:255'],
            'allowed_domains' => ['sometimes', 'array'],
            'allowed_domains.*' => ['string'],
            'widget_config' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $widgetConfig = $validated['widget_config'] ?? null;
        unset($validated['widget_config']);
        $property->fill($validated);
        if ($widgetConfig !== null && ($error = $this->applyWidgetConfig($property, $widgetConfig))) {
            return $error;
        }
        $changed = array_keys($property->getDirty());
        $property->save();
        $this->clearPropertyCache($property);
        Audit::record('property.updated', $property, ['fields' => $changed]);

        return response()->json([
            'success' => true,
            'message' => 'Property updated successfully.',
            'data' => $this->formatPropertyResponse($property, $user),
        ]);
    }

    /**
     * Regenerate API Keys for property.
     */
    public function regenerateKeys(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only administrators can regenerate API keys.',
            ], 403);
        }

        $property = $this->findTenantProperty($request, $id);
        $this->clearPropertyCache($property);
        $property->regenerateKeys();
        $this->clearPropertyCache($property);
        Audit::record('property.keys_regenerated', $property);

        return response()->json([
            'success' => true,
            'message' => 'API keys regenerated successfully.',
            'data' => $this->formatPropertyResponse($property, $user),
        ]);
    }

    /**
     * Delete property.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only administrators can delete properties.',
            ], 403);
        }

        $property = $this->findTenantProperty($request, $id);
        Audit::record('property.deleted', $property, ['site_name' => $property->site_name, 'site_url' => $property->site_url]);
        $this->clearPropertyCache($property);
        $property->delete();

        return response()->json([
            'success' => true,
            'message' => 'Property deleted successfully.',
        ]);
    }

    /**
     * List departments under property.
     */
    public function listDepartments(Request $request, string $propertyId): JsonResponse
    {
        $property = $this->findTenantProperty($request, $propertyId);
        $departments = $property->departments()
            ->withCount(['agents', 'conversations'])
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $departments,
        ]);
    }

    /**
     * Create department under property.
     */
    public function storeDepartment(Request $request, string $propertyId): JsonResponse
    {
        $user = $request->user();

        $property = $this->findTenantProperty($request, $propertyId);
        abort_unless($user->canManageProperty($property), 403, 'Forbidden.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $dept = Department::create([
            'property_id' => $property->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department created successfully.',
            'data' => $dept,
        ], 201);
    }

    /**
     * Update department details (Name, description, active status).
     */
    public function updateDepartment(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $query = Department::where('id', $id);
        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
        }
        $dept = $query->firstOrFail();
        abort_unless($user->canManageProperty($dept->property), 403, 'Forbidden.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $dept->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? $dept->description,
            'is_active' => isset($validated['is_active']) ? (bool) $validated['is_active'] : $dept->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Department updated successfully.',
            'data' => $dept,
        ]);
    }

    /**
     * Delete department.
     */
    public function destroyDepartment(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $dept = Department::where('id', $id);
        if (! $user->isSuperAdmin()) {
            $dept->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
        }
        $dept = $dept->firstOrFail();
        abort_unless($user->canManageProperty($dept->property), 403, 'Forbidden.');
        $dept->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully.',
        ]);
    }

    /**
     * List all FAQs for property.
     */
    public function listFaqs(Request $request, string $id): JsonResponse
    {
        $property = $this->findTenantProperty($request, $id);
        $faqs = $property->faqs()->get();

        return response()->json([
            'success' => true,
            'data' => $faqs,
        ]);
    }

    /**
     * Store new FAQ.
     */
    public function storeFaq(Request $request, string $id): JsonResponse
    {
        $property = $this->findTenantProperty($request, $id);
        abort_unless($request->user()->canManageProperty($property), 403, 'Forbidden.');

        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string'],
            'order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $faq = $property->faqs()->create([
            'category' => $validated['category'] ?? 'General',
            'question' => $validated['question'],
            'answer' => $validated['answer'],
            'order' => $validated['order'] ?? ($property->faqs()->count() + 1),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        Cache::forget("prop_faqs_{$property->id}");
        Cache::forget("prop_faqs_all_{$property->id}");

        return response()->json([
            'success' => true,
            'message' => 'FAQ created successfully.',
            'data' => $faq,
        ], 201);
    }

    /**
     * Update existing FAQ.
     */
    public function updateFaq(Request $request, string $faqId): JsonResponse
    {
        $user = $request->user();
        $query = \App\Models\Faq::where('id', $faqId);
        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
        }
        $faq = $query->firstOrFail();
        abort_unless($user->canManageProperty($faq->property), 403, 'Forbidden.');

        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'max:100'],
            'question' => ['sometimes', 'string', 'max:500'],
            'answer' => ['sometimes', 'string'],
            'order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $faq->update($validated);
        Cache::forget("prop_faqs_{$faq->property_id}");
        Cache::forget("prop_faqs_all_{$faq->property_id}");

        return response()->json([
            'success' => true,
            'message' => 'FAQ updated successfully.',
            'data' => $faq,
        ]);
    }

    /**
     * Delete FAQ.
     */
    public function destroyFaq(Request $request, string $faqId): JsonResponse
    {
        $user = $request->user();
        $query = \App\Models\Faq::where('id', $faqId);
        if (! $user->isSuperAdmin()) {
            $query->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
        }
        $faq = $query->firstOrFail();
        abort_unless($user->canManageProperty($faq->property), 403, 'Forbidden.');
        $propertyId = $faq->property_id;
        $faq->delete();
        Cache::forget("prop_faqs_{$propertyId}");
        Cache::forget("prop_faqs_all_{$propertyId}");

        return response()->json([
            'success' => true,
            'message' => 'FAQ deleted successfully.',
        ]);
    }

    /**
     * Clear property cache when updated.
     */
    /**
     * Merge an incoming widget_config into the property.
     * - the webhook secret goes to its own encrypted column (never back into widget_config);
     *   a blank secret keeps the existing one
     * - the webhook URL must pass the SSRF guard (audit H4)
     * - client-side helper keys are dropped
     */
    private function applyWidgetConfig(Property $property, array $incoming): ?JsonResponse
    {
        $current = $property->widget_config ?? [];
        $legacySecret = $current['webhook_secret'] ?? null;

        $newSecret = array_key_exists('webhook_secret', $incoming) ? trim((string) $incoming['webhook_secret']) : '';
        unset($incoming['webhook_secret'], $incoming['webhook_secret_set'], $current['webhook_secret']);

        $merged = array_merge($current, $incoming);

        $url = trim((string) ($merged['webhook_url'] ?? ''));
        if ($url === '') {
            $merged['webhook_url'] = null;
        } else {
            $check = OutboundUrlGuard::check($url);
            if (! $check['ok']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Webhook URL rejected: ' . $check['reason'],
                    'errors' => ['widget_config.webhook_url' => [$check['reason']]],
                ], 422);
            }
            $merged['webhook_url'] = $url;
        }

        if ($newSecret !== '') {
            $property->webhook_secret = mb_substr($newSecret, 0, 200);
        } elseif ($legacySecret && ! $property->webhook_secret) {
            // Move a legacy secret out of widget_config into the encrypted column.
            $property->webhook_secret = $legacySecret;
        }

        $property->widget_config = $merged;

        return null;
    }

    private function clearPropertyCache(Property $property): void
    {
        Cache::forget("prop_pub_{$property->public_key}");
        Cache::forget("prop_depts_{$property->id}");
        Cache::forget("prop_faqs_{$property->id}");
    }

    /**
     * Scope property finding to caller's tenant.
     */
    private function findTenantProperty(Request $request, string $id): Property
    {
        $user = $request->user();
        $query = Property::where('id', $id);

        if (! $user->isSuperAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        }
        $property = $query->firstOrFail();
        // Website-restricted staff cannot even see other websites of their company.
        abort_unless($user->canAccessProperty($property), 404);

        return $property;
    }

    /**
     * Format property with embed snippet.
     */
    private function formatPropertyResponse(Property $property, ?User $viewer): array
    {
        $data = $property->toArrayFor($viewer);
        $apiUrl = config('app.url', 'http://localhost:8000');
        
        $data['embed_code'] = "<!-- JixChat Live Support Widget -->\n"
            . "<script>\n"
            . "  window.JixChatConfig = {\n"
            . "    propertyKey: \"{$property->public_key}\",\n"
            . "    apiUrl: \"{$apiUrl}\"\n"
            . "  };\n"
            . "</script>\n"
            . "<script src=\"{$apiUrl}/widget.js\" async></script>\n"
            . "<!-- End JixChat -->";

        return $data;
    }
}
