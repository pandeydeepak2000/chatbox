<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\AiProviderCatalog;
use App\Services\Ai\AiProviderService;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Company-administrator tools for AI providers and models (company.data middleware: the platform super admin never
 * reaches these). Every lookup is scoped to the signed-in administrator's company; a key is write-only everywhere.
 * The only platform-level endpoints are the custom-endpoint approvals at the bottom (metadata only, never keys).
 */
class AiProviderController extends Controller
{
    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->tenant_id && $user->isAdmin(), 403, 'Only company administrators can manage AI providers.');

        return $user;
    }

    private function model(User $admin, string $id): AiModel
    {
        return AiModel::where('tenant_id', $admin->tenant_id)->where('id', $id)->firstOrFail();
    }

    private function providerArray(AiProvider $p, ?AiSetting $settings): array
    {
        return $p->toSafeArray() + [
            'models' => $p->models()->orderBy('display_name')->get()->map(fn (AiModel $m) => $m->toSafeArray() + ['is_active' => $settings?->active_model_id === $m->id])->values()->all(),
            'is_active' => $settings?->provider_id === $p->id,
        ];
    }

    // ------------------------------------------------------------------ list

    public function index(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $settings = AiSetting::forTenant($admin->tenant_id);
        $rows = AiProvider::where('tenant_id', $admin->tenant_id)->get();
        $out = [];
        foreach (AiProviderCatalog::NAMED as $type) {
            $p = $rows->firstWhere('slug', $type);
            $out[] = $p ? $this->providerArray($p, $settings) : [
                'id' => null, 'slug' => $type, 'type' => $type, 'name' => AiProviderCatalog::TYPES[$type]['label'], 'label' => AiProviderCatalog::TYPES[$type]['label'],
                'subtitle' => AiProviderCatalog::TYPES[$type]['subtitle'], 'base_url' => AiProviderCatalog::TYPES[$type]['base_url'], 'models_path' => null,
                'timeout_seconds' => 25, 'json_mode' => false, 'enabled' => true, 'approval_status' => 'approved', 'approval_note' => null,
                'connection_status' => 'untested', 'connection_message' => null, 'connection_checked_at' => null,
                'api_key_set' => false, 'api_key_hint' => null, 'models' => [], 'is_active' => false,
            ];
        }
        foreach ($rows->where('type', 'custom') as $p) {
            $out[] = $this->providerArray($p, $settings);
        }

        return response()->json(['success' => true, 'data' => [
            'providers' => $out,
            'options' => AiProviderCatalog::options(),
            'active' => ['provider_id' => $settings->provider_id, 'model_id' => $settings->active_model_id],
        ]]);
    }

    // ------------------------------------------------------------------ providers

    /** Save the key / switches of a provider (named providers by slug, custom ones by id). A blank key keeps the stored one. */
    public function update(Request $request, string $key): JsonResponse
    {
        $admin = $this->admin($request);
        $p = AiProviderService::resolve($admin->tenant_id, $key);
        $v = $request->validate([
            'api_key' => ['nullable', 'string', 'min:8', 'max:400'],
            'clear_api_key' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:60'],
            'name' => ['nullable', 'string', 'min:2', 'max:80'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'models_path' => ['nullable', 'string', 'max:120', 'regex:/^\/[A-Za-z0-9._\/-]*$/'],
            'json_mode' => ['nullable', 'boolean'],
        ]);

        if (! empty($v['api_key'])) {
            $p->api_key = trim($v['api_key']);
            $p->connection_status = 'untested';
            $p->connection_message = null;
            $p->connection_checked_at = null;
        } elseif (! empty($v['clear_api_key'])) {
            return $this->forgetResponse($p, $admin);
        }
        foreach (['enabled', 'timeout_seconds'] as $f) {
            if (array_key_exists($f, $v) && $v[$f] !== null) {
                $p->{$f} = $v[$f];
            }
        }
        if ($p->isCustom()) {
            if (! empty($v['name'])) {
                $p->name = trim($v['name']);
            }
            if (array_key_exists('models_path', $v)) {
                $p->models_path = $v['models_path'] ?: null;
            }
            if (isset($v['json_mode'])) {
                $p->json_mode = (bool) $v['json_mode'];
            }
            if (! empty($v['base_url']) && rtrim($v['base_url'], '/') !== $p->base_url) {
                $p->base_url = AiProviderService::assertSafeCustomUrl($v['base_url']);
                $allowed = AiProviderService::hostAllowlisted($p->host());
                $p->approval_status = $allowed ? 'approved' : 'pending';           // a changed address must be approved again
                $p->approval_note = $allowed ? 'Host is on the platform allowlist.' : 'Address changed: waiting for platform approval.';
                $p->connection_status = 'untested';
            }
        }
        $p->save();
        AiProviderService::resyncIfActive($p);
        Audit::record('ai.provider_updated', $p, ['fields' => array_keys(array_filter($v, fn ($x) => $x !== null && $x !== ''))], $admin, $admin->tenant_id);

        return response()->json(['success' => true, 'data' => $this->providerArray($p->fresh(), AiSetting::forTenant($admin->tenant_id))]);
    }

    private function forgetResponse(AiProvider $p, User $admin): JsonResponse
    {
        AiProviderService::forget($p, $admin);

        return response()->json(['success' => true, 'data' => ['removed' => $p->isCustom(), 'slug' => $p->slug]]);
    }

    public function storeCustom(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'protocol' => ['nullable', 'in:openai'],                       // the one protocol this engine speaks for custom endpoints
            'base_url' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'min:8', 'max:400'],
            'models_path' => ['nullable', 'string', 'max:120', 'regex:/^\/[A-Za-z0-9._\/-]*$/'],
            'model_id' => ['nullable', 'string', 'max:150'],
            'timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:60'],
            'json_mode' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $p = AiProviderService::createCustom($admin->tenant_id, $v, $admin);
        if (! empty($v['model_id'])) {
            AiProviderService::addModel($p, $v['model_id'], null, null, null, false);
        }

        return response()->json(['success' => true, 'data' => $this->providerArray($p->fresh(), AiSetting::forTenant($admin->tenant_id))], 201);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $admin = $this->admin($request);

        return $this->forgetResponse(AiProviderService::resolve($admin->tenant_id, $key), $admin);
    }

    public function test(Request $request, string $key): JsonResponse
    {
        $admin = $this->admin($request);
        $p = AiProviderService::testConnection(AiProviderService::resolve($admin->tenant_id, $key));

        return response()->json(['success' => $p->connection_status === 'ok', 'data' => $this->providerArray($p, AiSetting::forTenant($admin->tenant_id)), 'message' => $p->connection_message]);
    }

    /** The provider's live model list, optionally filtered by ?q=. Nothing is saved. */
    public function discover(Request $request, string $key): JsonResponse
    {
        $admin = $this->admin($request);
        $p = AiProviderService::resolve($admin->tenant_id, $key);
        $r = AiProviderService::discover($p);
        $q = mb_strtolower(trim((string) $request->input('q', '')));
        $have = AiModel::where('provider_id', $p->id)->pluck('model_id')->all();
        $models = collect($r['models'])
            ->when($q !== '', fn ($c) => $c->filter(fn ($m) => str_contains(mb_strtolower($m['id'] . ' ' . $m['name']), $q)))
            ->map(fn ($m) => $m + ['added' => in_array($m['id'], $have, true)])->take(300)->values()->all();

        return response()->json(['success' => $r['ok'], 'data' => ['models' => $models, 'code' => $r['code'], 'manual_allowed' => true], 'message' => $r['message']], 200);
    }

    // ------------------------------------------------------------------ models

    public function storeModel(Request $request, string $key): JsonResponse
    {
        $admin = $this->admin($request);
        $p = AiProviderService::resolve($admin->tenant_id, $key);
        $v = $request->validate([
            'model_id' => ['required', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'input_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'test' => ['nullable', 'boolean'],
            'make_default' => ['nullable', 'boolean'],
        ]);
        if (($v['input_per_million'] ?? null) === null xor ($v['output_per_million'] ?? null) === null) {
            throw ValidationException::withMessages(['input_per_million' => ['Enter both the input and the output price, or neither.']]);
        }
        $m = AiProviderService::addModel($p, $v['model_id'], $v['display_name'] ?? null, isset($v['input_per_million']) ? (float) $v['input_per_million'] : null, isset($v['output_per_million']) ? (float) $v['output_per_million'] : null);
        if (! empty($v['test'])) {
            $m = AiProviderService::testModel($m->load('provider'));
        }
        if (! empty($v['make_default'])) {
            AiProviderService::activate($m->load('provider'));
        }
        Audit::record('ai.model_added', $m, ['provider' => $p->slug, 'model' => $m->model_id], $admin, $admin->tenant_id);

        return response()->json(['success' => true, 'data' => $m->fresh()->toSafeArray()], 201);
    }

    public function updateModel(Request $request, string $id): JsonResponse
    {
        $admin = $this->admin($request);
        $m = $this->model($admin, $id);
        $v = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'input_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        if (! empty($v['display_name'])) {
            $m->display_name = trim($v['display_name']);
        }
        if (isset($v['enabled'])) {
            $m->enabled = (bool) $v['enabled'];
        }
        if (isset($v['input_per_million'], $v['output_per_million'])) {
            $m->input_per_million = (float) $v['input_per_million'];
            $m->output_per_million = (float) $v['output_per_million'];
        }
        $m->save();
        Audit::record('ai.model_updated', $m, ['enabled' => $m->enabled], $admin, $admin->tenant_id);

        return response()->json(['success' => true, 'data' => $m->toSafeArray()]);
    }

    public function testModel(Request $request, string $id): JsonResponse
    {
        $admin = $this->admin($request);
        $m = AiProviderService::testModel($this->model($admin, $id)->load('provider'));

        return response()->json(['success' => $m->test_status === 'available', 'data' => $m->toSafeArray(), 'message' => $m->test_message]);
    }

    public function makeDefault(Request $request, string $id): JsonResponse
    {
        $admin = $this->admin($request);
        $s = AiProviderService::activate($this->model($admin, $id)->load('provider'));
        Audit::record('ai.model_activated', $s, ['model' => $s->model, 'provider' => $s->provider], $admin, $admin->tenant_id);

        return response()->json(['success' => true, 'data' => ['active_model_id' => $s->active_model_id, 'provider_id' => $s->provider_id, 'ai_enabled' => (bool) $s->enabled]]);
    }

    public function destroyModel(Request $request, string $id): JsonResponse
    {
        $admin = $this->admin($request);
        $m = $this->model($admin, $id);
        if (AiSetting::forTenant($admin->tenant_id)->active_model_id === $m->id) {
            throw ValidationException::withMessages(['model' => ['This is the active model. Choose another default model first.']]);
        }
        $m->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ platform (super admin): custom endpoint approvals

    public function platformList(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $rows = AiProvider::where('type', 'custom')->orderByRaw("case approval_status when 'pending' then 0 else 1 end")->orderByDesc('created_at')->limit(200)->get();
        $tenants = \App\Models\Tenant::whereIn('id', $rows->pluck('tenant_id'))->pluck('name', 'id');

        // Metadata only. The API key is never selected into this response.
        return response()->json(['success' => true, 'data' => $rows->map(fn (AiProvider $p) => [
            'id' => $p->id, 'company' => $tenants[$p->tenant_id] ?? '—', 'name' => $p->name, 'host' => $p->host(), 'base_url' => $p->base_url,
            'approval_status' => $p->approval_status, 'approval_note' => $p->approval_note, 'created_at' => $p->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function platformDecide(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $v = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:200']]);
        $p = AiProviderService::decide(AiProvider::where('type', 'custom')->findOrFail($id), $v['decision'] === 'approve', $request->user(), $v['note'] ?? null);

        return response()->json(['success' => true, 'data' => ['id' => $p->id, 'approval_status' => $p->approval_status]]);
    }
}
