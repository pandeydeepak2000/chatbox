<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Ai\AiBudgetService;
use App\Services\Ai\AiProviderService;
use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** Multi-provider AI settings: five providers, models, custom endpoints, tenant isolation, secrets, SSRF. */
class AiProvidersTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    private const SECRET = 'sk-test-SECRET-KEY-9f8e7d6c';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
        // Deterministic DNS: every host resolves to a public address unless a test says otherwise.
        OutboundUrlGuard::$resolver = fn (string $host) => ['93.184.216.34'];
        Http::preventStrayRequests();   // a test never reaches a real provider; unmatched calls fail fast
    }

    protected function tearDown(): void
    {
        OutboundUrlGuard::$resolver = null;
        parent::tearDown();
    }

    private function owner(): self
    {
        app('auth')->forgetGuards();

        return $this->actingAs($this->ownerA, 'sanctum');
    }

    private function adminB(): User
    {
        return User::create(['tenant_id' => $this->tenantB->id, 'name' => 'B Admin', 'email' => 'adminb@b.test', 'password' => Hash::make('admin-pass-1'), 'role' => 'admin']);
    }

    private function superAdmin(): User
    {
        return User::create(['tenant_id' => null, 'name' => 'Platform', 'email' => 'root@platform.test', 'password' => Hash::make('super-pass-1'), 'role' => 'super_admin']);
    }

    private function saveKey(string $slug, string $key = self::SECRET)
    {
        return $this->owner()->postJson("/api/v1/ai/providers/{$slug}", ['api_key' => $key])->assertOk();
    }

    /** @var callable response of the faked provider; a test can replace it between calls */
    private $upstream;

    private function fakeUpstream(string $pattern): void
    {
        Http::fake([$pattern => fn () => ($this->upstream)()]);
    }

    private function chatBody(array $reply = ['action' => 'answer', 'reply' => 'ok', 'language' => 'en', 'confidence' => 0.9, 'cited_ids' => [], 'ticket_summary' => ['issue' => '', 'affected_service' => '', 'steps_tried' => '', 'results' => '', 'unresolved' => '']]): array
    {
        return ['choices' => [['message' => ['content' => json_encode($reply)], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10], 'model' => 'm'];
    }

    // ------------------------------------------------------------------ 1. the five providers

    public function test_all_five_providers_are_offered_in_the_required_order(): void
    {
        $res = $this->owner()->getJson('/api/v1/ai/providers')->assertOk();
        $this->assertSame(['openai', 'gemini', 'orvapi', 'anthropic', 'custom'], collect($res->json('data.options'))->pluck('type')->all());
        $this->assertSame(['OpenAI', 'Google Gemini', 'OrvAPI', 'Anthropic (Claude)', 'Custom Provider'], collect($res->json('data.options'))->pluck('label')->all());
        $this->assertSame('Official Google Gemini API', collect($res->json('data.options'))->firstWhere('type', 'gemini')['subtitle']);
        $this->assertSame(['openai', 'gemini', 'orvapi', 'anthropic'], collect($res->json('data.providers'))->pluck('type')->all(), 'Named providers are listed even before a key exists.');

        $legacy = $this->owner()->getJson('/api/v1/ai/settings')->assertOk();
        $this->assertSame(['openai', 'gemini', 'orvapi', 'anthropic', 'openai_compatible'], collect($legacy->json('data.providers'))->pluck('value')->all());
    }

    public function test_named_providers_use_their_fixed_official_endpoints(): void
    {
        $by = collect($this->owner()->getJson('/api/v1/ai/providers')->json('data.providers'))->keyBy('type');
        $this->assertSame('https://api.openai.com/v1', $by['openai']['base_url']);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/openai', $by['gemini']['base_url']);
        $this->assertSame('https://api.orvapi.com/v1', $by['orvapi']['base_url']);
        $this->assertSame('https://api.anthropic.com', $by['anthropic']['base_url']);
        // A named provider cannot be redirected to another host.
        $this->saveKey('openai');
        $this->owner()->postJson('/api/v1/ai/providers/openai', ['base_url' => 'https://evil.example/v1'])->assertOk();
        $this->assertNull(AiProvider::where('slug', 'openai')->value('base_url'));
    }

    // ------------------------------------------------------------------ 2. secrets

    public function test_api_keys_are_encrypted_hidden_and_never_logged(): void
    {
        $res = $this->saveKey('gemini');
        $this->assertStringNotContainsString(self::SECRET, $res->getContent());
        $this->assertTrue($res->json('data.api_key_set'));
        $this->assertStringEndsWith('9f8e7d6c' === substr(self::SECRET, -8) ? '7d6c' : '', $res->json('data.api_key_hint'));

        $raw = (string) DB::table('ai_providers')->where('slug', 'gemini')->value('api_key');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw, 'Stored encrypted.');
        $this->assertSame(self::SECRET, AiProvider::where('slug', 'gemini')->first()->api_key);

        $this->assertStringNotContainsString(self::SECRET, $this->owner()->getJson('/api/v1/ai/providers')->getContent());
        $this->assertStringNotContainsString(self::SECRET, $this->owner()->getJson('/api/v1/ai/settings')->getContent());
        $this->assertStringNotContainsString(self::SECRET, AuditLog::all()->toJson(), 'Audit trail never records the key.');

        // Activating a model copies the key into the engine row, still encrypted.
        $m = $this->owner()->postJson('/api/v1/ai/providers/gemini/models', ['model_id' => 'gemini-test', 'make_default' => true])->assertCreated();
        $this->assertStringNotContainsString(self::SECRET, (string) DB::table('ai_settings')->where('tenant_id', $this->tenantA->id)->value('api_key'));
        $this->assertStringNotContainsString(self::SECRET, $m->getContent());
    }

    // ------------------------------------------------------------------ 3. model discovery per provider

    public function test_model_discovery_works_for_every_provider_with_the_documented_endpoint_and_headers(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-alpha'], ['id' => 'gpt-beta']]], 200),
            'generativelanguage.googleapis.com/v1beta/openai/models' => Http::response(['data' => [['id' => 'models/gemini-one'], ['id' => 'models/gemini-two']]], 200),
            'api.orvapi.com/v1/models' => Http::response(['data' => [['id' => 'orv-model-a'], ['id' => 'orv-model-b']]], 200),
            'api.anthropic.com/v1/models*' => Http::response(['data' => [['id' => 'claude-x', 'display_name' => 'Claude X', 'lifecycle' => 'active']], 'has_more' => false], 200),
        ]);
        foreach (['openai', 'gemini', 'orvapi', 'anthropic'] as $slug) {
            $this->saveKey($slug);
        }

        $ids = fn (string $slug, array $q = []) => collect($this->owner()->postJson("/api/v1/ai/providers/{$slug}/discover", $q)->assertOk()->json('data.models'))->pluck('id')->all();
        $this->assertSame(['gpt-alpha', 'gpt-beta'], $ids('openai'));
        $this->assertSame(['gemini-one', 'gemini-two'], $ids('gemini'), 'Gemini ids lose the models/ prefix.');
        $this->assertSame(['orv-model-a', 'orv-model-b'], $ids('orvapi'));
        $this->assertSame(['claude-x'], $ids('anthropic'));
        $this->assertSame(['gpt-beta'], $ids('openai', ['q' => 'BETA']), 'Search filters the list.');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.openai.com/v1/models' && $r->hasHeader('Authorization', 'Bearer ' . self::SECRET));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/models' && $r->hasHeader('Authorization', 'Bearer ' . self::SECRET));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.orvapi.com/v1/models');
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.anthropic.com/v1/models') && $r->hasHeader('x-api-key', self::SECRET) && $r->hasHeader('anthropic-version') && str_contains($r->url(), 'limit=1000'));
    }

    public function test_models_are_never_a_hard_coded_list_and_a_listed_model_is_not_assumed_to_work(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-alpha']]], 200)]);
        $this->saveKey('openai');

        $listed = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'gpt-alpha', 'display_name' => 'Alpha'])->assertCreated();
        $this->assertSame('listed', $listed->json('data.catalog_status'));
        $this->assertSame('untested', $listed->json('data.test_status'), 'Listed is not tested.');

        $manual = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'gpt-not-in-list'])->assertCreated();
        $this->assertSame('unlisted', $manual->json('data.catalog_status'));
        $this->assertSame('gpt-not-in-list', $manual->json('data.display_name'));

        $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'bad id; drop table'])->assertStatus(422);
        $this->assertSame(2, AiModel::count());
    }

    // ------------------------------------------------------------------ 4. add custom provider + approval

    public function test_custom_provider_waits_for_platform_approval_and_cannot_be_used_before(): void
    {
        Http::fake(['llm.partner.test/*' => Http::response($this->chatBody(), 200)]);
        $res = $this->owner()->postJson('/api/v1/ai/providers/custom', [
            'name' => 'Partner LLM', 'base_url' => 'https://llm.partner.test/v1', 'api_key' => self::SECRET, 'models_path' => '/models', 'model_id' => 'partner-1',
        ])->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame('pending', $res->json('data.approval_status'));
        $this->assertStringNotContainsString(self::SECRET, $res->getContent());

        $modelId = $res->json('data.models.0.id');
        $blocked = $this->owner()->postJson("/api/v1/ai/models/{$modelId}/test")->assertOk();               // not approved: no call is made
        $this->assertSame('error', $blocked->json('data.test_status'));
        $this->assertStringContainsString('not been approved', $blocked->json('data.test_message'));
        Http::assertNothingSent();
        $this->owner()->postJson("/api/v1/ai/models/{$modelId}/default")->assertStatus(422);

        // Only a platform administrator decides, and sees metadata only.
        app('auth')->forgetGuards();
        $super = $this->superAdmin();
        $list = $this->actingAs($super, 'sanctum')->getJson('/api/v1/platform/ai/custom-providers')->assertOk();
        $this->assertSame('llm.partner.test', $list->json('data.0.host'));
        $this->assertSame('AmbitionHost', $list->json('data.0.company'));
        $this->assertStringNotContainsString(self::SECRET, $list->getContent());
        $this->owner()->postJson("/api/v1/platform/ai/custom-providers/{$id}/decision", ['decision' => 'approve'])->assertForbidden();   // a company admin cannot approve itself
        app('auth')->forgetGuards();
        $this->actingAs($super, 'sanctum')->postJson("/api/v1/platform/ai/custom-providers/{$id}/decision", ['decision' => 'approve', 'note' => 'ok'])->assertOk()->assertJsonPath('data.approval_status', 'approved');

        $this->owner()->postJson("/api/v1/ai/models/{$modelId}/test")->assertOk()->assertJsonPath('data.test_status', 'available');
        $this->owner()->postJson("/api/v1/ai/models/{$modelId}/default")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://llm.partner.test/v1/chat/completions');
    }

    public function test_an_allowlisted_host_is_approved_automatically_and_a_changed_address_needs_approval_again(): void
    {
        config(['chatbox.ai.custom_host_allowlist' => ['trusted.example']]);
        $res = $this->owner()->postJson('/api/v1/ai/providers/custom', ['name' => 'Trusted', 'base_url' => 'https://api.trusted.example/v1', 'api_key' => self::SECRET])->assertCreated();
        $this->assertSame('approved', $res->json('data.approval_status'));

        $this->owner()->postJson('/api/v1/ai/providers/' . $res->json('data.id'), ['base_url' => 'https://other.example/v1'])->assertOk()->assertJsonPath('data.approval_status', 'pending');
    }

    // ------------------------------------------------------------------ 5. unsafe custom URLs

    public function test_unsafe_custom_urls_are_rejected(): void
    {
        $bad = [
            'http://api.example.com/v1',                // not https
            'https://api.example.com:8443/v1',          // non-standard port
            'https://127.0.0.1/v1',                     // loopback IP literal
            'https://169.254.169.254/latest',           // cloud metadata service
            'https://10.0.0.5/v1',                      // private network
            'https://[::1]/v1',                         // IPv6 loopback
            'https://localhost/v1',                     // internal host name
            'https://metadata.internal/v1',             // internal suffix
            'https://user:pass@api.example.com/v1',     // credentials in the URL
            'https://api.example.com/v1?key=1',         // query string
            'ftp://api.example.com/v1',
            'not a url',
        ];
        foreach ($bad as $url) {
            $this->owner()->postJson('/api/v1/ai/providers/custom', ['name' => 'Bad', 'base_url' => $url, 'api_key' => self::SECRET])
                ->assertStatus(422, "must be rejected: {$url}");
        }
        $this->assertSame(0, AiProvider::where('type', 'custom')->count());

        // A public-looking host name that RESOLVES to a private address is rejected too.
        OutboundUrlGuard::$resolver = fn (string $host) => ['10.1.2.3'];
        $this->owner()->postJson('/api/v1/ai/providers/custom', ['name' => 'Rebind', 'base_url' => 'https://rebind.example.com/v1', 'api_key' => self::SECRET])->assertStatus(422);
    }

    public function test_the_destination_is_revalidated_at_request_time_and_redirects_are_never_followed(): void
    {
        config(['chatbox.ai.custom_host_allowlist' => ['later.example']]);
        $res = $this->owner()->postJson('/api/v1/ai/providers/custom', ['name' => 'Later', 'base_url' => 'https://api.later.example/v1', 'api_key' => self::SECRET, 'models_path' => '/models', 'model_id' => 'm1'])->assertCreated();
        $id = $res->json('data.id');

        // After approval the host's DNS is changed to an internal address (DNS rebinding): nothing is sent.
        Http::fake();
        OutboundUrlGuard::$resolver = fn (string $host) => ['169.254.169.254'];
        $this->owner()->postJson("/api/v1/ai/providers/{$id}/test")->assertStatus(200)->assertJsonPath('data.connection_status', 'blocked');
        Http::assertNothingSent();

        $options = OutboundUrlGuard::httpOptions(['host' => 'api.later.example', 'port' => 443, 'ip' => '93.184.216.34']);
        $this->assertFalse($options['allow_redirects'], 'Redirects (to internal hosts) are never followed.');
        $this->assertContains('api.later.example:443:93.184.216.34', $options['curl'][CURLOPT_RESOLVE], 'The vetted IP is pinned.');
    }

    // ------------------------------------------------------------------ 6. tenant isolation

    public function test_companies_never_see_or_touch_each_others_providers_models_or_keys(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-alpha']]], 200)]);
        $this->saveKey('openai');
        $model = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'gpt-alpha'])->json('data');
        $providerId = AiProvider::where('tenant_id', $this->tenantA->id)->value('id');

        $b = $this->adminB();
        app('auth')->forgetGuards();
        $this->actingAs($b, 'sanctum');
        $mine = $this->getJson('/api/v1/ai/providers')->assertOk();
        $this->assertFalse(collect($mine->json('data.providers'))->firstWhere('slug', 'openai')['api_key_set'], "B has no key of its own, and never A's.");
        $this->assertStringNotContainsString('7d6c', $mine->getContent());

        $this->postJson("/api/v1/ai/providers/{$providerId}", ['enabled' => false])->assertNotFound();
        $this->postJson("/api/v1/ai/providers/{$providerId}/test")->assertNotFound();
        $this->postJson("/api/v1/ai/providers/{$providerId}/discover")->assertNotFound();
        $this->postJson("/api/v1/ai/models/{$model['id']}/test")->assertNotFound();
        $this->postJson("/api/v1/ai/models/{$model['id']}/default")->assertNotFound();
        $this->deleteJson("/api/v1/ai/models/{$model['id']}")->assertNotFound();
        $this->deleteJson("/api/v1/ai/providers/{$providerId}")->assertNotFound();

        // B's own provider rows are separate (same slug, different company, different key).
        $this->postJson('/api/v1/ai/providers/openai', ['api_key' => 'sk-test-B-OWN-KEY-123456'])->assertOk();
        $this->assertSame(2, AiProvider::where('slug', 'openai')->count());
        $this->assertSame(self::SECRET, AiProvider::where('tenant_id', $this->tenantA->id)->where('slug', 'openai')->first()->api_key);
        $this->assertSame('sk-test-B-OWN-KEY-123456', AiProvider::where('tenant_id', $this->tenantB->id)->where('slug', 'openai')->first()->api_key);

        // Agents are not administrators.
        app('auth')->forgetGuards();
        $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/ai/providers')->assertForbidden();
    }

    public function test_the_platform_super_admin_cannot_read_company_providers_or_keys(): void
    {
        $this->saveKey('openai');
        app('auth')->forgetGuards();
        $super = $this->superAdmin();
        foreach ([['getJson', '/api/v1/ai/providers'], ['postJson', '/api/v1/ai/providers/openai'], ['getJson', '/api/v1/ai/settings'], ['postJson', '/api/v1/ai/providers/openai/discover']] as [$m, $url]) {
            $this->actingAs($super, 'sanctum')->{$m}($url)->assertForbidden();
            app('auth')->forgetGuards();
        }
    }

    // ------------------------------------------------------------------ 7. failures are classified, never relayed

    public function test_provider_failures_are_classified_and_provider_text_is_never_relayed(): void
    {
        $this->saveKey('openai');
        $this->fakeUpstream('api.openai.com/v1/models');
        $status = function (int $code) {
            $this->upstream = fn () => Http::response(['error' => ['message' => 'Incorrect API key provided: ' . self::SECRET]], $code);
            $r = $this->owner()->postJson('/api/v1/ai/providers/openai/test')->assertOk();
            $this->assertStringNotContainsString(self::SECRET, $r->getContent());
            $this->assertStringNotContainsString('Incorrect API key', $r->getContent());

            return $r->json('data.connection_status');
        };
        $this->assertSame('invalid_credentials', $status(401));
        $this->assertSame('invalid_credentials', $status(403));
        $this->assertSame('rate_limited', $status(429));
        $this->assertSame('unavailable', $status(503));

        $this->upstream = fn () => throw new ConnectionException('cURL error 28: Operation timed out');
        $this->assertSame('unreachable', $this->owner()->postJson('/api/v1/ai/providers/openai/test')->json('data.connection_status'));

        $this->upstream = fn () => Http::response(['data' => [['id' => 'a'], ['id' => 'b']]], 200);
        $ok = $this->owner()->postJson('/api/v1/ai/providers/openai/test')->assertOk();
        $this->assertSame('ok', $ok->json('data.connection_status'));
        $this->assertStringContainsString('2 model(s)', $ok->json('data.connection_message'));
        $this->assertFalse((bool) AiSetting::forTenant($this->tenantA->id)->enabled, 'A successful connection never switches AI on.');
    }

    public function test_model_test_distinguishes_available_unknown_model_bad_key_and_bad_format(): void
    {
        $this->saveKey('openai');
        $m = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'gpt-alpha'], )->json('data');
        $test = fn () => $this->owner()->postJson("/api/v1/ai/models/{$m['id']}/test")->assertOk();
        $this->fakeUpstream('api.openai.com/v1/chat/completions');

        $this->upstream = fn () => Http::response($this->chatBody(['ok' => true]), 200);
        $this->assertSame('available', $test()->json('data.test_status'));

        $this->upstream = fn () => Http::response(['error' => ['message' => 'The model `gpt-alpha` does not exist']], 404);
        $r = $test();
        $this->assertSame('unavailable', $r->json('data.test_status'));
        $this->assertStringNotContainsString('does not exist', $r->getContent());

        $this->upstream = fn () => Http::response([], 401);
        $this->assertSame('auth_failed', $test()->json('data.test_status'));
        $this->upstream = fn () => Http::response([], 429);
        $this->assertSame('rate_limited', $test()->json('data.test_status'));
        $this->upstream = fn () => throw new ConnectionException('timeout');
        $this->assertSame('error', $test()->json('data.test_status'));
        $this->upstream = fn () => Http::response(['choices' => [['message' => ['content' => 'Sure, here you go!']]]], 200);
        $r = $test();
        $this->assertSame('error', $r->json('data.test_status'));
        $this->assertStringContainsString('JSON format', $r->json('data.test_message'));
    }

    // ------------------------------------------------------------------ provider protocol differences (OrvAPI bug)

    public function test_response_format_is_only_sent_where_it_is_supported_orvapi_and_gemini_do_not_get_it(): void
    {
        foreach (['openai' => true, 'orvapi' => false, 'gemini' => false] as $slug => $expectsJsonMode) {
            $this->saveKey($slug);
            $m = $this->owner()->postJson("/api/v1/ai/providers/{$slug}/models", ['model_id' => 'm-' . $slug])->json('data');
            $base = ['openai' => 'api.openai.com/v1', 'orvapi' => 'api.orvapi.com/v1', 'gemini' => 'generativelanguage.googleapis.com/v1beta/openai'][$slug];
            Http::fake(["{$base}/*" => Http::response($this->chatBody(['ok' => true]), 200)]);

            $this->owner()->postJson("/api/v1/ai/models/{$m['id']}/test")->assertOk()->assertJsonPath('data.test_status', 'available');

            Http::assertSent(function (HttpRequest $r) use ($base, $expectsJsonMode) {
                return str_contains($r->url(), $base . '/chat/completions') && (isset($r->data()['response_format']) === $expectsJsonMode)
                    && $r->data()['model'] !== '' && $r->hasHeader('Authorization');
            });
        }
    }

    public function test_tolerant_json_parsing_for_providers_without_a_json_mode(): void
    {
        $parse = fn (string $t) => \App\Services\Ai\OpenAiCompatibleLlmClient::extractJson($t);
        $this->assertSame(['a' => 1], $parse('{"a":1}'));
        $this->assertSame(['a' => 1], $parse("```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], $parse('Here you go: {"a":1} hope that helps'));
        $this->assertNull($parse('no json here'));
    }

    // ------------------------------------------------------------------ 9. enabled / disabled, approval, pricing

    public function test_choosing_a_default_model_never_switches_ai_on_and_ai_state_has_clear_reasons(): void
    {
        $this->saveKey('openai');
        $m = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'gpt-alpha'])->json('data');
        $this->owner()->postJson("/api/v1/ai/models/{$m['id']}/default")->assertOk()->assertJsonPath('data.ai_enabled', false);
        $s = AiSetting::forTenant($this->tenantA->id)->fresh();
        $this->assertSame('openai', $s->provider);
        $this->assertSame('gpt-alpha', $s->model);
        $this->assertFalse((bool) $s->enabled);
        $this->assertSame('disabled', AiBudgetService::blockReason($s, null));

        $s->forceFill(['enabled' => true])->save();
        $this->assertSame('no_price', AiBudgetService::blockReason($s->fresh(), null), 'No price confirmed: AI stays off, nothing is invented.');

        $this->owner()->postJson("/api/v1/ai/models/{$m['id']}", ['input_per_million' => 0.5, 'output_per_million' => 1.5])->assertOk()->assertJsonPath('data.price_set', true);
        $this->assertNull(AiBudgetService::blockReason($s->fresh(), null));
        $this->assertSame(0.5, AiBudgetService::price($s->fresh())->input_per_million);

        $this->owner()->postJson("/api/v1/ai/models/{$m['id']}", ['enabled' => false])->assertOk();
        $this->assertSame('model_disabled', AiBudgetService::blockReason($s->fresh(), null));
        $this->owner()->postJson("/api/v1/ai/models/{$m['id']}", ['enabled' => true])->assertOk();

        $this->owner()->postJson('/api/v1/ai/providers/openai', ['enabled' => false])->assertOk();
        $this->assertSame('provider_disabled', AiBudgetService::blockReason($s->fresh(), null));
        $this->owner()->postJson('/api/v1/ai/providers/openai', ['enabled' => true])->assertOk();

        AiProvider::where('slug', 'openai')->update(['approval_status' => 'rejected']);
        $this->assertSame('provider_not_approved', AiBudgetService::blockReason($s->fresh(), null));
    }

    public function test_a_disabled_model_cannot_be_made_default_and_the_active_model_cannot_be_deleted(): void
    {
        $this->saveKey('openai');
        $a = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'm-a', 'make_default' => true])->json('data');
        $b = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'm-b'])->json('data');
        $this->owner()->postJson("/api/v1/ai/models/{$b['id']}", ['enabled' => false])->assertOk();
        $this->owner()->postJson("/api/v1/ai/models/{$b['id']}/default")->assertStatus(422);
        $this->owner()->deleteJson("/api/v1/ai/models/{$a['id']}")->assertStatus(422);
        $this->owner()->deleteJson("/api/v1/ai/models/{$b['id']}")->assertOk();
    }

    public function test_switching_provider_never_reuses_another_providers_key_and_removing_a_key_turns_ai_off(): void
    {
        $this->saveKey('openai');
        $a = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'm-a', 'make_default' => true])->json('data');
        AiSetting::forTenant($this->tenantA->id)->update(['enabled' => true]);

        $this->owner()->postJson('/api/v1/ai/providers/openai', ['clear_api_key' => true])->assertOk();
        $s = AiSetting::forTenant($this->tenantA->id)->fresh();
        $this->assertFalse((bool) $s->enabled);
        $this->assertSame('', (string) $s->api_key);
        $this->assertNull($s->provider_id);
        $this->assertSame('', (string) AiProvider::where('slug', 'openai')->first()->api_key);

        // Legacy form: switching to Gemini without typing a key must not carry the previous provider's key over.
        $this->saveKey('openai', 'sk-test-ANOTHER-KEY-55555555');
        $this->owner()->postJson('/api/v1/ai/settings', ['enabled' => false, 'provider' => 'openai', 'model' => 'm-a', 'monthly_cap_usd' => 10, 'daily_cap_usd' => 1, 'per_visitor_daily_messages' => 10])->assertOk();
        $this->owner()->postJson('/api/v1/ai/settings', ['enabled' => false, 'provider' => 'gemini', 'model' => 'gemini-x', 'monthly_cap_usd' => 10, 'daily_cap_usd' => 1, 'per_visitor_daily_messages' => 10])->assertOk();
        $this->assertSame('', (string) AiSetting::forTenant($this->tenantA->id)->fresh()->api_key);
        $this->assertSame('gemini', AiSetting::forTenant($this->tenantA->id)->fresh()->provider);
        $this->assertNotNull(AiProvider::where('tenant_id', $this->tenantA->id)->where('slug', 'gemini')->first(), 'The legacy form is mirrored into provider/model rows.');
    }

    public function test_existing_company_settings_are_backfilled_by_the_migration_logic(): void
    {
        // A company configured before this release: provider + key + model only in ai_settings.
        AiSetting::forTenant($this->tenantA->id)->forceFill(['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => self::SECRET])->save();
        AiProviderService::syncFromLegacy(AiSetting::forTenant($this->tenantA->id));

        $p = AiProvider::where('tenant_id', $this->tenantA->id)->where('slug', 'anthropic')->first();
        $this->assertSame(self::SECRET, $p->api_key);
        $this->assertSame('claude-opus-5-5', AiModel::where('provider_id', $p->id)->value('model_id'));
        $this->assertSame($p->id, AiSetting::forTenant($this->tenantA->id)->provider_id);
    }

    // ------------------------------------------------------------------ 11. usage tracking with the provider's own pricing

    public function test_usage_is_tracked_per_company_website_provider_and_model_with_the_company_confirmed_price(): void
    {
        $this->saveKey('openai');
        $m = $this->owner()->postJson('/api/v1/ai/providers/openai/models', ['model_id' => 'gpt-alpha', 'input_per_million' => 2, 'output_per_million' => 8, 'make_default' => true])->json('data');
        AiSetting::forTenant($this->tenantA->id)->update(['enabled' => true]);
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response($this->chatBody(['action' => 'escalate', 'reply' => 'x', 'language' => 'en', 'confidence' => 0.1, 'cited_ids' => [], 'ticket_summary' => ['issue' => '', 'affected_service' => '', 'steps_tried' => '', 'results' => '', 'unresolved' => '']]) + [], 200)]);

        $this->startChat($this->siteA, 'my wordpress plugin conflicts with ioncube loader after migrating from another provider');

        $log = \App\Models\AiUsageLog::firstOrFail();
        $this->assertSame($this->tenantA->id, $log->tenant_id);
        $this->assertSame($this->siteA->id, $log->property_id);
        $this->assertSame('openai', $log->provider);
        $this->assertSame('m', $log->model, 'The model the provider reports it used.');
        $this->assertSame(20, (int) $log->input_tokens);
        $this->assertSame(10, (int) $log->output_tokens);
        $this->assertEqualsWithDelta((20 * 2 + 10 * 8) / 1_000_000, (float) $log->cost_usd, 0.0000001);
        $this->assertSame(0, \App\Models\AiUsageLog::where('tenant_id', $this->tenantB->id)->count());
    }
}
