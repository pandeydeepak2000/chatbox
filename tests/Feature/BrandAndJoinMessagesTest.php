<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ConversationStateMachine as SM;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/**
 * Real scenario from production: company registered as "SaluteHost", website named "SaluteHost Web Portal".
 * One company name everywhere, honest renewal handling, and exactly one "joined" notice written by the state machine.
 */
class BrandAndJoinMessagesTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    private Tenant $salute;
    private Property $portal;
    private User $saluteAdmin;
    private User $saluteAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00', 'Asia/Kolkata'));

        $this->salute = Tenant::create(['name' => 'SaluteHost', 'email' => 's@s.test', 'plan' => 'pro', 'status' => 'active']);
        $this->salute->forceFill(['ticket_prefix' => 'SAL'])->save();
        $this->saluteAdmin = User::create(['tenant_id' => $this->salute->id, 'name' => 'SaluteHost Admin', 'email' => 'admin@s.test', 'password' => Hash::make('admin-pass-1'), 'role' => 'admin']);
        $this->saluteAgent = User::create(['tenant_id' => $this->salute->id, 'name' => 'Alex', 'email' => 'alex@s.test', 'password' => Hash::make('alex-pass-12'), 'role' => 'agent']);
        $this->portal = Property::create(['tenant_id' => $this->salute->id, 'site_name' => 'SaluteHost Web Portal', 'site_url' => 'https://portal.salute.test', 'allowed_domains' => ['portal.salute.test'], 'is_active' => true, 'identity_mode' => 'pin_optional']);
        User::query()->update(['is_online' => false]);
    }

    private function texts(string $convId): string
    {
        return Message::where('conversation_id', $convId)->whereIn('sender_type', ['bot', 'system'])->orderBy('seq')->pluck('content')->implode("\n---\n");
    }

    public function test_one_company_name_is_used_everywhere_not_the_website_name(): void
    {
        $init = $this->withHeaders($this->origin($this->portal))->postJson('/api/v1/widget/init', ['property_key' => $this->portal->public_key])->assertOk();
        $this->assertSame('SaluteHost', $init->json('data.property.brand'));

        [$token, $convId] = $this->startChat($this->portal, 'hello');
        $this->assertStringContainsString('Thank you for contacting SaluteHost Support.', $this->texts($convId));
        $this->assertStringNotContainsString('Web Portal Support', $this->texts($convId));

        // The admin's chosen brand always wins.
        $this->portal->forceFill(['widget_config' => ['brand_name' => 'SaluteHost Cloud']])->save();
        \Illuminate\Support\Facades\Cache::forget('prop_pub_' . $this->portal->public_key);   // websites are cached by key for 2 minutes
        [$t2, $c2] = $this->startChat($this->portal, 'hello again');
        $this->assertStringContainsString('Thank you for contacting SaluteHost Cloud Support.', $this->texts($c2));
        $this->assertSame('SaluteHost Cloud', $this->withHeaders($this->origin($this->portal))->postJson('/api/v1/widget/init', ['property_key' => $this->portal->public_key])->json('data.property.brand'));
    }

    public function test_renewal_request_is_never_performed_and_gets_the_real_window_not_a_promise(): void
    {
        [$token, $convId] = $this->startChat($this->portal, 'please renew my domain');

        $first = $this->texts($convId);
        $this->assertStringContainsString("can't process renewals", $first);
        $this->assertStringContainsString('connecting you with our senior support team', $first);
        $this->assertStringNotContainsStringIgnoringCase('senior department', $first);
        $this->assertStringNotContainsString('1 to 2 hours', $first);
        $this->assertStringNotContainsStringIgnoringCase('has joined', $first);
        $this->assertSame(0, Ticket::count(), 'No ticket during the 60-second window.');

        $this->runOutEscalationWindow();
        $ticket = Ticket::firstOrFail();
        $this->assertSame('SAL-000001', $ticket->reference, 'SaluteHost has its own ticket numbering.');
        $this->assertSame('domain', $ticket->category);
        $this->assertStringContainsString('Ticket ID: #SAL-000001', $this->texts($convId));
        $this->assertStringNotContainsString('1 to 2 hours', str_replace('in 1–2 hours', '', $this->texts($convId)));
    }

    public function test_an_admin_who_takes_over_is_shown_as_the_company_support_team_once(): void
    {
        [$token, $convId] = $this->startChat($this->portal, 'please renew my domain');

        $join = fn () => $this->actingAs($this->saluteAdmin, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/takeover");
        $join()->assertOk();
        $join()->assertOk();

        $this->assertSame(1, substr_count($this->texts($convId), 'SaluteHost Support has joined the conversation.'));
        $this->assertStringNotContainsString('🟢', $this->texts($convId));
        $this->assertSame(SM::AGENT_ACTIVE, Conversation::find($convId)->support_state);
    }

    public function test_the_first_staff_reply_announces_the_join_exactly_once_with_the_state_machine_wording(): void
    {
        [$token, $convId] = $this->startChat($this->portal, 'please renew my domain');
        $reply = fn (string $t) => $this->actingAs($this->saluteAgent, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/messages", ['content' => $t]);

        $reply('Hi, checking your domain now.')->assertCreated();
        $reply('It is renewed for one year.')->assertCreated();

        $this->assertSame(1, substr_count($this->texts($convId), 'Alex from the support team has joined the conversation.'));
        $this->assertStringNotContainsString('has joined the chat', $this->texts($convId), 'The old private wording is gone.');
        $this->assertSame(0, Ticket::count(), 'The agent answered inside the window: no ticket.');
    }

    public function test_assigning_a_waiting_chat_says_assigned_not_joined(): void
    {
        [$token, $convId] = $this->startChat($this->portal, 'please renew my domain');
        $assign = fn () => $this->actingAs($this->saluteAdmin, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/assign", ['agent_id' => $this->saluteAgent->id]);
        $assign()->assertOk();
        $assign()->assertOk();

        $text = $this->texts($convId);
        $this->assertSame(1, substr_count($text, 'assigned to our support team'));
        $this->assertStringNotContainsStringIgnoringCase('has joined', $text, 'Assigned is not the same as joined.');
        $this->assertSame(SM::AGENT_ASSIGNED, Conversation::find($convId)->support_state);

        // When that person really acts, then (and only then) they join.
        $this->actingAs($this->saluteAgent, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/messages", ['content' => 'On it.'])->assertCreated();
        $this->assertSame(1, substr_count($this->texts($convId), 'Alex from the support team has joined the conversation.'));
    }

    public function test_another_company_never_sees_this_companys_brand(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hello');
        $this->assertStringContainsString('AmbitionHost Support', $this->texts($convId));
        $this->assertStringNotContainsString('SaluteHost', $this->texts($convId));
    }
}
