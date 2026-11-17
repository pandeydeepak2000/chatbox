<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\Audit;
use App\Support\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Provider and model management for ONE company at a time (every query is scoped by tenant_id).
 *
 * Security properties:
 *  - API keys are encrypted at rest (model cast), write-only, and never put into a message, log line or response here.
 *  - Provider error bodies are never relayed: admins see a fixed, classified message (bad key / no such model / outage ...).
 *  - Every outbound call re-validates the destination at request time (https for custom, public IPs only, redirects off,
 *    the vetted IP is pinned), so changing DNS after approval cannot reach an internal address.
 *  - Custom endpoints are unusable until approved by the platform (allowlist host or super-admin decision).
 */
class AiProviderService
{
    private const MESSAGES = [
        'ok' => 'Connected.',
        'no_key' => 'No API key is saved for this provider.',
        'invalid_credentials' => 'The provider rejected the API key (invalid, expired or without access).',
        'rate_limited' => 'The provider is rate-limiting this key right now. Try again shortly.',
        'unavailable' => 'The provider is temporarily unavailable. Try again later.',
        'unreachable' => 'Could not reach the provider (network error or timeout).',
        'not_found' => 'The provider answered, but this address or model was not found.',
        'blocked' => 'The provider address was rejected for safety.',
        'no_catalog' => 'This provider has no model list. Add a model ID and use "Test model".',
        'error' => 'The provider returned an unexpected error.',
    ];

    // ------------------------------------------------------------------ providers

    /** The company's row for a named provider (created on first use, without any key). */
    public static function named(string $tenantId, string $type): AiProvider
    {
        abort_unless(AiProviderCatalog::isNamed($type), 422, 'Unknown provider.');

        return AiProvider::firstOrCreate(
            ['tenant_id' => $tenantId, 'slug' => $type],
            ['type' => $type, 'name' => AiProviderCatalog::TYPES[$type]['label'], 'approval_status' => 'approved']
        );
    }

    /** Provider by id or slug, always inside the company (another company's id simply does not exist here). */
    public static function resolve(string $tenantId, string $key): AiProvider
    {
        $p = AiProvider::where('tenant_id', $tenantId)->where(fn ($q) => $q->where('id', $key)->orWhere('slug', $key))->first();
        if (! $p && AiProviderCatalog::isNamed($key)) {
            $p = self::named($tenantId, $key);
        }
        abort_unless($p, 404, 'Provider not found.');

        return $p;
    }

    public static function baseUrl(AiProvider $p): ?string
    {
        return $p->isCustom() ? $p->base_url : (AiProviderCatalog::TYPES[$p->type]['base_url'] ?? null);
    }

    public static function hostAllowlisted(?string $host): bool
    {
        $host = strtolower((string) $host);
        foreach ((array) config('chatbox.ai.custom_host_allowlist', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a custom endpoint: https only, port 443, a host NAME (no IP literal), resolving only to public addresses.
     *
     * @throws ValidationException
     */
    public static function assertSafeCustomUrl(string $url, string $field = 'base_url'): string
    {
        $url = rtrim(trim($url), '/');
        $p = parse_url($url);
        $fail = fn (string $m) => throw ValidationException::withMessages([$field => [$m]]);
        if (! $p || strtolower($p['scheme'] ?? '') !== 'https') {
            $fail('The API address must start with https://');
        }
        if (isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            $fail('The API address must not contain credentials, a query string or a fragment.');
        }
        if (($p['port'] ?? 443) !== 443) {
            $fail('Only the standard HTTPS port 443 is allowed.');
        }
        $host = strtolower($p['host'] ?? '');
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $fail('Use a host name, not an IP address.');
        }
        $check = OutboundUrlGuard::check($url . '/chat/completions');
        if (! $check['ok']) {
            $fail('This address is not allowed: ' . $check['reason']);
        }

        return $url;
    }

    /** @param array{name:string, base_url:string, api_key:string, models_path?:?string, timeout_seconds?:int, json_mode?:bool, enabled?:bool} $v */
    public static function createCustom(string $tenantId, array $v, ?User $by = null): AiProvider
    {
        $url = self::assertSafeCustomUrl($v['base_url']);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $approved = self::hostAllowlisted($host);

        $p = AiProvider::create([
            'tenant_id' => $tenantId,
            'slug' => 'custom-' . Str::lower(Str::random(6)),
            'type' => 'custom',
            'name' => mb_substr(trim($v['name']), 0, 80),
            'base_url' => $url,
            'models_path' => ! empty($v['models_path']) ? $v['models_path'] : null,
            'api_key' => trim($v['api_key']),
            'timeout_seconds' => max(5, min(60, (int) ($v['timeout_seconds'] ?? 25))),
            'json_mode' => (bool) ($v['json_mode'] ?? false),
            'enabled' => (bool) ($v['enabled'] ?? true),
            'approval_status' => $approved ? 'approved' : 'pending',
            'approval_note' => $approved ? 'Host is on the platform allowlist.' : 'Waiting for platform approval.',
            'approved_at' => $approved ? now() : null,
        ]);
        Audit::record('ai.provider_created', $p, ['type' => 'custom', 'host' => $host, 'approval' => $p->approval_status], $by, $tenantId);

        return $p;
    }

    /** Platform decision on a custom endpoint. Re-validates the address; never needs (or shows) the key. */
    public static function decide(AiProvider $p, bool $approve, ?User $by, ?string $note = null): AiProvider
    {
        abort_unless($p->isCustom(), 422, 'Only custom providers need approval.');
        if ($approve) {
            self::assertSafeCustomUrl((string) $p->base_url);
        }
        $p->forceFill([
            'approval_status' => $approve ? 'approved' : 'rejected',
            'approval_note' => mb_substr((string) $note, 0, 200) ?: ($approve ? 'Approved by the platform.' : 'Rejected by the platform.'),
            'approved_by' => $by?->id,
            'approved_at' => now(),
        ])->save();
        Audit::record($approve ? 'ai.provider_approved' : 'ai.provider_rejected', $p, ['host' => $p->host()], $by, $p->tenant_id);

        return $p;
    }

    /** Remove a custom provider, or forget the key of a named one. Never leaves a dangling active selection. */
    public static function forget(AiProvider $p, ?User $by = null): void
    {
        $settings = AiSetting::where('tenant_id', $p->tenant_id)->where('provider_id', $p->id)->first();
        if ($settings) {
            $settings->forceFill(['enabled' => false, 'api_key' => null, 'provider_id' => null, 'active_model_id' => null])->save();
        }
        if ($p->isCustom()) {
            AiModel::where('provider_id', $p->id)->delete();
            $p->delete();
        } else {
            $p->forceFill(['api_key' => null, 'connection_status' => 'untested', 'connection_message' => null, 'connection_checked_at' => null])->save();
        }
        Audit::record('ai.provider_removed', null, ['provider' => $p->slug], $by, $p->tenant_id);
    }

    // ------------------------------------------------------------------ discovery / tests

    /** @return array{ok:bool, status:int, json:?array, code:string, message:string} */
    private static function get(AiProvider $p, string $path, array $query = []): array
    {
        $key = (string) $p->api_key;
        if ($key === '') {
            return self::result(false, 0, null, 'no_key');
        }
        $base = self::baseUrl($p);
        if (! $base) {
            return self::result(false, 0, null, 'error');
        }
        $url = rtrim($base, '/') . $path;
        if ($p->isCustom() && ! str_starts_with(strtolower($url), 'https://')) {
            return self::result(false, 0, null, 'blocked');
        }
        $check = OutboundUrlGuard::check($url);                       // re-validated NOW, not only when it was saved
        if (! $check['ok']) {
            return self::result(false, 0, null, 'blocked');
        }
        $headers = $p->type === 'anthropic'
            ? ['x-api-key' => $key, 'anthropic-version' => AiProviderCatalog::ANTHROPIC_VERSION]
            : ['Authorization' => 'Bearer ' . $key];

        try {
            $res = Http::timeout(max(5, min(60, (int) $p->timeout_seconds)))
                ->withOptions(OutboundUrlGuard::httpOptions($check))
                ->withHeaders($headers)->acceptJson()->get($url, $query);
        } catch (ConnectionException) {
            return self::result(false, 0, null, 'unreachable');
        } catch (\Throwable) {
            return self::result(false, 0, null, 'unreachable');
        }

        return self::result($res->successful(), $res->status(), $res->successful() ? ($res->json() ?: []) : null, self::classify($res->status()));
    }

    private static function classify(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'ok',
            in_array($status, [401, 403], true) => 'invalid_credentials',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'unavailable',
            $status === 0 => 'unreachable',
            default => 'error',
        };
    }

    private static function result(bool $ok, int $status, ?array $json, string $code): array
    {
        return ['ok' => $ok, 'status' => $status, 'json' => $json, 'code' => $ok ? 'ok' : $code, 'message' => $ok ? self::MESSAGES['ok'] : (self::MESSAGES[$code] ?? self::MESSAGES['error'])];
    }

    /**
     * The provider's own model list (live: never a hard-coded catalogue).
     *
     * @return array{ok:bool, code:string, message:string, models:array<int,array{id:string,name:string,lifecycle:?string}>}
     */
    public static function discover(AiProvider $p): array
    {
        $path = $p->isCustom() ? $p->models_path : (AiProviderCatalog::TYPES[$p->type]['models_path'] ?? null);
        if (! $path) {
            return ['ok' => false, 'code' => 'no_catalog', 'message' => self::MESSAGES['no_catalog'], 'models' => []];
        }
        $r = self::get($p, $path, $p->type === 'anthropic' ? ['limit' => 1000] : []);
        if (! $r['ok']) {
            return ['ok' => false, 'code' => $r['code'], 'message' => $r['message'], 'models' => []];
        }
        $strip = AiProviderCatalog::TYPES[$p->type]['strip_model_prefix'] ?? '';
        $models = [];
        foreach (($r['json']['data'] ?? $r['json']['models'] ?? []) as $m) {
            $id = is_array($m) ? (string) ($m['id'] ?? $m['name'] ?? '') : (string) $m;
            if ($strip !== '' && str_starts_with($id, $strip)) {
                $id = substr($id, strlen($strip));
            }
            if ($id === '' || ! preg_match('/^[A-Za-z0-9._:\/\-]{1,150}$/', $id)) {
                continue;
            }
            $models[$id] = [
                'id' => $id,
                'name' => is_array($m) ? mb_substr((string) ($m['display_name'] ?? $m['displayName'] ?? $id), 0, 120) : $id,
                'lifecycle' => is_array($m) ? ($m['lifecycle'] ?? null) : null,
            ];
        }
        ksort($models);

        return ['ok' => true, 'code' => 'ok', 'message' => self::MESSAGES['ok'], 'models' => array_values($models)];
    }

    /** Provider-level check ("is the connection and the key good"), stored on the provider. Enables nothing. */
    public static function testConnection(AiProvider $p): AiProvider
    {
        $d = self::discover($p);
        $status = $d['code'];
        $message = $d['message'];
        if ($status === 'ok') {
            $message = 'Connected. ' . count($d['models']) . ' model(s) listed by the provider.';
        }
        $p->forceFill(['connection_status' => $status, 'connection_message' => mb_substr($message, 0, 200), 'connection_checked_at' => now()])->save();

        return $p;
    }

    /** Model-level check ("does THIS model answer with this key"): one tiny real request. Never enables anything. */
    public static function testModel(AiModel $m): AiModel
    {
        $p = $m->provider;
        $status = 'error';
        $message = self::MESSAGES['error'];
        if (! $p || (string) $p->api_key === '') {
            [$status, $message] = ['error', self::MESSAGES['no_key']];
        } elseif ($p->isCustom() && $p->approval_status !== 'approved') {
            [$status, $message] = ['error', 'This custom provider has not been approved by the platform yet.'];
        } else {
            $settings = new AiSetting([
                'tenant_id' => $m->tenant_id, 'provider' => $p->settingProvider(), 'base_url' => $p->isCustom() ? $p->base_url : null,
                'model' => $m->model_id, 'effort' => 'low', 'api_key' => $p->api_key, 'max_output_tokens' => 256,
                'timeout_seconds' => $p->timeout_seconds, 'json_mode' => $p->json_mode,
            ]);
            $res = app(AiResponder::class)->ping($settings);
            if ($res->ok()) {
                [$status, $message] = ['available', 'The model answered a test request.'];
            } else {
                $st = $res->status;
                [$status, $message] = match (true) {
                    in_array($st, [401, 403], true) => ['auth_failed', self::MESSAGES['invalid_credentials']],
                    in_array($st, [400, 404], true) => ['unavailable', 'The provider does not accept this model ID for this key (not found, retired or not enabled).'],
                    $st === 429 => ['rate_limited', self::MESSAGES['rate_limited']],
                    $st !== null && $st >= 500 => ['error', self::MESSAGES['unavailable']],
                    $st === 0 => ['error', self::MESSAGES['unreachable']],
                    default => ['error', str_contains((string) $res->error, 'valid JSON') ? 'The model answered, but not in the JSON format the assistant needs.' : self::MESSAGES['error']],
                };
            }
        }
        $m->forceFill(['test_status' => $status, 'test_message' => mb_substr($message, 0, 200), 'tested_at' => now()])->save();

        return $m;
    }

    // ------------------------------------------------------------------ models

    /**
     * Save a model for a provider. "Saved" is not "available": catalog_status records whether the provider's own list
     * contained it (when the provider has a list), test_status records an actual request.
     */
    public static function addModel(AiProvider $p, string $modelId, ?string $displayName, ?float $in, ?float $out, bool $checkCatalog = true): AiModel
    {
        $modelId = trim($modelId);
        if (! preg_match('/^[A-Za-z0-9._:\/\-]{1,150}$/', $modelId)) {
            throw ValidationException::withMessages(['model_id' => ['The model ID may only contain letters, numbers and . _ : / -']]);
        }
        $catalog = 'unknown';
        if ($checkCatalog && $p->isUsable()) {
            $d = self::discover($p);
            if ($d['ok']) {
                $catalog = collect($d['models'])->contains('id', $modelId) ? 'listed' : 'unlisted';
            }
        }
        $m = AiModel::firstOrNew(['provider_id' => $p->id, 'model_id' => $modelId]);
        $isNew = ! $m->exists;
        $m->fill([
            'tenant_id' => $p->tenant_id,
            'provider_key' => $p->settingProvider(),
            'display_name' => mb_substr(trim((string) $displayName) ?: $modelId, 0, 120),
            'catalog_status' => $catalog,
        ]);
        if ($in !== null && $out !== null) {
            $m->input_per_million = $in;
            $m->output_per_million = $out;
        }
        if ($isNew) {
            $m->enabled = true;
        }
        $m->save();

        return $m;
    }

    /** Make this model the one customers' answers use. Does NOT switch AI on: that stays an explicit admin choice. */
    public static function activate(AiModel $m): AiSetting
    {
        $p = $m->provider;
        if (! $p || ! $p->isUsable()) {
            throw ValidationException::withMessages(['model' => ['Save an API key for this provider first (custom providers also need approval).']]);
        }
        if (! $m->enabled) {
            throw ValidationException::withMessages(['model' => ['This model is disabled. Enable it first.']]);
        }

        return self::writeActive(AiSetting::forTenant($m->tenant_id), $p, $m);
    }

    private static function writeActive(AiSetting $s, AiProvider $p, AiModel $m): AiSetting
    {
        $s->forceFill([
            'provider' => $p->settingProvider(),
            'base_url' => $p->isCustom() ? $p->base_url : null,
            'model' => $m->model_id,
            'api_key' => $p->api_key,
            'provider_id' => $p->id,
            'active_model_id' => $m->id,
            'timeout_seconds' => $p->timeout_seconds,
            'json_mode' => $p->json_mode,
        ])->save();

        return $s;
    }

    /** After a key / timeout / address change of the ACTIVE provider, keep the engine row in step. */
    public static function resyncIfActive(AiProvider $p): void
    {
        $s = AiSetting::where('tenant_id', $p->tenant_id)->where('provider_id', $p->id)->first();
        if (! $s) {
            return;
        }
        $m = $s->active_model_id ? AiModel::find($s->active_model_id) : null;
        if ($m) {
            self::writeActive($s, $p, $m);
        }
    }

    /**
     * The legacy `POST ai/settings` (provider + model + key in one form) still works: mirror it into the provider and
     * model rows so the new screens and the engine always agree.
     */
    public static function syncFromLegacy(AiSetting $s): void
    {
        $type = AiProviderCatalog::typeFromSettingProvider((string) $s->provider);
        if ($type === 'custom') {
            $p = AiProvider::where('tenant_id', $s->tenant_id)->where('type', 'custom')->where('base_url', $s->base_url)->first();
            if (! $p && $s->base_url) {
                $host = strtolower((string) parse_url($s->base_url, PHP_URL_HOST));
                $allowed = self::hostAllowlisted($host);
                $p = AiProvider::create([
                    'tenant_id' => $s->tenant_id, 'slug' => 'custom-' . Str::lower(Str::random(6)), 'type' => 'custom', 'name' => 'Custom provider',
                    'base_url' => rtrim($s->base_url, '/'), 'enabled' => true,
                    'approval_status' => $allowed ? 'approved' : 'pending',
                    'approval_note' => $allowed ? 'Host is on the platform allowlist.' : 'Waiting for platform approval.',
                ]);
            }
            if (! $p) {
                return;
            }
        } else {
            $p = self::named($s->tenant_id, $type);
        }
        if ((string) $s->api_key !== '' && (string) $p->api_key !== (string) $s->api_key) {
            $p->api_key = (string) $s->api_key;
            $p->save();
        }
        $m = AiModel::firstOrCreate(
            ['provider_id' => $p->id, 'model_id' => (string) $s->model],
            ['tenant_id' => $s->tenant_id, 'provider_key' => $p->settingProvider(), 'display_name' => (string) $s->model, 'enabled' => true]
        );
        $s->forceFill(['provider_id' => $p->id, 'active_model_id' => $m->id])->save();
    }
}
