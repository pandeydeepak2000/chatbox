<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Property;
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

        $properties = $query->latest()->get();

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
            'widget_config' => $validated['widget_config'] ?? null,
            'is_active' => true,
        ]);

        // Create default Support department
        Department::create([
            'property_id' => $property->id,
            'name' => 'General Support',
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Property created successfully.',
            'data' => $this->formatPropertyResponse($property->load('departments')),
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
            'data' => $this->formatPropertyResponse($property->load(['departments', 'cannedResponses'])),
        ]);
    }

    /**
     * Update property configuration or allowed domains.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Only administrators can modify property settings.',
            ], 403);
        }

        $property = $this->findTenantProperty($request, $id);

        $validated = $request->validate([
            'site_name' => ['sometimes', 'string', 'max:255'],
            'site_url' => ['sometimes', 'string', 'max:255'],
            'allowed_domains' => ['sometimes', 'array'],
            'allowed_domains.*' => ['string'],
            'widget_config' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $property->update($validated);
        $this->clearPropertyCache($property);

        return response()->json([
            'success' => true,
            'message' => 'Property updated successfully.',
            'data' => $this->formatPropertyResponse($property),
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

        return response()->json([
            'success' => true,
            'message' => 'API keys regenerated successfully.',
            'data' => $this->formatPropertyResponse($property),
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
        $property->delete();

        return response()->json([
            'success' => true,
            'message' => 'Property deleted successfully.',
        ]);
    }

    /**
     * Create department under property.
     */
    public function storeDepartment(Request $request, string $propertyId): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $property = $this->findTenantProperty($request, $propertyId);

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
     * Delete department.
     */
    public function destroyDepartment(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $dept = Department::where('id', $id);
        if (! $user->isSuperAdmin()) {
            $dept->whereHas('property', fn($q) => $q->where('tenant_id', $user->tenant_id));
        }
        $dept = $dept->firstOrFail();
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

        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'max:100'],
            'question' => ['sometimes', 'string', 'max:500'],
            'answer' => ['sometimes', 'string'],
            'order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $faq->update($validated);
        Cache::forget("prop_faqs_{$faq->property_id}");

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
        $propertyId = $faq->property_id;
        $faq->delete();
        Cache::forget("prop_faqs_{$propertyId}");

        return response()->json([
            'success' => true,
            'message' => 'FAQ deleted successfully.',
        ]);
    }

    /**
     * Clear property cache when updated.
     */
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

        return $query->firstOrFail();
    }

    /**
     * Format property with embed snippet.
     */
    private function formatPropertyResponse(Property $property): array
    {
        $data = $property->toArray();
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
