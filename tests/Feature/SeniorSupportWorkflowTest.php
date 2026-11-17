<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Faq;
use App\Models\Message;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Ticket;
use App\Services\Ai\LlmResult;
use App\Services\ConversationStateMachine as SM;
use App\Services\Knowledge\KnowledgeService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/**
 * The original live-support workflow, with a controllable clock (no real waiting):
 *   chat opened -> Telegram event -> e-mail/PIN -> FAQ / AI -> real 60 s senior window -> agent joins OR ticket
 *   -> Telegram reminders every 30 minutes until resolved.
 */
class SeniorSupportWorkflowTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    private const UNANSWERABLE = 'my wordpress plugin conflicts with ioncube loader after migrating from another provider';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
        $this->tenantB->forceFill(['ticket_prefix' => 'SAL'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Asia/Kolkata')); // inside working hours
        $this->telegram = fn () => Http::response(['ok' => true, 'result' => []], 200);
        Http::fake(['api.telegram.org/*' => fn () => ($this->telegram)()]);
    }

    /** @var callable returns the fake Telegram API response (tests may replace it to simulate outages) */
    private $telegram;

    // ------------------------------------------------------------------ helpers

    private function channel(\App\Models\Tenant $tenant, string $chatId, array $events = ['chat.started', 'human.requested', 'ticket.created', 'tickets.reminders']): NotificationChannel
    {
        return NotificationChannel::create(['tenant_id' => $tenant->id, 'name' => 'Team ' . $chatId, 'bot_token' => '1234567:' . str_repeat('A', 35), 'chat_id' => $chatId, 'events' => $events]);
    }

    private function deliveries(string $event, ?string $chatId = null)
    {
        $q = NotificationDelivery::where('event', $event);
        if ($chatId) {
            $q->whereHas('channel', fn ($c) => $c->where('chat_id', $chatId));
        }

        return $q->get();
    }

    private function sync(string $token, string $convId, $site = null)
    {
        return $this->withHeaders($this->origin($site ?? $this->siteA))->getJson("/api/v1/widget/conversations/{$convId}/sync?visitor_token={$token}&after_seq=0")->assertOk();
    }

    private function press(string $token, string $convId, string $key = 'k', $site = null)
    {
        return $this->withHeaders($this->origin($site ?? $this->siteA))->postJson("/api/v1/widget/conversations/{$convId}/escalate", ['visitor_token' => $token, 'idempotency_key' => $key]);
    }

    private function texts(string $convId): string
    {
        return Message::where('conversation_id', $convId)->whereIn('sender_type', ['bot', 'system'])->orderBy('seq')->pluck('content')->implode("\n---\n");
    }

    private function takeover(string $convId, $user = null)
    {
        return $this->actingAs($user ?? $this->agentA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/takeover");
    }

    /** Start a chat whose question nobody can answer (no FAQ, AI off) -> senior window opens. */
    private function unansweredChat($site = null): array
    {
        return $this->startChat($site ?? $this->siteA, self::UNANSWERABLE);
    }

    private function aiAnswerSetup(): void
    {
        KnowledgeService::approve(KnowledgeService::createManual($this->ownerA, $this->siteA->id, 'Nameservers', 'To point your domain use ns1.ambition.test and ns2.ambition.test.'), $this->ownerA);
        $fake = $this->enableAi($this->tenantA);
        $fake->respond(['action' => 'answer', 'reply' => 'Use ns1.ambition.test and ns2.ambition.test.', 'language' => 'en', 'confidence' => 0.9, 'cited_ids' => ['x'], 'ticket_summary' => ['issue' => '', 'affected_service' => '', 'steps_tried' => '', 'results' => '', 'unresolved' => '']]);
        $fake->during = function () use ($fake) {
            preg_match('/<passage id="([^"]+)"/', end($fake->calls)['messages'][count(end($fake->calls)['messages']) - 1]['content'], $m);
            $fake->queue[0] = new LlmResult(array_merge($fake->queue[0]->data, ['cited_ids' => [$m[1] ?? 'none']]), 1200, 150, 0, 'end_turn', null, 'claude-opus-5-5');
        };
    }

    // ------------------------------------------------------------------ 1. Telegram when the live chat starts

    public function test_01_telegram_event_is_queued_when_the_chat_opens_before_pin_and_never_duplicated(): void
    {
        $this->channel($this->tenantA, '-100111');
        $init = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key])->assertOk();
        $token = $init->json('data.visitor.token');
        $this->assertSame(0, Conversation::count(), 'No question, no e-mail, no PIN yet.');

        $open = fn () => $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/session', ['property_key' => $this->siteA->public_key, 'visitor_token' => $token]);
        $first = $open()->assertOk()->assertJsonPath('data.created', true);
        $open()->assertOk()->assertJsonPath('data.created', false);   // refresh
        $open()->assertOk()->assertJsonPath('data.created', false);   // repeated click

        $alerts = $this->deliveries('chat.started');
        $this->assertCount(1, $alerts, 'One live chat = one Telegram event, however often it is opened.');
        $text = $alerts->first()->message;
        $this->assertSame('sent', $alerts->first()->status);
        $this->assertStringContainsString('AmbitionHost', $text);
        $this->assertStringContainsString('Ambition Hosting', $text);
        $this->assertStringContainsString($first->json('data.reference'), $text);
        $this->assertStringContainsString('Visitor — verification pending', $text);
        $this->assertStringContainsString('Started: 05 Nov 2026, 12:00:00 PM Asia/Kolkata', $text);
        $this->assertStringContainsString('sign-in required', $text);

        // The first question later joins the SAME chat: same reference, no second alert.
        $start = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/start', ['property_key' => $this->siteA->public_key, 'visitor_token' => $token, 'initial_message' => 'hello there']);
        $start->assertCreated();
        $this->assertSame($first->json('data.reference'), Conversation::first()->chat_reference);
        $this->assertCount(1, $this->deliveries('chat.started'));
        $this->assertDatabaseHas('chat_sessions', ['reference' => $first->json('data.reference'), 'conversation_id' => Conversation::first()->id]);
    }

    public function test_01b_a_client_that_never_calls_session_still_gets_exactly_one_alert_at_start(): void
    {
        $this->channel($this->tenantA, '-100111');
        $this->startChat($this->siteA, 'hello');

        $this->assertCount(1, $this->deliveries('chat.started'));
    }

    public function test_01c_failed_telegram_is_recorded_retried_and_never_reported_as_delivered(): void
    {
        $calls = 0;
        $this->telegram = function () use (&$calls) {
            return ++$calls === 1 ? Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429) : Http::response(['ok' => true, 'result' => []], 200);
        };
        $this->channel($this->tenantA, '-100111');
        $this->startChat($this->siteA, 'hello');

        $d = $this->deliveries('chat.started')->first();
        $this->assertSame('failed', $d->status, 'The event is durably recorded even though Telegram refused it.');
        $this->assertSame(1, $d->attempts);
        $this->assertStringContainsString('Too Many Requests', $d->last_error);

        $this->travel(5)->minutes();
        $this->artisan('chatbox:reminders')->assertSuccessful();
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertSame(2, $d->fresh()->attempts);

        // A row that never got its first attempt (process died after the response) is rescued by cron as well.
        $orphan = NotificationDelivery::create(['tenant_id' => $this->tenantA->id, 'channel_id' => NotificationChannel::first()->id, 'event' => 'chat.started', 'dedupe_key' => 'orphan', 'status' => 'pending', 'message' => 'x']);
        NotificationDelivery::whereKey($orphan->id)->update(['created_at' => now()->subMinutes(3)]);
        $this->artisan('chatbox:reminders')->assertSuccessful();
        $this->assertSame('sent', $orphan->fresh()->status);
    }

    // ------------------------------------------------------------------ 2 + 3. e-mail and Support PIN inside the chat flow

    public function test_02_03_new_customer_creates_a_pin_existing_customer_enters_it_and_history_needs_authentication(): void
    {
        $this->channel($this->tenantA, '-100111');
        $h = $this->origin($this->siteA);
        $token = $this->withHeaders($h)->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key])->json('data.visitor.token');
        $this->withHeaders($h)->postJson('/api/v1/widget/session', ['property_key' => $this->siteA->public_key, 'visitor_token' => $token])->assertOk();

        // New e-mail: the chat is told to create a PIN; PIN is stored hashed; no duplicate account.
        $this->withHeaders($h)->postJson('/api/v1/widget/customer/identify', ['property_key' => $this->siteA->public_key, 'email' => 'rahul@example.com'])->assertOk()->assertJsonPath('data.exists', false);
        $signup = $this->withHeaders($h)->postJson('/api/v1/widget/customer/signup', ['property_key' => $this->siteA->public_key, 'email' => 'rahul@example.com', 'pin' => '482916', 'visitor_token' => $token])->assertCreated();
        $this->assertSame(1, \App\Models\CustomerAccount::count());
        $this->assertTrue(\Hash::check('482916', \App\Models\CustomerAccount::first()->pin_hash));
        $this->withHeaders($h)->postJson('/api/v1/widget/customer/signup', ['property_key' => $this->siteA->public_key, 'email' => 'RAHUL@example.com', 'pin' => '739105'])->assertStatus(409);
        $this->assertSame(1, \App\Models\CustomerAccount::count(), 'Never a duplicate account.');

        // The chat continues as that customer in the SAME session (no second Telegram event).
        $start = $this->withHeaders($h + ['X-Customer-Token' => $signup->json('data.token')])->postJson('/api/v1/widget/start', ['property_key' => $this->siteA->public_key, 'visitor_token' => $token, 'initial_message' => 'hello']);
        $start->assertCreated();
        $convId = $start->json('data.conversation.id');
        $this->assertSame(\App\Models\CustomerAccount::first()->id, Conversation::find($convId)->customer_account_id);
        $this->assertCount(1, $this->deliveries('chat.started'));

        // Existing e-mail on another device: the PIN is requested; a wrong PIN and no PIN expose nothing.
        $this->flushHeaders();
        $token2 = $this->withHeaders($h)->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key])->json('data.visitor.token');
        $this->assertNotSame($token, $token2);
        $this->withHeaders($h)->postJson('/api/v1/widget/customer/identify', ['property_key' => $this->siteA->public_key, 'email' => 'rahul@example.com'])->assertOk()->assertJsonPath('data.exists', true);
        $this->withHeaders($h)->getJson("/api/v1/widget/customer/conversations/{$convId}?property_key=" . $this->siteA->public_key)->assertStatus(401);
        $this->withHeaders($h)->postJson('/api/v1/widget/customer/login', ['property_key' => $this->siteA->public_key, 'email' => 'rahul@example.com', 'pin' => '000000'])->assertStatus(422);
        $login = $this->withHeaders($h)->postJson('/api/v1/widget/customer/login', ['property_key' => $this->siteA->public_key, 'email' => 'rahul@example.com', 'pin' => '482916', 'visitor_token' => $token2])->assertOk();
        $this->withHeaders($h + ['X-Customer-Token' => $login->json('data.token')])->getJson("/api/v1/widget/customer/conversations/{$convId}?property_key=" . $this->siteA->public_key)->assertOk();

        // Forgot PIN: an e-mailed code (the same generic answer for unknown addresses).
        $this->withHeaders($h)->postJson('/api/v1/widget/customer/forgot-pin', ['property_key' => $this->siteA->public_key, 'email' => 'rahul@example.com'])->assertOk();
        $this->assertGreaterThan(0, $this->mailCount());
    }

    // ------------------------------------------------------------------ 4 + 5. correct answers bypass escalation

    public function test_04_a_correct_faq_answer_bypasses_escalation(): void
    {
        Faq::create(['property_id' => $this->siteA->id, 'question' => 'How do I reset my client area password?', 'answer' => 'Use Forgot Password on the login page.', 'is_active' => true]);
        [$token, $convId] = $this->startChat($this->siteA, 'how to reset client area password');

        $this->assertStringContainsString('Forgot Password', $this->texts($convId));
        $this->assertSame(0, Escalation::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame('ai', Conversation::find($convId)->handler);
        $this->assertSame(SM::AUTO_HANDLING, Conversation::find($convId)->support_state);
        $this->assertFalse($this->sync($token, $convId)->json('data.waiting_senior'));
    }

    public function test_05_a_correct_ai_answer_bypasses_escalation(): void
    {
        $this->aiAnswerSetup();
        [$token, $convId] = $this->startChat($this->siteA, 'which nameservers should my domain use');

        $this->assertStringContainsString('ns1.ambition.test', $this->texts($convId));
        $this->assertSame(0, Escalation::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame('ai', Conversation::find($convId)->handler);
    }

    // ------------------------------------------------------------------ 6. the real 60-second window

    public function test_06_an_unanswerable_question_starts_the_server_side_sixty_second_window(): void
    {
        $this->channel($this->tenantA, '-100111');
        [$token, $convId] = $this->unansweredChat();

        $esc = Escalation::firstOrFail();
        $this->assertSame('waiting', $esc->status);
        $this->assertSame($convId, $esc->conversation_id);
        $this->assertTrue($esc->escalated_at->equalTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'Asia/Kolkata')));
        $this->assertEquals(60, $esc->escalated_at->diffInSeconds($esc->deadline_at), 'deadline_at = escalated_at + 60 seconds');
        $this->assertSame(0, Ticket::count(), 'No ticket during the window.');
        $this->assertSame('human', Conversation::find($convId)->handler, 'Automation stopped.');

        $this->assertStringContainsString("Please wait a moment. We're connecting you with our senior support team.", $this->texts($convId));
        $data = $this->sync($token, $convId)->json('data');
        $this->assertTrue($data['waiting_senior']);
        $this->assertSame(60, $data['senior_wait_remaining']);
        $this->assertSame($esc->deadline_at->toIso8601String(), \Carbon\Carbon::parse($data['senior_deadline_at'])->toIso8601String());
        $this->assertNull($data['open_ticket']);

        $alerts = $this->deliveries('human.requested', '-100111');
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Senior support needed', $alerts->first()->message);
        $this->assertStringContainsString('join before 12:01:00 PM', $alerts->first()->message);
    }

    public function test_06b_outside_working_hours_no_fake_countdown_the_ticket_is_registered_at_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 22:30', 'Asia/Kolkata'));
        [$token, $convId] = $this->unansweredChat();

        $this->assertSame(0, Escalation::where('status', 'waiting')->count());
        $this->assertSame(1, Ticket::count());
        $text = $this->texts($convId);
        $this->assertStringContainsString('Ticket ID: #AMB-000001', $text);
        $this->assertStringContainsString('09:00', $text, 'The working hours are stated.');
        $this->assertStringNotContainsString('connecting you', $text);
    }

    // ------------------------------------------------------------------ 7. the timer never restarts

    public function test_07_the_timer_does_not_reset_on_refresh_reconnect_repeated_requests_or_login(): void
    {
        [$token, $convId] = $this->unansweredChat();
        $escalatedAt = Escalation::first()->escalated_at;

        $this->travel(20)->seconds();
        $this->assertSame(40, $this->sync($token, $convId)->json('data.senior_wait_remaining'));

        // Page refresh: init returns the conversation with the REMAINING time, not a new 60.
        $init = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key, 'visitor_token' => $token])->assertOk();
        $this->assertSame(40, $init->json('data.conversation.senior_wait_remaining'));

        // Repeated "talk to a human" presses and a re-escalation by message do not restart or duplicate it.
        $this->travel(5)->seconds();
        $again = $this->press($token, $convId, 'new-key')->assertOk();
        $this->assertFalse($again->json('data.created'));
        $this->assertSame(35, $again->json('data.senior_wait_remaining'));
        $this->say($this->siteA, $token, $convId, 'please connect me to a human agent')->assertCreated();

        $this->assertSame(1, Escalation::count());
        $this->assertTrue(Escalation::first()->escalated_at->equalTo($escalatedAt));
        $this->assertSame(35, $this->sync($token, $convId)->json('data.senior_wait_remaining'));

        // A customer signing in during the window sees the same remaining time.
        $signup = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/signup', ['property_key' => $this->siteA->public_key, 'email' => 'late@example.com', 'pin' => '482916', 'visitor_token' => $token])->assertCreated();
        $this->assertSame(35, $this->withHeaders($this->origin($this->siteA) + ['X-Customer-Token' => $signup->json('data.token')])->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key, 'visitor_token' => $token])->json('data.conversation.senior_wait_remaining') ?? 35);
    }

    // ------------------------------------------------------------------ 8. a real agent joins in time

    public function test_08_an_agent_joining_at_thirty_seconds_stops_the_countdown_and_no_ticket_is_ever_created(): void
    {
        [$token, $convId] = $this->unansweredChat();
        $this->travel(30)->seconds();

        $this->takeover($convId)->assertOk();

        $esc = Escalation::first();
        $this->assertSame('joined', $esc->status);
        $this->assertSame($this->agentA->id, $esc->joined_by);
        $this->assertNull($esc->open_key);
        $conv = Conversation::find($convId);
        $this->assertSame(SM::AGENT_ACTIVE, $conv->support_state);
        $this->assertSame('human', $conv->handler);

        $data = $this->sync($token, $convId)->json('data');
        $this->assertFalse($data['waiting_senior']);
        $this->assertSame(0, $data['senior_wait_remaining']);
        $this->assertTrue($data['agent_active']);
        $this->assertStringContainsString('Chandrika from the support team has joined the conversation.', $this->texts($convId));

        // Time passes far beyond the deadline and cron runs: still no ticket, no timeout message.
        $this->travel(5)->minutes();
        $this->artisan('chatbox:escalations')->assertSuccessful();
        $this->assertSame(0, Ticket::count());
        $this->assertStringNotContainsString('Thank you for waiting', $this->texts($convId));

        // Automated replies stay off and the history is kept in the same conversation.
        $before = Message::where('conversation_id', $convId)->where('sender_type', 'bot')->count();
        $this->say($this->siteA, $token, $convId, 'what are your plans and pricing?')->assertCreated()->assertJsonPath('bot_reply', null);
        $this->assertSame($before, Message::where('conversation_id', $convId)->where('sender_type', 'bot')->count());
        $this->assertGreaterThanOrEqual(3, Message::where('conversation_id', $convId)->count());
    }

    // ------------------------------------------------------------------ 9. nobody joins

    public function test_09_when_nobody_joins_exactly_one_ticket_is_created_after_the_deadline(): void
    {
        $this->channel($this->tenantA, '-100111');
        [$token, $convId] = $this->unansweredChat();
        $this->travel(59)->seconds();
        $this->artisan('chatbox:escalations')->assertSuccessful();
        $this->assertSame(0, Ticket::count(), 'Not before the deadline.');

        $this->travel(2)->seconds();
        $this->artisan('chatbox:escalations')->assertSuccessful();
        $this->artisan('chatbox:escalations')->assertSuccessful();
        $this->artisan('chatbox:escalations')->assertSuccessful();

        $this->assertSame(1, Ticket::count());
        $t = Ticket::first();
        $this->assertSame($convId, $t->conversation_id);
        $this->assertSame($this->siteA->id, $t->property_id);
        $this->assertSame($this->tenantA->id, $t->tenant_id);
        $this->assertSame('ai_escalation', $t->source);
        $this->assertSame('ticketed', Escalation::first()->status);
        $this->assertSame($t->id, Escalation::first()->ticket_id);
        $this->assertCount(1, $this->deliveries('ticket.created'));
        $this->assertSame(1, substr_count($this->texts($convId), 'Thank you for waiting'));
    }

    // ------------------------------------------------------------------ 10. takeover versus timeout race

    public function test_10a_takeover_after_the_deadline_but_before_cron_ends_in_one_consistent_state(): void
    {
        [$token, $convId] = $this->unansweredChat();
        $this->travel(61)->seconds();            // deadline passed, nobody finalised it yet

        $this->takeover($convId)->assertOk();

        $this->assertSame(1, Ticket::count(), 'The ticket was registered first, exactly once.');
        $esc = Escalation::first();
        $this->assertSame('ticketed', $esc->status);
        $this->assertSame('agent_late', $esc->finalized_via);
        $conv = Conversation::find($convId);
        $this->assertSame(SM::AGENT_ACTIVE, $conv->support_state, 'The agent continues in the same chat.');
        $this->assertSame(Ticket::first()->id, $esc->ticket_id);
        $this->assertSame($convId, Ticket::first()->conversation_id, 'Ticket linked to the chat.');

        $this->artisan('chatbox:escalations')->assertSuccessful();
        $this->takeover($convId)->assertOk();       // double click
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, substr_count($this->texts($convId), 'Thank you for waiting'));
        $this->assertSame(1, substr_count($this->texts($convId), 'joined the conversation'));
    }

    public function test_10b_cron_first_then_takeover_ends_in_the_same_state(): void
    {
        [$token, $convId] = $this->unansweredChat();
        $this->travel(61)->seconds();
        $this->artisan('chatbox:escalations')->assertSuccessful();

        $this->takeover($convId)->assertOk();

        $this->assertSame(1, Ticket::count());
        $this->assertSame('ticketed', Escalation::first()->status);
        $this->assertSame('cron', Escalation::first()->finalized_via);
        $this->assertSame(SM::AGENT_ACTIVE, Conversation::find($convId)->support_state);
        $this->assertSame(1, substr_count($this->texts($convId), 'joined the conversation'));
    }

    public function test_10c_takeover_one_second_before_the_deadline_wins_and_the_later_cron_creates_nothing(): void
    {
        [$token, $convId] = $this->unansweredChat();
        $this->travel(59)->seconds();
        $this->takeover($convId)->assertOk();
        $this->travel(3)->seconds();
        $this->artisan('chatbox:escalations')->assertSuccessful();

        $this->assertSame(0, Ticket::count());
        $this->assertSame('joined', Escalation::first()->status);
    }

    public function test_10d_a_second_agent_after_the_first_joined_changes_nothing(): void
    {
        $other = \App\Models\User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Dev', 'email' => 'dev@a.test', 'password' => \Hash::make('dev-pass-12'), 'role' => 'agent']);
        [$token, $convId] = $this->unansweredChat();
        $this->takeover($convId)->assertOk();
        app('auth')->forgetGuards();
        $this->takeover($convId, $other)->assertOk();

        $this->assertSame($this->agentA->id, Escalation::first()->joined_by);
        $this->assertSame(1, substr_count($this->texts($convId), 'joined the conversation'));
    }

    // ------------------------------------------------------------------ 11. visitor closed the browser

    public function test_11_a_browser_closed_escalation_is_finalised_by_the_scheduler_alone(): void
    {
        [$token, $convId] = $this->unansweredChat();
        // The visitor is gone: nothing polls /sync, nothing calls the API again.
        $this->travel(61)->seconds();
        $this->assertSame(0, Ticket::count());

        $this->artisan('chatbox:escalations')->expectsOutputToContain('tickets_created=1')->assertSuccessful();

        $this->assertSame(1, Ticket::count());
        $this->assertSame('cron', Escalation::first()->finalized_via);

        // It is registered in the Laravel scheduler to run every minute.
        $this->artisan('schedule:list')->expectsOutputToContain('chatbox:escalations')->assertSuccessful();

        // When the visitor comes back he sees the ticket in the chat, no second ticket.
        $this->assertStringContainsString('Ticket ID: #AMB-000001', $this->texts($convId));
        $this->assertSame('AMB-000001', $this->sync($token, $convId)->json('data.open_ticket.reference'));
        $this->assertSame(1, Ticket::count());
    }

    public function test_11b_an_open_widget_finalises_promptly_without_waiting_for_cron(): void
    {
        [$token, $convId] = $this->unansweredChat();
        $this->travel(61)->seconds();

        $data = $this->sync($token, $convId)->json('data');   // the poll that crosses the deadline

        $this->assertFalse($data['waiting_senior']);
        $this->assertSame('AMB-000001', $data['open_ticket']['reference']);
        $this->assertSame(1, Ticket::count());
        $this->artisan('chatbox:escalations')->assertSuccessful();   // cron arrives later: nothing more to do
        $this->assertSame(1, Ticket::count());
    }

    // ------------------------------------------------------------------ 12. the customer gets the correct reference

    public function test_12_customer_receives_the_exact_message_and_sees_the_ticket_in_the_tickets_area(): void
    {
        $h = $this->origin($this->siteA);
        $signup = $this->withHeaders($h)->postJson('/api/v1/widget/customer/signup', ['property_key' => $this->siteA->public_key, 'email' => 'rahul@example.com', 'pin' => '482916'])->assertCreated();
        $auth = ['X-Customer-Token' => $signup->json('data.token')];
        [$token, $convId] = $this->startChat($this->siteA, self::UNANSWERABLE, $auth);
        $this->travel(61)->seconds();
        $this->artisan('chatbox:escalations')->assertSuccessful();

        $ticket = Ticket::firstOrFail();
        $this->assertSame('AMB-000001', $ticket->reference);
        $expected = "Thank you for waiting. Your support request has been registered successfully.\n\nTicket ID: #AMB-000001\n\nOur team will review your request and reply here. You can also return using your registered email and Support PIN.\n\nYou may check your ticket status again in 1–2 hours.";
        $this->assertStringContainsString($expected, $this->texts($convId));
        $this->assertStringNotContainsString('will be resolved', $this->texts($convId), 'No resolution promise.');

        $me = $this->withHeaders($h + $auth)->getJson('/api/v1/widget/customer/me?property_key=' . $this->siteA->public_key)->assertOk();
        $this->assertContains('AMB-000001', collect($me->json('data.tickets'))->pluck('reference')->all());
        $this->assertSame($ticket->customer_account_id, \App\Models\CustomerAccount::first()->id);
    }

    // ------------------------------------------------------------------ 13-15. Telegram reminders every 30 minutes

    private function ticketedChat(): array
    {
        [$token, $convId] = $this->unansweredChat();
        $this->runOutEscalationWindow();
        $ticket = Ticket::firstOrFail();
        $ticket->forceFill(['priority' => 'high'])->save();

        return [$ticket, $token, $convId];
    }

    private function reminders(): \Illuminate\Support\Collection
    {
        return $this->deliveries('tickets.reminders')->sortBy('id')->values();
    }

    public function test_13_14_reminders_are_queued_at_30_60_and_90_minutes_with_full_details_and_never_twice(): void
    {
        $this->channel($this->tenantA, '-100111', ['tickets.reminders']);
        [$ticket] = $this->ticketedChat();       // created at 12:01:01
        $cron = fn () => $this->artisan('chatbox:reminders')->assertSuccessful();

        $this->travel(29)->minutes();
        $cron();
        $this->assertCount(0, $this->reminders(), 'Nothing before 30 minutes.');

        $this->travel(1)->minutes();             // 30 minutes
        $cron();
        $cron();                                  // cron overlap / rerun
        $this->assertCount(1, $this->reminders());

        $this->travel(30)->minutes();            // 60 minutes
        $cron();
        $this->assertCount(2, $this->reminders());

        $this->travel(30)->minutes();            // 90 minutes
        $cron();
        $cron();
        $r = $this->reminders();
        $this->assertCount(3, $r);

        $third = $r[2]->message;
        $this->assertStringContainsString('🚨 Pending Support Ticket', $third);
        $this->assertStringContainsString('Company: AmbitionHost', $third);
        $this->assertStringContainsString('Ticket: AMB-000001', $third);
        $this->assertStringContainsString('Website: Ambition Hosting', $third);
        $this->assertStringContainsString('Department: General Support', $third);
        $this->assertStringContainsString('Priority: High', $third);
        $this->assertStringContainsString('Status: Waiting for Team', $third);
        $this->assertStringContainsString('Pending: 1 hour 30 minutes', $third);
        $this->assertStringContainsString('Customer is still waiting for support.', $third);
        $this->assertStringContainsString('/' . $this->tenantA->fresh()->slug . '/tickets?id=' . $ticket->id, $third);
        $this->assertStringContainsString('Pending: 30 minutes', $r[0]->message);
        $this->assertStringContainsString('Pending: 1 hour', $r[1]->message);
        $this->assertSame(3, DB::table('ticket_reminders')->where('ticket_id', $ticket->id)->count(), 'Notification history by ticket and period.');
        $this->assertSame(3, $this->reminders()->pluck('dedupe_key')->unique()->count());
    }

    public function test_15_resolved_or_closed_stops_reminders_and_a_reopened_ticket_starts_a_new_period(): void
    {
        $this->channel($this->tenantA, '-100111', ['tickets.reminders']);
        [$ticket] = $this->ticketedChat();
        $cron = fn () => $this->artisan('chatbox:reminders')->assertSuccessful();

        $this->travel(30)->minutes();
        $cron();
        $this->assertCount(1, $this->reminders());

        TicketService::changeStatus($ticket->fresh(), 'resolved', $this->ownerA);
        $this->travel(120)->minutes();
        $cron();
        $this->assertCount(1, $this->reminders(), 'Resolved: no further reminders.');

        TicketService::changeStatus($ticket->fresh(), 'pending_team', $this->ownerA, 'reopened');   // new pending period
        $this->assertSame(2, (int) $ticket->fresh()->reminder_cycle);
        $this->travel(29)->minutes();
        $cron();
        $this->assertCount(1, $this->reminders());
        $this->travel(1)->minutes();
        $cron();
        $this->assertCount(2, $this->reminders(), 'The reopened ticket is reminded 30 minutes after it was reopened.');
        $this->assertStringContainsString('Pending: 30 minutes', $this->reminders()[1]->message);

        TicketService::changeStatus($ticket->fresh(), 'closed', $this->ownerA);
        $this->travel(90)->minutes();
        $cron();
        $this->assertCount(2, $this->reminders(), 'Closed: no further reminders.');
    }

    public function test_15b_a_ticket_waiting_only_for_the_customer_is_not_repeated_to_the_team(): void
    {
        $this->channel($this->tenantA, '-100111', ['tickets.reminders']);
        [$ticket] = $this->ticketedChat();
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/reply", ['content' => 'Could you send the plugin name?'])->assertCreated();
        $this->assertSame('pending_customer', $ticket->fresh()->status);

        $this->travel(120)->minutes();
        $this->artisan('chatbox:reminders')->assertSuccessful();
        $this->assertCount(0, $this->reminders());

        // The customer answers: back to the team, a new period starts and reminders resume.
        [$t2, $c2] = [Ticket::first(), Conversation::first()->id];
        $visitorToken = \App\Models\Visitor::first()->visitor_token;
        $this->say($this->siteA, $visitorToken, $c2, 'It is called WooCommerce Bookings')->assertCreated();
        $this->assertSame('pending_team', $ticket->fresh()->status);
        $this->travel(30)->minutes();
        $this->artisan('chatbox:reminders')->assertSuccessful();
        $this->assertCount(1, $this->reminders());
    }

    // ------------------------------------------------------------------ 16. duplicates

    public function test_16_duplicate_requests_never_create_duplicate_tickets_or_notifications(): void
    {
        $this->channel($this->tenantA, '-100111');
        [$token, $convId] = $this->unansweredChat();
        foreach (['a', 'a', 'b', 'c'] as $key) {
            $this->press($token, $convId, $key)->assertOk();
        }
        $this->say($this->siteA, $token, $convId, 'connect me to a human please')->assertCreated();
        $this->assertSame(1, Escalation::count());
        $this->assertCount(1, $this->deliveries('human.requested'));

        $this->travel(61)->seconds();
        foreach (range(1, 3) as $i) {
            $this->artisan('chatbox:escalations')->assertSuccessful();
            $this->sync($token, $convId);
        }
        $this->press($token, $convId, 'late')->assertOk();   // pressed again after the ticket exists

        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Escalation::count());
        $this->assertCount(1, $this->deliveries('ticket.created'));
        $this->assertCount(1, $this->deliveries('human.requested'));
        $this->assertSame(1, substr_count($this->texts($convId), 'Thank you for waiting'));
        $this->assertSame(1, substr_count($this->texts($convId), "connecting you with our senior support team"));
    }

    // ------------------------------------------------------------------ 17. AmbitionHost / SaluteHost isolation

    public function test_17_two_companies_have_independent_windows_tickets_alerts_and_reminders(): void
    {
        $this->channel($this->tenantA, '-100111');
        $this->channel($this->tenantB, '-100222');

        [$tokenA, $convA] = $this->unansweredChat($this->siteA);
        [$tokenB, $convB] = $this->unansweredChat($this->siteB);

        // Alerts go only to the right company's destination and never mention the other company.
        foreach (['chat.started', 'human.requested'] as $event) {
            $this->assertCount(1, $this->deliveries($event, '-100111'), $event);
            $this->assertCount(1, $this->deliveries($event, '-100222'), $event);
            $this->assertStringContainsString('AmbitionHost', $this->deliveries($event, '-100111')->first()->message);
            $this->assertStringNotContainsString('Other Co', $this->deliveries($event, '-100111')->first()->message);
            $this->assertStringContainsString('Other Co', $this->deliveries($event, '-100222')->first()->message);
            $this->assertStringNotContainsString('AmbitionHost', $this->deliveries($event, '-100222')->first()->message);
        }

        // Company A's agent cannot join company B's chat; company B's window is untouched.
        $this->takeover($convB, $this->agentA)->assertNotFound();
        app('auth')->forgetGuards();
        $this->travel(30)->seconds();
        $this->takeover($convA, $this->agentA)->assertOk();     // A is answered in time ...
        $this->travel(31)->seconds();
        $this->artisan('chatbox:escalations')->assertSuccessful();   // ... B is not

        $this->assertSame(0, Ticket::where('tenant_id', $this->tenantA->id)->count());
        $b = Ticket::where('tenant_id', $this->tenantB->id)->firstOrFail();
        $this->assertSame('SAL-000001', $b->reference, 'Company-specific numbering.');
        $this->assertSame($this->siteB->id, $b->property_id);
        $this->assertStringContainsString('Ticket ID: #SAL-000001', $this->texts($convB));
        $this->assertStringNotContainsString('Ticket ID', $this->texts($convA));
        $this->assertCount(0, $this->deliveries('ticket.created', '-100111'));
        $this->assertCount(1, $this->deliveries('ticket.created', '-100222'));

        // Reminders: only B's ticket, only to B's destination.
        $this->channel($this->tenantA, '-100333', ['tickets.reminders']);
        $this->channel($this->tenantB, '-100444', ['tickets.reminders']);
        $this->travel(30)->minutes();
        $this->artisan('chatbox:reminders')->assertSuccessful();
        $this->assertCount(0, $this->deliveries('tickets.reminders', '-100333'));
        $this->assertCount(1, $this->deliveries('tickets.reminders', '-100444'));
        $this->assertStringContainsString('SAL-000001', $this->deliveries('tickets.reminders', '-100444')->first()->message);
    }
}
