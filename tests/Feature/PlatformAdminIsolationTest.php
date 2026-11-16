<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** The platform super admin manages companies; a company's private data stays with that company's staff. */
class PlatformAdminIsolationTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
    }

    private function superAdmin(): User
    {
        return User::create(['tenant_id' => null, 'name' => 'Platform', 'email' => 'root@platform.test', 'password' => Hash::make('super-pass-1'), 'role' => 'super_admin']);
    }

    public function test_super_admin_cannot_read_or_act_on_company_conversations_tickets_leads_or_customers(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please, my invoice is wrong');
        $ticket = \App\Models\Ticket::firstOrFail();
        $super = $this->superAdmin();

        $forbidden = [
            ['getJson', '/api/v1/chat/conversations'],
            ['getJson', "/api/v1/chat/conversations/{$convId}"],
            ['postJson', "/api/v1/chat/conversations/{$convId}/messages", ['content' => 'hi']],
            ['postJson', "/api/v1/chat/conversations/{$convId}/takeover"],
            ['postJson', "/api/v1/chat/conversations/{$convId}/notes", ['content' => 'x']],
            ['getJson', '/api/v1/chat/stats'],
            ['getJson', '/api/v1/tickets'],
            ['getJson', "/api/v1/tickets/{$ticket->id}"],
            ['postJson', "/api/v1/tickets/{$ticket->id}/reply", ['content' => 'x']],
            ['getJson', '/api/v1/leads'],
            ['getJson', '/api/v1/leads/export'],
            ['getJson', '/api/v1/customers'],
            ['getJson', '/api/v1/knowledge'],
            ['getJson', '/api/v1/ai/settings'],
            ['getJson', '/api/v1/settings/telegram'],
            ['getJson', '/api/v1/analytics'],
            ['getJson', '/api/v1/crm/leads'],
            ['getJson', '/api/v1/notifications'],
        ];
        foreach ($forbidden as $call) {
            [$method, $url] = $call;
            $res = $this->actingAs($super, 'sanctum')->{$method}($url, $call[2] ?? []);
            $this->assertSame(403, $res->getStatusCode(), "{$method} {$url} must be forbidden for the platform admin, got {$res->getStatusCode()}");
            app('auth')->forgetGuards();
        }

        // Nothing was changed by the refused calls.
        $this->assertSame('human', Conversation::find($convId)->handler);
    }

    public function test_super_admin_still_manages_the_platform_and_company_staff_keep_access(): void
    {
        $this->startChat($this->siteA, 'hello');
        $super = $this->superAdmin();

        $this->actingAs($super, 'sanctum')->getJson('/api/v1/platform/overview')->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($super, 'sanctum')->getJson('/api/v1/platform/tenants')->assertOk();
        app('auth')->forgetGuards();

        $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/chat/conversations')->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/tickets')->assertOk();
        app('auth')->forgetGuards();
        $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/ai/settings')->assertOk();
    }

    public function test_company_staff_of_one_company_never_see_another_companys_conversation(): void
    {
        [$tokenB, $convB] = $this->startChat($this->siteB, 'hello from company B');

        $this->actingAs($this->agentA, 'sanctum')->getJson("/api/v1/chat/conversations/{$convB}")->assertNotFound();
        app('auth')->forgetGuards();
        $this->actingAs($this->ownerA, 'sanctum')->getJson("/api/v1/chat/conversations/{$convB}")->assertNotFound();
        app('auth')->forgetGuards();
        $ids = collect($this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/chat/conversations')->json('data.data') ?? $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/chat/conversations')->json('data'))->pluck('id')->all();
        $this->assertNotContains($convB, $ids);
    }
}
