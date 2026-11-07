<?php

namespace Tests\Feature\Security;

use App\Models\CannedResponse;
use App\Models\Conversation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 1 audit: security regression tests.
 *
 * Every test here asserts the SECURE / CORRECT behaviour.
 * A failing test = a confirmed defect in the current code (see docs/PHASE1_AUDIT.md).
 * They are expected to fail until the Phase 3 fixes land, then they must stay green.
 *
 * Run only these:  php artisan test --group=security-audit
 */
#[Group('security-audit')]
class Phase1AuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private User $agentA;
    private User $agentB;
    private Property $propA;
    private Property $propB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['name' => 'Company A', 'email' => 'a@a.test', 'plan' => 'pro', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Company B', 'email' => 'b@b.test', 'plan' => 'pro', 'status' => 'active']);

        $this->adminA = User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Admin A', 'email' => 'admin@a.test', 'password' => Hash::make('old-password-A'), 'role' => 'admin']);
        $this->agentA = User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Agent A', 'email' => 'agent@a.test', 'password' => Hash::make('pw-agent-A'), 'role' => 'agent']);
        $this->agentB = User::create(['tenant_id' => $this->tenantB->id, 'name' => 'Agent B', 'email' => 'agent@b.test', 'password' => Hash::make('pw-agent-B'), 'role' => 'agent']);

        $this->propA = Property::create([
            'tenant_id' => $this->tenantA->id, 'site_name' => 'Site A', 'site_url' => 'https://a.test',
            'allowed_domains' => ['a.test'], 'is_active' => true,
            'widget_config' => ['primary_color' => '#000', 'webhook_url' => 'https://hooks.a.test/x', 'webhook_secret' => 'whsec_TOP_SECRET'],
        ]);
        $this->propB = Property::create([
            'tenant_id' => $this->tenantB->id, 'site_name' => 'Site B', 'site_url' => 'https://b.test',
            'allowed_domains' => ['b.test'], 'is_active' => true,
        ]);
    }

    private function visitorFor(Property $p, string $token = 'vtok_test_1'): Visitor
    {
        return Visitor::create(['property_id' => $p->id, 'visitor_token' => $token, 'name' => 'Guest']);
    }

    /** C1: anyone can reset any account's password with only the email address. */
    public function test_forgot_password_requires_a_verified_reset_token(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'admin@a.test',
            'new_password' => 'attacker-chosen-1',
        ]);

        $this->assertTrue(
            Hash::check('old-password-A', $this->adminA->fresh()->password),
            'Password was changed without any reset token / email verification.'
        );
    }

    /** C2: public widget init returns the webhook signing secret. */
    public function test_widget_init_does_not_expose_webhook_secret_or_url(): void
    {
        $res = $this->withHeaders(['Origin' => 'https://a.test'])
            ->postJson('/api/v1/widget/init', ['property_key' => $this->propA->public_key]);

        $res->assertOk();
        $this->assertStringNotContainsString('whsec_TOP_SECRET', $res->getContent());
        $this->assertStringNotContainsString('hooks.a.test', $res->getContent());
    }

    /** C3: visitor-supplied name is stored raw and rendered via innerHTML in the dashboard (stored XSS -> token theft). */
    public function test_visitor_profile_fields_are_not_returned_as_raw_html_to_staff(): void
    {
        $payload = '<img src=x onerror=alert(document.cookie)>';

        $this->withHeaders(['Origin' => 'https://a.test'])->postJson('/api/v1/widget/init', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_xss',
            'name' => $payload,
            'phone' => '<svg onload=1>',
        ])->assertOk();

        $leads = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/crm/leads');
        $leads->assertOk();
        $this->assertStringNotContainsString('<img', json_decode($leads->getContent(), true)['data'][0]['name'] ?? '');
        $this->assertStringNotContainsString('<svg', json_decode($leads->getContent(), true)['data'][0]['phone'] ?? '');
    }

    /** C4a: uploaded file keeps the client-chosen extension (PNG bytes saved as .html in public/). */
    public function test_widget_upload_does_not_trust_client_extension(): void
    {
        $this->visitorFor($this->propA, 'vtok_up');
        $png = UploadedFile::fake()->image('real.png');
        $evil = new UploadedFile($png->getPathname(), 'evil.html', 'image/png', null, true);

        $res = $this->postJson('/api/v1/widget/upload', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_up',
            'file' => $evil,
        ]);

        $url = (string) $res->json('url');
        $this->assertStringEndsNotWith('.html', $url, 'Upload stored with attacker-controlled .html extension: ' . $url);
    }

    /** C4b: SVG (can carry script) is accepted and served from the app's own origin. */
    public function test_widget_upload_rejects_svg(): void
    {
        $this->visitorFor($this->propA, 'vtok_svg');
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $res = $this->postJson('/api/v1/widget/upload', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_svg',
            'file' => $svg,
        ]);

        $this->assertNotEquals(200, $res->status(), 'SVG upload accepted.');
    }

    /** H1: domain whitelist trusts the request body "origin" field over the real Origin header. */
    public function test_domain_whitelist_cannot_be_bypassed_with_body_origin(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.test'])
            ->postJson('/api/v1/widget/init', [
                'property_key' => $this->propA->public_key,
                'origin' => 'https://a.test',
            ])
            ->assertStatus(403);
    }

    /** H2: canned responses of another tenant readable by passing property_id. */
    public function test_canned_responses_are_tenant_isolated(): void
    {
        CannedResponse::create(['property_id' => $this->propA->id, 'shortcut' => '/secret', 'content' => 'TENANT_A_PRIVATE_TEXT']);

        $res = $this->actingAs($this->agentB, 'sanctum')
            ->getJson('/api/v1/chat/canned-responses?property_id=' . $this->propA->id);

        $this->assertStringNotContainsString('TENANT_A_PRIVATE_TEXT', $res->getContent());
    }

    /** H3: conversation can be assigned to an agent of a different company. */
    public function test_conversation_cannot_be_assigned_to_other_tenant_agent(): void
    {
        $v = $this->visitorFor($this->propA);
        $conv = Conversation::create(['property_id' => $this->propA->id, 'visitor_id' => $v->id, 'status' => 'unassigned']);

        $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$conv->id}/assign", ['agent_id' => $this->agentB->id]);

        $this->assertNotEquals($this->agentB->id, $conv->fresh()->assigned_agent_id, 'Tenant A chat assigned to Tenant B agent.');
    }

    /** H4: webhook test endpoint sends server-side requests to internal addresses (SSRF). */
    public function test_webhook_test_blocks_internal_addresses(): void
    {
        Http::fake();

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/v1/properties/{$this->propA->id}/test-webhook", ['webhook_url' => 'http://169.254.169.254/latest/meta-data/']);

        Http::assertNothingSent();
    }

    /** H5: agents (non-admin) receive the property secret_key. */
    public function test_agents_do_not_receive_property_secret_key(): void
    {
        $res = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/properties');
        $res->assertOk();
        $this->assertStringNotContainsString($this->propA->secret_key, $res->getContent());
    }

    /** M1: CSV export allows spreadsheet formula injection from visitor-controlled fields. */
    public function test_csv_export_neutralises_formulas(): void
    {
        Visitor::create(['property_id' => $this->propA->id, 'visitor_token' => 'vtok_csv', 'name' => '=HYPERLINK("http://evil.test","click")']);

        $res = $this->actingAs($this->agentA, 'sanctum')->get('/api/v1/crm/leads/export');
        $csv = $res->streamedContent();

        $this->assertDoesNotMatchRegularExpression('/(^|,)"?=HYPERLINK/m', $csv, 'Formula written unescaped into CSV.');
    }

    /** B1: bot greeting hard-codes "SaluteHost" for every tenant. */
    public function test_bot_greeting_is_not_hardcoded_to_one_brand(): void
    {
        $this->visitorFor($this->propB, 'vtok_brand');

        $res = $this->postJson('/api/v1/widget/start', [
            'property_key' => $this->propB->public_key,
            'visitor_token' => 'vtok_brand',
            'initial_message' => 'hello there',
        ])->assertCreated();

        $this->assertStringNotContainsString('SaluteHost', (string) $res->json('data.bot_reply.content'));
    }

    /** B2: messages are HTML-encoded before storage, so the widget (which escapes again) shows &#039; etc. */
    public function test_message_text_is_stored_as_plain_text(): void
    {
        $v = $this->visitorFor($this->propA);
        $conv = Conversation::create(['property_id' => $this->propA->id, 'visitor_id' => $v->id, 'status' => 'open']);

        $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$conv->id}/messages", ['content' => "don't worry & relax"])
            ->assertCreated();

        $this->assertDatabaseHas('messages', ['conversation_id' => $conv->id, 'content' => "don't worry & relax"]);
    }

    /** B3: agent typing endpoint is not wrapped in try/catch -> 500 when realtime server is down (cPanel). */
    public function test_agent_typing_survives_realtime_server_outage(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => 'a',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);

        $v = $this->visitorFor($this->propA);
        $conv = Conversation::create(['property_id' => $this->propA->id, 'visitor_id' => $v->id, 'status' => 'open']);

        $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$conv->id}/typing", ['is_typing' => true])
            ->assertOk();
    }

    /** B4: polling sync uses created_at > last.created_at, so messages written in the same second are skipped forever. */
    public function test_polling_sync_does_not_skip_messages_sent_in_the_same_second(): void
    {
        $v = $this->visitorFor($this->propA, 'vtok_sync');
        $conv = Conversation::create(['property_id' => $this->propA->id, 'visitor_id' => $v->id, 'status' => 'open']);
        $ts = now()->startOfSecond();

        $first = \App\Models\Message::create(['conversation_id' => $conv->id, 'sender_type' => 'visitor', 'message_type' => 'text', 'content' => 'first']);
        $second = \App\Models\Message::create(['conversation_id' => $conv->id, 'sender_type' => 'agent', 'message_type' => 'text', 'content' => 'second']);
        $first->forceFill(['created_at' => $ts])->save();
        $second->forceFill(['created_at' => $ts])->save();

        $res = $this->getJson("/api/v1/widget/conversations/{$conv->id}/sync?visitor_token=vtok_sync&after_id={$first->id}");

        $this->assertContains('second', collect($res->json('data.messages'))->pluck('content')->all(), 'Same-second message lost by polling sync.');
    }

    /** H6: the general "api" rate limiter is defined but never attached to the api middleware group. */
    public function test_public_widget_endpoints_are_rate_limited(): void
    {
        $limited = false;
        for ($i = 0; $i < 80; $i++) {
            $status = $this->postJson('/api/v1/widget/heartbeat', ['property_key' => 'x', 'visitor_token' => 'y'])->status();
            if ($status === 429) { $limited = true; break; }
        }
        $this->assertTrue($limited, '80 rapid requests to a public endpoint were never throttled.');
    }

    protected function tearDown(): void
    {
        foreach (File::glob(public_path('uploads/attachments/att_*')) as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }
}
