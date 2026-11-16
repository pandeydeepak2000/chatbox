<?php

namespace Tests\Feature\Security;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeVersion;
use App\Models\StaffNotification;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/**
 * Regression tests for the security review of 7 Nov 2026 (docs/SECURITY_REVIEW.md).
 * Each test is named after the finding it protects.
 */
class SecurityReviewFixesTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
    }

    private function visitorWithChat(string $token = 'vtok_sec00001', ?string $siteId = null): Conversation
    {
        $site = $siteId ? \App\Models\Property::find($siteId) : $this->siteA;
        $v = Visitor::create(['property_id' => $site->id, 'visitor_token' => $token, 'name' => 'Guest', 'email' => 'guest@example.com']);

        return Conversation::create(['property_id' => $site->id, 'visitor_id' => $v->id, 'status' => 'open']);
    }

    public function test_sec01_removing_an_agents_last_website_never_grants_all_websites(): void
    {
        $this->agentA->setWebsiteAccess([$this->siteA2->id]);
        $this->visitorWithChat('vtok_sec00001', $this->siteA->id);

        $this->actingAs($this->agentA->fresh(), 'sanctum')->getJson('/api/v1/chat/conversations?filter=all')->assertOk()->assertJsonCount(0, 'data.data');

        // The website is deleted (pivot cascade) — the agent must end up with NO website, not all of them.
        \Illuminate\Support\Facades\DB::table('property_user')->where('user_id', $this->agentA->id)->delete();
        app('auth')->forgetGuards();
        $agent = User::find($this->agentA->id);
        $this->assertSame([], $agent->restrictedPropertyIds());
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/chat/conversations?filter=all')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($agent, 'sanctum')->get('/api/v1/crm/leads/export')->assertOk();
        $this->assertFalse($agent->canAccessProperty($this->siteA));
    }

    public function test_sec02_legacy_crm_endpoints_respect_website_restrictions(): void
    {
        Visitor::create(['property_id' => $this->siteA->id, 'visitor_token' => 'vtok_crm00001', 'name' => 'Only On Site A']);
        $this->agentA->setWebsiteAccess([$this->siteA2->id]);
        $agent = $this->agentA->fresh();

        $csv = $this->actingAs($agent, 'sanctum')->get('/api/v1/crm/leads/export')->streamedContent();
        $this->assertStringNotContainsString('Only On Site A', $csv);
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/crm/leads')->assertOk()->assertDontSee('Only On Site A');
        $visitor = Visitor::where('visitor_token', 'vtok_crm00001')->first();
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/chat/visitors/{$visitor->id}/block")->assertNotFound();
    }

    public function test_sec03_company_admins_cannot_take_over_the_owner_or_other_admins(): void
    {
        $admin2 = User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Second Admin', 'email' => 'admin2@a.test', 'password' => Hash::make('admin2-pass-1'), 'role' => 'admin']);

        $this->actingAs($admin2, 'sanctum')->postJson("/api/v1/agents/{$this->ownerA->id}/update", ['email' => 'evil@a.test', 'password' => 'NewPass12345'])->assertForbidden();
        $this->actingAs($admin2, 'sanctum')->deleteJson("/api/v1/agents/{$this->ownerA->id}")->assertForbidden();
        $this->assertSame('owner@a.test', $this->ownerA->fresh()->email);

        // Admins still manage agents; the owner manages admins but can never be deleted.
        $this->actingAs($admin2, 'sanctum')->postJson("/api/v1/agents/{$this->agentA->id}/update", ['name' => 'Renamed'])->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/agents/{$admin2->id}/update", ['name' => 'Renamed Admin'])->assertOk();
        $super = User::create(['tenant_id' => null, 'name' => 'Root', 'email' => 'root@platform.test', 'password' => Hash::make('root-pass-123'), 'role' => 'super_admin']);
        $this->actingAs($super, 'sanctum')->deleteJson("/api/v1/agents/{$this->ownerA->id}")->assertForbidden();
    }

    public function test_sec04_website_admins_cannot_disable_websites_or_allow_every_domain(): void
    {
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/websites/{$this->siteA->id}/staff", ['user_id' => $this->agentA->id, 'role' => 'admin'])->assertOk();
        app('auth')->forgetGuards();
        $agent = $this->agentA->fresh();

        $this->actingAs($agent, 'sanctum')->putJson("/api/v1/properties/{$this->siteA->id}", ['is_active' => false])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->putJson("/api/v1/properties/{$this->siteA->id}", ['allowed_domains' => ['*']])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->putJson("/api/v1/properties/{$this->siteA->id}", ['widget_config' => ['webhook_url' => 'https://hooks.example.com/x']])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/websites/{$this->siteA->id}/settings", ['allowed_domains' => ['*']])->assertForbidden();
        $this->assertTrue($this->siteA->fresh()->is_active);

        // Widget appearance values are validated on the server (they end up in CSS on public pages).
        $this->actingAs($agent, 'sanctum')->putJson("/api/v1/properties/{$this->siteA->id}", ['widget_config' => ['primary_color' => 'url(https://evil.test/px)']])->assertStatus(422);
        $this->actingAs($agent, 'sanctum')->putJson("/api/v1/properties/{$this->siteA->id}", ['widget_config' => ['primary_color' => '#112233', 'position' => 'left']])->assertOk();
    }

    public function test_sec05_property_key_must_match_exactly_and_suspended_companies_widgets_stop(): void
    {
        $origin = ['Origin' => 'https://host.a.test'];
        $this->withHeaders($origin)->postJson('/api/v1/widget/init', ['property_key' => strtoupper($this->siteA->public_key)])->assertNotFound();
        $this->withHeaders($origin)->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key])->assertOk();

        $super = User::create(['tenant_id' => null, 'name' => 'Root', 'email' => 'root@platform.test', 'password' => Hash::make('root-pass-123'), 'role' => 'super_admin']);
        $this->actingAs($super, 'sanctum')->postJson("/api/v1/platform/tenants/{$this->tenantA->id}/status", ['status' => 'suspended'])->assertOk();
        $this->withHeaders($origin)->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key])->assertNotFound();
    }

    public function test_sec06_changing_email_or_password_needs_the_current_password(): void
    {
        $this->actingAs($this->agentA, 'sanctum')->putJson('/api/v1/auth/profile', ['name' => 'Chandrika', 'email' => 'attacker@evil.test'])->assertStatus(422);
        $this->actingAs($this->agentA, 'sanctum')->putJson('/api/v1/auth/profile', ['name' => 'Chandrika', 'email' => 'agent@a.test', 'password' => 'NewPass12345'])->assertStatus(422);
        $this->assertSame('agent@a.test', $this->agentA->fresh()->email);

        $this->actingAs($this->agentA, 'sanctum')->putJson('/api/v1/auth/profile', ['name' => 'Chandrika S', 'email' => 'agent@a.test'])->assertOk();
        $this->actingAs($this->agentA, 'sanctum')->putJson('/api/v1/auth/profile', ['name' => 'Chandrika S', 'email' => 'new@a.test', 'current_password' => 'agent-pass-1'])->assertOk();
        $this->assertSame('new@a.test', $this->agentA->fresh()->email);
    }

    public function test_sec07_staff_login_locks_per_account_across_ips(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->postJson('/api/v1/auth/login', ['email' => 'owner@a.test', 'password' => "wrong-{$i}"]);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.99'])->postJson('/api/v1/auth/login', ['email' => 'owner@a.test', 'password' => 'owner-pass-1'])->assertStatus(429);
    }

    public function test_sec08_parallel_wrong_pins_cannot_exceed_five_attempts(): void
    {
        $origin = ['Origin' => 'https://host.a.test'];
        $this->withHeaders($origin)->postJson('/api/v1/widget/customer/signup', ['property_key' => $this->siteA->public_key, 'email' => 'race@example.com', 'pin' => '482916'])->assertCreated();
        $account = CustomerAccount::where('email_normalized', 'race@example.com')->first();

        // Simulate 5 attempts that are still "in flight" (counted before their PIN check finished).
        $account->forceFill(['failed_attempts' => 5])->save();
        $this->withHeaders($origin)->postJson('/api/v1/widget/customer/login', ['property_key' => $this->siteA->public_key, 'email' => 'race@example.com', 'pin' => '482916'])->assertStatus(422);
        $this->assertTrue($account->fresh()->isLocked(), 'A sixth attempt locks the account instead of being checked.');
    }

    public function test_sec09_human_request_alerts_are_throttled_per_conversation(): void
    {
        $conv = $this->visitorWithChat();
        $esc = \App\Models\Escalation::create([
            'tenant_id' => $this->tenantA->id, 'property_id' => $conv->property_id, 'conversation_id' => $conv->id, 'reason' => 'customer_request',
            'escalated_at' => now(), 'deadline_at' => now()->addSeconds(60), 'status' => 'waiting', 'open_key' => $conv->id,
        ]);
        Notifier::escalationRequested($conv, $esc);
        Notifier::escalationRequested($conv, $esc);
        Notifier::escalationRequested($conv, $esc);
        $this->assertSame(1, StaffNotification::where('type', 'human.requested')->where('user_id', $this->ownerA->id)->count());
    }

    public function test_sec10_maintenance_deletes_unsent_uploads_and_fails_stuck_documents(): void
    {
        Storage::disk('local')->put('attachments/orphan.png', 'x');
        $orphan = Attachment::create(['tenant_id' => $this->tenantA->id, 'property_id' => $this->siteA->id, 'uploader_type' => 'visitor', 'uploader_id' => 'v1', 'disk' => 'local', 'path' => 'attachments/orphan.png', 'original_name' => 'a.png', 'mime' => 'image/png', 'size' => 1, 'sha256' => str_repeat('a', 64)]);
        $orphan->forceFill(['created_at' => now()->subDays(2)])->save();

        $doc = KnowledgeDocument::create(['tenant_id' => $this->tenantA->id, 'title' => 'Big file', 'source_type' => 'file', 'status' => 'processing', 'created_by' => $this->ownerA->id]);
        $version = KnowledgeVersion::create(['document_id' => $doc->id, 'version' => 1, 'status' => 'processing', 'file_path' => 'knowledge/x.xlsx']);
        KnowledgeVersion::whereKey($version->id)->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('chatbox:maintenance')->assertSuccessful();

        $this->assertNull(Attachment::find($orphan->id));
        Storage::disk('local')->assertMissing('attachments/orphan.png');
        $this->assertSame('failed', $version->fresh()->status);
        $this->assertSame('failed', $doc->fresh()->status);
    }

    public function test_sec11_dashboard_pages_send_csp_and_legacy_dashboard_is_off(): void
    {
        $res = $this->get('/app');
        $res->assertOk();
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        // Scripts are allowed by hash only — no 'unsafe-inline' for the new dashboard.
        $this->assertMatchesRegularExpression("/script-src 'self' 'sha256-/", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $res->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $this->get('/legacy-dashboard')->assertNotFound();
    }
}
