<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PlatformController;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** Stages 2 and 6: website admin URLs, website roles, company settings, plans/subscriptions, analytics. */
class WorkspaceAndPlatformTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
    }

    public function test_every_website_gets_a_unique_slug_and_admin_url(): void
    {
        $this->assertSame('ambition-hosting', $this->siteA->slug);
        $dup = Property::create(['tenant_id' => $this->tenantB->id, 'site_name' => 'Ambition Hosting', 'site_url' => 'https://x.test']);
        $this->assertSame('ambition-hosting-2', $dup->slug);
        $this->assertStringEndsWith('/ambition-hosting/admin', $this->siteA->adminUrl());
        $this->assertNotNull(Property::slugProblem('admin'));
        $this->assertNotNull(Property::slugProblem('ambition-hosting'));

        $this->getJson('/api/v1/public/websites/ambition-hosting')->assertOk()->assertJsonPath('data.site_name', 'Ambition Hosting');
        $this->getJson('/api/v1/public/websites/nope')->assertNotFound();
        $this->get('/ambition-hosting/admin')->assertOk();
    }

    public function test_website_admin_url_only_lets_staff_of_that_website_in(): void
    {
        $this->agentA->setWebsiteAccess([$this->siteA2->id]);
        $login = fn ($email, $pw, $slug) => $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $pw, 'website_slug' => $slug]);

        $denied = $login('agent@a.test', 'agent-pass-1', $this->siteA->slug)->assertStatus(422);
        $wrongPw = $login('agent@a.test', 'bad-password', $this->siteA2->slug)->assertStatus(422);
        $this->assertSame($wrongPw->json('message'), $denied->json('message'), 'No hint whether the website or the password was wrong.');

        $ok = $login('agent@a.test', 'agent-pass-1', $this->siteA2->slug)->assertOk();
        $this->assertSame([$this->siteA2->id], collect($ok->json('data.properties'))->pluck('id')->all(), 'Restricted staff only receive their websites.');
        $this->assertTrue($ok->json('data.permissions.website_restricted'));

        $login('agent@b.test', 'agent-pass-1', $this->siteA->slug)->assertStatus(422);
        $owner = $login('owner@a.test', 'owner-pass-1', $this->siteA->slug)->assertOk();
        $this->assertCount(2, $owner->json('data.properties'));
        $this->assertTrue($owner->json('data.permissions.is_company_owner'));
    }

    public function test_website_admin_role_can_manage_only_that_website(): void
    {
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/websites/{$this->siteA->id}/staff", ['user_id' => $this->agentA->id, 'role' => 'admin'])->assertOk();
        $agent = $this->agentA->fresh();

        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/websites/{$this->siteA->id}/settings", ['identity_mode' => 'pin_required', 'slug' => 'ambition-host'])->assertOk()->assertJsonPath('data.slug', 'ambition-host');
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/websites/{$this->siteA2->id}/settings", ['identity_mode' => 'pin_required'])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/websites/{$this->siteA->id}/settings", ['is_active' => false])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/properties/{$this->siteA->id}/faqs", ['question' => 'Q?', 'answer' => 'A'])->assertCreated();
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/properties/{$this->siteA2->id}/faqs", ['question' => 'Q?', 'answer' => 'A'])->assertNotFound();
        $this->actingAs($this->agentB, 'sanctum')->postJson("/api/v1/websites/{$this->siteA->id}/settings", ['identity_mode' => 'guest_only'])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/websites/{$this->siteA->id}/staff", ['user_id' => $this->agentA->id, 'role' => null])->assertForbidden();

        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/websites/{$this->siteA2->id}/settings", ['slug' => 'ambition-host'])->assertStatus(422);
    }

    public function test_company_settings_working_hours_and_sales_owner(): void
    {
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/company', [])->assertForbidden();
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/company', [
            'name' => 'AmbitionHost', 'timezone' => 'Asia/Kolkata', 'ticket_prefix' => 'AMB',
            'working_hours' => ['start' => '09:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5, 6, 7]],
            'sales_followup_user_id' => $this->agentB->id,
        ])->assertStatus(422);
        $res = $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/company', [
            'name' => 'AmbitionHost', 'timezone' => 'Asia/Kolkata', 'ticket_prefix' => 'AMB',
            'working_hours' => ['start' => '10:00', 'end' => '19:00', 'days' => [1, 2, 3, 4, 5, 6]],
            'sales_followup_user_id' => $this->agentA->id,
        ])->assertOk();
        $this->assertSame('10:00', $res->json('data.working_hours.start'));
        $this->assertSame($this->agentA->id, $this->tenantA->fresh()->settings['sales_followup_user_id']);
    }

    public function test_plan_limits_are_enforced_and_only_super_admin_manages_subscriptions(): void
    {
        PlatformController::createSubscription($this->tenantA->id, 'free'); // free: 1 website, 2 staff
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/properties', ['site_name' => 'Third', 'site_url' => 'https://third.test'])->assertStatus(422)->assertJsonPath('errors.plan.0', fn ($m) => str_contains($m, 'Free plan'));
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/agents', ['name' => 'X', 'email' => 'x@a.test', 'password' => 'password-123'])->assertStatus(422);

        $super = User::create(['tenant_id' => null, 'name' => 'Super', 'email' => 'super@p.test', 'password' => Hash::make('super-pass-1'), 'role' => 'super_admin']);
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/platform/tenants/{$this->tenantA->id}/subscription", ['plan_code' => 'internal', 'status' => 'active'])->assertForbidden();
        $this->actingAs($super, 'sanctum')->postJson("/api/v1/platform/tenants/{$this->tenantA->id}/subscription", ['plan_code' => 'internal', 'status' => 'active'])->assertOk();
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/properties', ['site_name' => 'Third', 'site_url' => 'https://third.test'])->assertCreated();

        $overview = $this->actingAs($super, 'sanctum')->getJson('/api/v1/platform/overview')->assertOk();
        $this->assertSame('internal', collect($overview->json('data.tenants'))->firstWhere('id', $this->tenantA->id)['plan']['code']);

        $this->actingAs($super, 'sanctum')->postJson("/api/v1/platform/tenants/{$this->tenantB->id}/status", ['status' => 'suspended'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@b.test', 'password' => 'agent-pass-1'])->assertStatus(403);
    }

    public function test_analytics_are_scoped_to_accessible_websites(): void
    {
        $this->startChat($this->siteA, 'talk to a human please');
        $this->startChat($this->siteB, 'talk to a human please');

        $a = $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/analytics?days=7')->assertOk();
        $this->assertSame(1, $a->json('data.conversations'));
        $this->assertSame(1, $a->json('data.tickets_open'));

        $this->agentA->setWebsiteAccess([$this->siteA2->id]);
        $restricted = $this->actingAs($this->agentA->fresh(), 'sanctum')->getJson('/api/v1/analytics?days=7')->assertOk();
        $this->assertSame(0, $restricted->json('data.conversations'));
    }
}
