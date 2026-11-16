<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Message;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiResponder;
use App\Services\Ai\SupportBot;
use App\Services\ConversationStateMachine as SM;
use App\Services\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/**
 * Premium support experience: honest escalation, state machine, agent takeover, truthful identity answers,
 * single Telegram alert and strict company isolation of automated answers.
 */
class PremiumSupportFlowTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00', 'Asia/Kolkata')); // inside working hours
    }

    private function botTexts(string $convId): array
    {
        return Message::where('conversation_id', $convId)->whereIn('sender_type', ['bot', 'system'])->orderBy('seq')->pluck('content')->all();
    }

    private function online(): void
    {
        $this->agentA->forceFill(['is_online' => true, 'last_active_at' => now()])->save();
    }

    // ---------------------------------------------------------------- company isolation of automated answers

    public function test_faq_of_another_company_is_never_used_and_clarification_names_no_company(): void
    {
        Faq::create(['property_id' => $this->siteB->id, 'question' => 'What is the price of the Business plan?', 'answer' => 'OtherCo Business plan costs Rs 999.', 'is_active' => true]);

        [$token, $convId] = $this->startChat($this->siteA, 'what is the price of the business plan');
        $all = implode("\n", $this->botTexts($convId));

        $this->assertStringNotContainsString('999', $all, "Another company's FAQ answer must never reach this customer.");
        $this->assertStringNotContainsStringIgnoringCase('Hermes', $all);
        $this->assertStringNotContainsStringIgnoringCase('n8n', $all);
        $this->assertStringNotContainsStringIgnoringCase('SaluteHost', $all);
    }

    public function test_faq_needs_a_real_overlap_with_the_question_not_just_a_word_in_the_answer(): void
    {
        Faq::create(['property_id' => $this->siteA->id, 'question' => 'How do I reset my client area password?', 'answer' => 'Open the login page and press Forgot Password. Our refund policy is separate.', 'is_active' => true]);

        $this->assertNull(SupportBot::faqMatch($this->siteA, 'refund policy'), 'A word that appears only in the answer body must not trigger the FAQ.');
        $this->assertNotNull(SupportBot::faqMatch($this->siteA, 'how to reset client area password'));
    }

    // ---------------------------------------------------------------- honest escalation (team online)

    public function test_escalation_shows_the_real_window_and_never_claims_a_completed_transfer(): void
    {
        $this->online();
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');

        $conv = Conversation::find($convId);
        $this->assertSame('human', $conv->handler, 'automation stops immediately');
        $this->assertSame(SM::QUEUED_FOR_TEAM, $conv->support_state);

        $all = implode("\n", $this->botTexts($convId));
        $this->assertStringContainsString("Please wait a moment. We're connecting you with our senior support team.", $all);
        $this->assertStringNotContainsStringIgnoringCase('has joined', $all, 'No agent has joined yet, so nothing may say so.');
        $this->assertStringNotContainsStringIgnoringCase('transferred', $all);

        // The widget gets the server's remaining seconds, and never the internal state names.
        $sync = $this->withHeaders($this->origin($this->siteA))->getJson("/api/v1/widget/conversations/{$convId}/sync?visitor_token={$token}&after_seq=0")->assertOk();
        $this->assertTrue($sync->json('data.waiting_senior'));
        $this->assertSame(60, $sync->json('data.senior_wait_remaining'));
        $this->assertStringNotContainsString('QUEUED_FOR_TEAM', $sync->getContent());
    }

    public function test_escalation_is_recorded_in_the_state_log_once(): void
    {
        $this->online();
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');

        $this->assertSame(
            [[SM::AUTO_HANDLING, SM::ESCALATION_REQUESTED], [SM::ESCALATION_REQUESTED, SM::QUEUED_FOR_TEAM]],
            DB::table('conversation_state_logs')->where('conversation_id', $convId)->orderBy('id')->get()->map(fn ($r) => [$r->from_state, $r->to_state])->all()
        );
        $this->assertNotNull(Conversation::find($convId)->escalation_requested_at);
    }

    public function test_double_click_on_talk_to_a_human_does_not_duplicate_notice_or_ticket(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hello there, I need help with my hosting account');
        $press = fn (string $key) => $this->withHeaders($this->origin($this->siteA))->postJson("/api/v1/widget/conversations/{$convId}/escalate", ['visitor_token' => $token, 'idempotency_key' => $key]);
        $this->online();

        $press('k1')->assertOk();
        $after1 = count($this->botTexts($convId));
        $press('k2')->assertOk();
        $press('k3')->assertOk();

        $this->assertSame($after1, count($this->botTexts($convId)), 'Second and third press must not post another notice.');
        $this->assertLessThanOrEqual(1, Ticket::count());
    }

    // ---------------------------------------------------------------- team offline

    public function test_offline_team_is_stated_honestly_with_ticket_and_working_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 22:30', 'Asia/Kolkata'));
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');

        $all = implode("\n", $this->botTexts($convId));
        $this->assertStringContainsString('AMB-000001', $all);
        $this->assertStringContainsString('09:00', $all);
        $this->assertStringNotContainsStringIgnoringCase('senior team', $all, 'Nobody is available, so no "senior team is reviewing" claim.');
        $this->assertSame(SM::QUEUED_FOR_TEAM, Conversation::find($convId)->support_state);
    }

    // ---------------------------------------------------------------- agent takeover

    public function test_agent_takeover_is_authorised_logged_announced_once_and_stops_automation(): void
    {
        $this->online();
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');

        $join = fn () => $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/takeover");
        $join()->assertOk();
        $join()->assertOk(); // double click / second tab

        $conv = Conversation::find($convId);
        $this->assertSame(SM::AGENT_ACTIVE, $conv->support_state);
        $joined = Message::where('conversation_id', $convId)->where('sender_type', 'system')->where('content', 'like', '%joined the conversation%')->get();
        $this->assertCount(1, $joined, 'The join notice appears exactly once.');
        $this->assertStringContainsString('Chandrika from the support team has joined the conversation.', $joined->first()->content);

        // Automated replies stay off once the agent is in.
        $before = Message::where('conversation_id', $convId)->where('sender_type', 'bot')->count();
        $this->say($this->siteA, $token, $convId, 'what are your plans and pricing?')->assertCreated()->assertJsonPath('bot_reply', null);
        $this->assertSame($before, Message::where('conversation_id', $convId)->where('sender_type', 'bot')->count());

        $this->assertDatabaseHas('conversation_state_logs', ['conversation_id' => $convId, 'to_state' => SM::AGENT_ACTIVE, 'actor_id' => $this->agentA->id, 'actor_type' => 'agent']);
    }

    public function test_other_company_agent_and_platform_super_admin_cannot_change_state(): void
    {
        $this->online();
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');
        $conv = Conversation::find($convId);

        $this->assertFalse(SM::transition($conv, SM::AGENT_ACTIVE, $this->agentB), "Another company's agent is refused.");

        $super = User::create(['tenant_id' => null, 'name' => 'Platform', 'email' => 'root@platform.test', 'password' => Hash::make('super-pass-1'), 'role' => 'super_admin']);
        $this->assertFalse(SM::transition($conv, SM::AGENT_ACTIVE, $super), 'The platform admin does not work inside a company inbox.');

        $this->assertSame(SM::QUEUED_FOR_TEAM, $conv->fresh()->support_state);
        $this->actingAs($this->agentB, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/takeover")->assertNotFound();
    }

    public function test_illegal_and_repeated_transitions_are_refused_or_idempotent(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hello, what are your hosting plans');
        $conv = Conversation::find($convId);

        $this->assertSame(SM::AUTO_HANDLING, $conv->support_state);
        $this->assertSame(SM::CHANGED, SM::moveTo($conv, SM::QUEUED_FOR_TEAM));
        $this->assertSame(SM::SAME, SM::moveTo($conv, SM::QUEUED_FOR_TEAM), 'Repeating a state is a no-op.');
        $this->assertSame(SM::REFUSED, SM::moveTo($conv, SM::WAITING_FOR_CUSTOMER), 'Not allowed straight from the queue.');
        $this->assertFalse(SM::transition($conv, 'NOT_A_STATE'));
        $this->assertSame(1, DB::table('conversation_state_logs')->where('conversation_id', $convId)->where('to_state', SM::QUEUED_FOR_TEAM)->count());
    }

    public function test_assignment_moves_to_assigned_and_tells_the_customer_only_that_it_was_assigned(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 22:30', 'Asia/Kolkata'));
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');
        $ticket = Ticket::firstOrFail();

        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/update", ['assigned_to' => $this->agentA->id])->assertOk();

        $this->assertSame(SM::AGENT_ASSIGNED, Conversation::find($convId)->support_state);
        $all = implode("\n", $this->botTexts($convId));
        $this->assertStringContainsString('assigned to our support team', $all);
        $this->assertStringNotContainsString('has joined', $all, 'Assigned is not the same as joined.');
    }

    public function test_ticket_status_changes_drive_waiting_resolved_and_closed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 22:30', 'Asia/Kolkata'));
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');
        $ticket = Ticket::firstOrFail();
        $conv = Conversation::find($convId);
        SM::moveTo($conv, SM::AGENT_ACTIVE, $this->agentA);

        $set = fn (string $status) => $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/update", ['status' => $status])->assertOk();
        $set('pending_customer');
        $this->assertSame(SM::WAITING_FOR_CUSTOMER, $conv->fresh()->support_state);
        $set('resolved');
        $this->assertSame(SM::RESOLVED, $conv->fresh()->support_state);
        $set('closed');
        $this->assertSame(SM::CLOSED, $conv->fresh()->support_state);
    }

    // ---------------------------------------------------------------- truthful identity answers

    public function test_asking_if_it_is_a_bot_gets_a_truthful_answer_not_a_fake_transfer(): void
    {
        foreach (['Are you a bot?', 'kya aap bot ho?', 'is this a real person'] as $i => $question) {
            [$token, $convId] = $this->startChat($this->siteA, $question);
            $reply = implode("\n", $this->botTexts($convId));

            $this->assertMatchesRegularExpression('/automated support assistant/i', $reply, "Case {$i}: must say it is automated.");
            $this->assertStringContainsString('AmbitionHost', $reply, 'The registered company name, not the website name.');
            $this->assertSame('ai', Conversation::find($convId)->handler, 'Not escalated by a mere identity question.');
            $this->assertSame(0, Ticket::count());
        }
    }

    public function test_an_explicit_request_for_a_person_is_still_escalated_not_answered_as_identity(): void
    {
        $this->assertFalse(SupportBot::asksIfAutomated('I want to talk to a real person'));
        $this->assertTrue(SupportBot::wantsHuman('I want to talk to a real person'));
        $this->assertTrue(SupportBot::asksIfAutomated('are you a robot?'));
        $this->assertFalse(SupportBot::asksIfAutomated('How do I reset my password?'));
    }

    public function test_customer_facing_replies_never_contain_technical_labels(): void
    {
        $this->online();
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');
        $all = implode("\n", $this->botTexts($convId));

        foreach (['AI Assistant', 'Chatbot', 'Bot Reply', 'Generated by AI', 'Automated Response', 'LLM', 'Anthropic', 'OpenAI'] as $label) {
            $this->assertStringNotContainsStringIgnoringCase($label, $all);
        }
    }

    // ---------------------------------------------------------------- Telegram: internal, single, minimal

    public function test_one_escalation_sends_one_telegram_alert_with_company_deadline_and_secure_link(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/settings/telegram', [
            'name' => 'Team', 'bot_token' => '1234567:' . str_repeat('A', 35), 'chat_id' => '-1001234567890', 'events' => ['chat.started', 'human.requested', 'ticket.created'],
        ])->assertCreated();
        $this->online();

        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');
        $press = fn (string $key) => $this->withHeaders($this->origin($this->siteA))->postJson("/api/v1/widget/conversations/{$convId}/escalate", ['visitor_token' => $token, 'idempotency_key' => $key]);
        $press('a')->assertOk();
        $press('b')->assertOk();

        $this->assertCount(1, NotificationDelivery::where('event', 'chat.started')->get(), 'One live chat = one chat alert.');
        $alerts = NotificationDelivery::where('event', 'human.requested')->get();
        $this->assertCount(1, $alerts, 'One escalation = one senior-support alert, however many code paths report it.');

        $text = $alerts->first()->message;
        $this->assertStringContainsString('AmbitionHost', $text);
        $this->assertStringContainsString('Ambition Hosting', $text);
        $this->assertStringContainsString('join before', $text);
        $this->assertStringContainsString('/app/inbox?id=' . $convId, $text);
        $this->assertStringContainsString('sign-in required', $text);
    }
    public function test_masked_email_hides_most_of_the_address(): void
    {
        $this->assertSame('pr***@example.com', Notifier::maskEmail('private.person@example.com'));
        $this->assertSame('ab***', Notifier::maskEmail('abcdef'));
    }

    // ---------------------------------------------------------------- AI path: current topic and company only

    public function test_ai_retrieval_uses_the_current_question_so_an_old_topic_cannot_bleed_in(): void
    {
        $responder = new AiResponder();
        $m = new \ReflectionMethod($responder, 'retrievalQuery');
        $m->setAccessible(true);

        $history = [
            ['role' => 'user', 'content' => 'how do I point my domain nameservers to your hosting'],
            ['role' => 'assistant', 'content' => 'Use ns1 and ns2 from your welcome email.'],
            ['role' => 'user', 'content' => 'What is your refund policy for annual shared hosting plans?'],
        ];
        $this->assertStringNotContainsString('nameservers', $m->invoke($responder, $history), 'A full new question stands on its own.');

        $history[] = ['role' => 'assistant', 'content' => 'Refunds are available within 30 days.'];
        $history[] = ['role' => 'user', 'content' => 'and monthly?'];
        $this->assertStringContainsString('refund policy', $m->invoke($responder, $history), 'A short follow-up keeps the previous question as context.');
    }

    public function test_ai_system_prompt_is_company_scoped_and_forbids_inventing_agents(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hello');
        $conv = Conversation::with('property')->find($convId);

        $responder = new AiResponder();
        $m = new \ReflectionMethod($responder, 'systemPrompt');
        $m->setAccessible(true);
        $prompt = strtolower($m->invoke($responder, $conv, AiSetting::forTenant($this->tenantA->id)));

        $this->assertStringContainsString('ambitionhost support', $prompt);
        $this->assertStringContainsString('never use prices, plans or policies of any other company', $prompt);
        $this->assertStringContainsString('never invent a name or a colleague', $prompt);
        $this->assertStringNotContainsString('salutehost', $prompt);
    }
}
