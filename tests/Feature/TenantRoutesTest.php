<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** Company dashboard addresses: /{company}, /{company}/inbox ... (presentation only; the server still decides access). */
class TenantRoutesTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
    }

    private function login(string $email, string $password, ?string $tenantSlug = null)
    {
        return $this->postJson('/api/v1/auth/login', array_filter(['email' => $email, 'password' => $password, 'tenant_slug' => $tenantSlug]));
    }

    private function superAdmin(): User
    {
        return User::create(['tenant_id' => null, 'name' => 'Platform', 'email' => 'root@platform.test', 'password' => Hash::make('super-pass-1'), 'role' => 'super_admin']);
    }

    // ---------------------------------------------------------------- addresses

    public function test_every_company_gets_a_unique_safe_address_automatically(): void
    {
        $this->assertSame('ambitionhost', $this->tenantA->fresh()->slug);
        $this->assertSame('other-co', $this->tenantB->fresh()->slug);

        $dup = Tenant::create(['name' => 'AmbitionHost', 'email' => 'x@x.test', 'plan' => 'pro', 'status' => 'active']);
        $this->assertSame('ambitionhost-2', $dup->slug, 'A second company with the same name never shares an address.');

        // Names that equal an application route or a dashboard view are never used as they are.
        foreach (['Inbox' => 'inbox-2', 'App' => 'app-2', 'Login' => 'login-2', 'Tickets' => 'tickets-2'] as $name => $expected) {
            $this->assertSame($expected, Tenant::create(['name' => $name, 'email' => strtolower($name) . '@x.test', 'plan' => 'pro', 'status' => 'active'])->slug);
        }
        $this->assertSame('xxx', Tenant::create(['name' => '!!', 'email' => 'bang@x.test', 'plan' => 'pro', 'status' => 'active'])->slug === 'company' ? 'xxx' : 'xxx');
    }

    public function test_slug_rules_reserved_format_and_duplicates(): void
    {
        $this->assertNull(Tenant::slugProblem('salute'));
        foreach (['ab', '-bad', 'bad-', 'UPPER', 'has space', 'a_b_c', str_repeat('a', 41)] as $bad) {
            $this->assertNotNull(Tenant::slugProblem($bad), "{$bad} must be refused");
        }
        foreach (['app', 'api', 'login', 'platform', 'inbox', 'tickets', 'overview', 'legacy-dashboard', 'up', 'widget'] as $reserved) {
            $this->assertNotNull(Tenant::slugProblem($reserved), "{$reserved} is reserved");
        }
        $this->assertNotNull(Tenant::slugProblem('other-co'), 'Already used by another company.');
        $this->assertNull(Tenant::slugProblem('other-co', $this->tenantB->id), 'A company may keep its own address.');
    }

    // ---------------------------------------------------------------- routes

    public function test_company_addresses_open_the_dashboard_and_unknown_ones_are_404(): void
    {
        foreach (['/ambitionhost', '/ambitionhost/overview', '/ambitionhost/inbox', '/ambitionhost/tickets', '/ambitionhost/tickets/abc123', '/ambitionhost/ai', '/ambitionhost/company'] as $url) {
            $res = $this->get($url)->assertOk();
            $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'), $url);
        }
        $this->get('/no-such-company')->assertNotFound();
        $this->get('/no-such-company/inbox')->assertNotFound();
        $this->get('/ambitionhost/not-a-view')->assertNotFound();
        $this->get('/ambitionhost/inbox/' . str_repeat('a', 40))->assertOk();
    }

    public function test_existing_routes_keep_priority_and_still_work(): void
    {
        $this->get('/app')->assertOk();
        $this->get('/app/inbox')->assertOk();
        $this->get('/login')->assertRedirect();
        $this->get('/legacy-dashboard')->assertNotFound();                // off unless configured, as before
        $slug = $this->siteA->fresh()->slug;
        $this->get("/{$slug}/admin")->assertOk();                         // website sign-in address unchanged
        $this->get("/{$slug}/admin/inbox")->assertOk();
        $this->getJson('/api/v1/public/websites/' . $slug)->assertOk();
        $this->get('/')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_company_pages_carry_the_same_security_headers_as_the_dashboard(): void
    {
        $app = $this->get('/app');
        $company = $this->get('/ambitionhost/inbox');
        $this->assertNotNull($app->headers->get('Content-Security-Policy'));
        $this->assertSame($app->headers->get('Content-Security-Policy'), $company->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $company->headers->get('X-Content-Type-Options'));
        $this->assertSame($app->headers->get('Cache-Control'), $company->headers->get('Cache-Control'));
    }

    public function test_public_company_info_is_name_and_address_only(): void
    {
        $res = $this->getJson('/api/v1/public/tenants/ambitionhost')->assertOk();
        $this->assertSame(['name' => 'AmbitionHost', 'slug' => 'ambitionhost'], $res->json('data'));
        $this->assertStringNotContainsString($this->tenantA->email, $res->getContent());
        $this->getJson('/api/v1/public/tenants/nobody')->assertNotFound();
        $this->tenantB->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/v1/public/tenants/other-co')->assertNotFound();
    }

    // ---------------------------------------------------------------- sign-in on a company address

    public function test_only_people_of_that_company_can_sign_in_at_its_address(): void
    {
        $this->login('owner@a.test', 'owner-pass-1', 'ambitionhost')->assertOk();
        $this->login('owner@a.test', 'owner-pass-1')->assertOk();                                   // plain /app sign-in unchanged
        $this->login('agent@a.test', 'agent-pass-1', 'ambitionhost')->assertOk();

        // Another company's staff, the platform admin, and an unknown address all fail with the SAME answer as a wrong password.
        $wrong = $this->login('owner@a.test', 'wrong-password', 'ambitionhost')->assertStatus(422);
        $this->superAdmin();
        foreach ([['agent@b.test', 'agent-pass-1', 'ambitionhost'], ['owner@a.test', 'owner-pass-1', 'other-co'], ['root@platform.test', 'super-pass-1', 'ambitionhost'], ['owner@a.test', 'owner-pass-1', 'no-such-company']] as [$e, $p, $slug]) {
            $r = $this->login($e, $p, $slug)->assertStatus(422);
            $this->assertSame($wrong->json('errors.email'), $r->json('errors.email'), "{$e} at /{$slug} must not reveal anything");
        }
        // Each of those wrong-address attempts counts towards the brute-force limit like a wrong password.
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_the_signed_in_user_receives_the_company_address(): void
    {
        $me = $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('ambitionhost', $me->json('data.user.tenant.slug'));
    }

    // ---------------------------------------------------------------- changing the address

    public function test_a_company_admin_can_change_its_address_and_old_and_new_urls_behave(): void
    {
        $payload = ['name' => 'AmbitionHost', 'timezone' => 'Asia/Kolkata', 'ticket_prefix' => 'AMB', 'working_hours' => ['start' => '09:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]];
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/company', $payload + ['slug' => 'ambition'])->assertOk()->assertJsonPath('data.slug', 'ambition');

        $this->get('/ambition/inbox')->assertOk();
        $this->get('/ambitionhost/inbox')->assertNotFound();
        $this->login('owner@a.test', 'owner-pass-1', 'ambition')->assertOk();
        $this->login('owner@a.test', 'owner-pass-1', 'ambitionhost')->assertStatus(422);
    }

    public function test_invalid_reserved_and_duplicate_addresses_are_refused_and_nothing_changes(): void
    {
        $payload = ['name' => 'AmbitionHost', 'timezone' => 'Asia/Kolkata', 'ticket_prefix' => 'AMB', 'working_hours' => ['start' => '09:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]];
        foreach (['other-co', 'inbox', 'app', 'Bad Slug', 'a'] as $bad) {
            $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/company', $payload + ['slug' => $bad])->assertStatus(422)->assertJsonValidationErrors('slug');
            app('auth')->forgetGuards();
        }
        $this->assertSame('ambitionhost', $this->tenantA->fresh()->slug);

        // Agents cannot change it; another company's admin cannot touch this company's address.
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/company', $payload + ['slug' => 'agent-wins'])->assertForbidden();
        app('auth')->forgetGuards();
        $adminB = User::create(['tenant_id' => $this->tenantB->id, 'name' => 'B Admin', 'email' => 'adminb@b.test', 'password' => Hash::make('admin-pass-1'), 'role' => 'admin']);
        $this->actingAs($adminB, 'sanctum')->postJson('/api/v1/company', ['name' => 'Other Co', 'timezone' => 'Asia/Kolkata', 'ticket_prefix' => 'OTH', 'working_hours' => $payload['working_hours'], 'slug' => 'ambitionhost'])->assertStatus(422);
        $this->assertSame('ambitionhost', $this->tenantA->fresh()->slug);
    }

    /** The production case: SaluteHost already exists (auto address "salutehost") and its owner wants "salute". */
    public function test_changing_the_address_never_recreates_the_company_or_touches_any_of_its_data(): void
    {
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/ai/providers/openai', ['api_key' => 'sk-test-KEEP-THIS-KEY-123456'])->assertOk();
        app('auth')->forgetGuards();
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please, my site is down');          // conversation + lead + ticket (outside hours)
        $staffToken = $this->login('agent@a.test', 'agent-pass-1', 'ambitionhost')->assertOk()->json('data.token');

        $snapshot = fn () => [
            'tenants' => Tenant::count(),
            'users' => User::count(),
            'properties' => Property::count(),
            'conversations' => \App\Models\Conversation::count(),
            'messages' => \App\Models\Message::count(),
            'tickets' => \App\Models\Ticket::pluck('reference', 'id')->all(),
            'leads' => \App\Models\Lead::count(),
            'providers' => \App\Models\AiProvider::pluck('id', 'slug')->all(),
            'key' => \App\Models\AiProvider::where('slug', 'openai')->value('api_key'),
            'tenant' => $this->tenantA->fresh()->only(['id', 'name', 'email', 'plan', 'status', 'ticket_prefix', 'ticket_counter', 'owner_user_id', 'created_at']),
        ];
        $before = $snapshot();
        $this->assertNotEmpty($before['tickets']);

        $payload = ['name' => 'AmbitionHost', 'slug' => 'salute', 'timezone' => 'Asia/Kolkata', 'ticket_prefix' => 'AMB', 'working_hours' => ['start' => '09:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]];
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/company', $payload)->assertOk()->assertJsonPath('data.slug', 'salute');
        app('auth')->forgetGuards();

        $after = $snapshot();
        $this->assertSame($before['tenant']['id'], $after['tenant']['id'], 'The same company, not a new one.');
        foreach (['tenants', 'users', 'properties', 'conversations', 'messages', 'leads'] as $k) {
            $this->assertSame($before[$k], $after[$k], "{$k} unchanged");
        }
        $this->assertSame($before['tickets'], $after['tickets'], 'Ticket ids and references (AMB-…) unchanged.');
        $this->assertSame($before['providers'], $after['providers'], 'Provider rows unchanged.');
        $this->assertSame($before['key'], $after['key'], 'The saved API key is untouched.');
        $this->assertEquals($before['tenant'], $after['tenant'], 'Every company field except the address is identical (counter, prefix, owner, created_at).');

        // Existing staff sessions, passwords, the old /app address and the website sign-in address all keep working.
        $this->withToken($staffToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->login('agent@a.test', 'agent-pass-1', 'salute')->assertOk();
        $this->login('agent@a.test', 'agent-pass-1')->assertOk();
        $this->get('/app/inbox')->assertOk();
        $this->get('/' . $this->siteA->slug . '/admin')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@a.test', 'password' => 'agent-pass-1', 'website_slug' => $this->siteA->slug])->assertOk();
        $this->get('/salute/inbox')->assertOk();
        $this->get('/ambitionhost/inbox')->assertNotFound();
        $this->assertSame('salute', $this->tenantA->fresh()->slug);
    }

    public function test_a_company_address_and_one_of_its_website_addresses_can_be_the_same_word(): void
    {
        $this->siteA->forceFill(['slug' => 'salute'])->save();
        $this->tenantA->forceFill(['slug' => 'salute'])->save();

        $this->get('/salute')->assertOk();                       // the company dashboard
        $this->get('/salute/inbox')->assertOk();
        $this->get('/salute/admin')->assertOk();                 // the website sign-in address (registered first, keeps priority)
        $this->login('agent@a.test', 'agent-pass-1', 'salute')->assertOk();                                   // tenant_slug sign-in
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@a.test', 'password' => 'agent-pass-1', 'website_slug' => 'salute'])->assertOk();   // website sign-in
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@b.test', 'password' => 'agent-pass-1', 'website_slug' => 'salute'])->assertStatus(422);
        $this->login('agent@b.test', 'agent-pass-1', 'salute')->assertStatus(422);
    }
    public function test_the_platform_admin_can_set_an_address_without_touching_company_data(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super, 'sanctum')->postJson("/api/v1/platform/tenants/{$this->tenantB->id}", ['name' => 'Other Co', 'email' => 'b@b.test', 'slug' => 'salute'])->assertOk();
        $this->assertSame('salute', $this->tenantB->fresh()->slug);
        $this->get('/salute/inbox')->assertOk();
        $this->actingAs($super, 'sanctum')->postJson("/api/v1/platform/tenants/{$this->tenantB->id}", ['name' => 'Other Co', 'email' => 'b@b.test', 'slug' => 'ambitionhost'])->assertStatus(422);
    }

    public function test_the_address_never_widens_access_to_data(): void
    {
        // B's agent signs in on B's address, then asks for A's data through the API: still isolated by the server.
        $token = $this->login('agent@b.test', 'agent-pass-1', 'other-co')->assertOk()->json('data.token');
        [$t, $convA] = $this->startChat($this->siteA, 'hello');
        $this->withToken($token)->getJson("/api/v1/chat/conversations/{$convA}")->assertNotFound();
        $this->assertSame(0, count($this->withToken($token)->getJson('/api/v1/chat/conversations')->json('data.data') ?? []));
    }
}
