<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\StaffNotification;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\Ticket;
use App\Services\BusinessHours;
use App\Services\Notifier;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** Stages 3–4: tickets, human takeover, notifications, Telegram, reminders, working hours. */
class TicketsAndTakeoverTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00', 'Asia/Kolkata')); // inside working hours
    }

    private function sync(string $token, string $convId, array $headers = [])
    {
        return $this->withHeaders($headers)->getJson("/api/v1/widget/conversations/{$convId}/sync?visitor_token={$token}&after_seq=0")->assertOk();
    }

    public function test_human_request_with_nobody_online_creates_one_ticket_and_stops_the_bot(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'My site is down, I want to talk to a human');

        $ticket = Ticket::firstOrFail();
        $this->assertSame('AMB-000001', $ticket->reference);
        $this->assertSame($convId, $ticket->conversation_id);
        $this->assertSame('customer_request', $ticket->source);
        $this->assertSame('human', Conversation::find($convId)->handler);

        $messages = collect($this->sync($token, $convId)->json('data.messages'));
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m['content'], 'AMB-000001')), 'Customer sees the reference only after it exists.');

        // Further messages: no bot reply while humans handle it; still exactly one ticket.
        $before = Message::where('conversation_id', $convId)->where('sender_type', 'bot')->count();
        $this->say($this->siteA, $token, $convId, 'hello? anyone? talk to agent')->assertCreated()->assertJsonPath('bot_reply', null);
        $this->assertSame($before, Message::where('conversation_id', $convId)->where('sender_type', 'bot')->count());
        $this->assertSame(1, Ticket::count());
        $this->assertSame('pending_team', $ticket->fresh()->status);
        $this->assertTrue(StaffNotification::where('type', 'ticket.created')->where('user_id', $this->ownerA->id)->exists());
    }

    public function test_ticket_subject_is_the_latest_unresolved_problem_not_the_first_question(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hello');
        $this->say($this->siteA, $token, $convId, 'My website shows a 500 error since I installed a plugin')->assertCreated();
        $this->say($this->siteA, $token, $convId, 'talk to a human please')->assertCreated();

        $ticket = Ticket::firstOrFail();
        $this->assertSame('My website shows a 500 error since I installed a plugin', $ticket->subject);
        $this->assertSame('My website shows a 500 error since I installed a plugin', $ticket->summary['issue']);
        $this->assertStringContainsString('hello', (string) $ticket->summary['results']);
        $this->assertStringNotContainsString('talk to a human', (string) $ticket->summary['results']);
    }

    public function test_escalate_button_is_idempotent_on_retries_and_double_clicks(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hello');
        $press = fn ($key) => $this->withHeaders($this->origin($this->siteA))->postJson("/api/v1/widget/conversations/{$convId}/escalate", ['visitor_token' => $token, 'idempotency_key' => $key]);

        $first = $press('key-1')->assertOk();
        $press('key-1')->assertOk()->assertJsonPath('data.created', false);
        $press('key-2')->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame(1, Ticket::count());
        $this->assertSame('ticket', $first->json('data.mode'));
        $this->assertSame(Ticket::first()->reference, $first->json('data.ticket.reference'));

        // Database guarantee: a second open ticket for the same conversation is impossible.
        [$t2, $created] = TicketService::createOrReuse(Conversation::find($convId), ['source' => 'staff']);
        $this->assertFalse($created);
        $this->assertSame(Ticket::first()->id, $t2->id);
    }

    public function test_live_handover_when_staff_are_online_in_working_hours(): void
    {
        $this->agentA->forceFill(['is_online' => true, 'last_active_at' => now()])->save();
        [$token, $convId] = $this->startChat($this->siteA, 'talk to a human please');
        $this->assertSame(0, Ticket::count());
        $this->assertSame('human', Conversation::find($convId)->handler);
        $this->assertTrue(StaffNotification::where('type', 'human.requested')->exists());
    }

    public function test_after_hours_escalation_states_working_hours_and_promises_no_overnight_support(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 22:30', 'Asia/Kolkata'));
        $this->agentA->forceFill(['is_online' => true, 'last_active_at' => now()])->save();
        [$token, $convId] = $this->startChat($this->siteA, 'please connect me to an agent');
        $this->assertSame(1, Ticket::count(), 'Outside 9–18 IST a ticket is created even if someone is logged in.');
        $reply = Message::where('conversation_id', $convId)->where('sender_type', 'bot')->orderByDesc('seq')->value('content');
        $this->assertStringContainsString('09:00–18:00 IST', $reply);
    }

    public function test_staff_reply_reaches_widget_but_internal_notes_never_do(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'I want a human');
        $ticket = Ticket::firstOrFail();

        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/reply", ['content' => 'Hi, I am checking your server now.'])->assertCreated();
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/notes", ['body' => 'INTERNAL: customer is on old plan'])->assertCreated();
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/notes", ['body' => 'INTERNAL second note'])->assertCreated();

        $widget = $this->sync($token, $convId)->getContent();
        $this->assertStringContainsString('checking your server', $widget);
        $this->assertStringNotContainsString('INTERNAL', $widget);
        $this->assertSame('pending_customer', $ticket->fresh()->status);
        // The person who answered an unassigned ticket now owns it, with history.
        $this->assertSame($this->agentA->id, $ticket->fresh()->assigned_to);
        $this->assertSame(1, \App\Models\TicketAssignment::where('ticket_id', $ticket->id)->count());
        $this->assertTrue(\App\Models\TicketEvent::where('ticket_id', $ticket->id)->where('type', 'replied')->exists());

        $staff = $this->actingAs($this->agentA, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
        $this->assertTrue(collect($staff->json('data.messages'))->contains(fn ($m) => $m['visibility'] === 'internal' && str_contains($m['content'], 'old plan')));
    }

    public function test_resolved_ticket_reopens_when_the_customer_replies(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'need a person');
        $ticket = Ticket::firstOrFail();
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/update", ['status' => 'resolved'])->assertOk();
        $this->assertNull($ticket->fresh()->open_conversation_id);
        $this->assertSame('resolved', $ticket->fresh()->status);

        $this->say($this->siteA, $token, $convId, 'It is broken again')->assertCreated();
        $this->assertSame('pending_team', $ticket->fresh()->status);
        $this->assertSame($convId, $ticket->fresh()->open_conversation_id);
        $this->assertTrue(StaffNotification::where('type', 'ticket.reopened')->exists());
        $this->assertTrue($ticket->events()->where('type', 'status_changed')->exists());
    }

    public function test_ticket_permissions_assignment_history_and_previous_owner_visibility(): void
    {
        [, $convId] = $this->startChat($this->siteA, 'human please');
        $ticket = Ticket::firstOrFail();
        $this->actingAs($this->agentB, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}")->assertNotFound();
        $this->actingAs($this->agentB, 'sanctum')->getJson('/api/v1/tickets')->assertJsonCount(0, 'data');

        // Agent restricted to the Domains website cannot see this Hosting ticket...
        $this->agentA->setWebsiteAccess([$this->siteA2->id]);
        $this->actingAs($this->agentA->fresh(), 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}")->assertNotFound();
        // ...and cannot be assigned to it.
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/update", ['assigned_to' => $this->agentA->id])->assertStatus(422);

        // Unrestricted again: assign, then reassign to owner; the previous assignee keeps visibility.
        $this->agentA->setWebsiteAccess([]);
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/update", ['assigned_to' => $this->agentA->id])->assertOk();
        $this->assertTrue(StaffNotification::where('user_id', $this->agentA->id)->where('type', 'ticket.assigned')->exists());
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/update", ['assigned_to' => $this->ownerA->id])->assertOk();
        $this->agentA->setWebsiteAccess([$this->siteA2->id]); // restricted again, but was an assignee
        $detail = $this->actingAs($this->agentA->fresh(), 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
        $this->assertSame([false, true], collect($detail->json('data.assignments'))->pluck('current')->all());
    }

    public function test_takeover_stops_ai_and_resume_needs_explicit_authorised_action(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hello');
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/takeover")->assertOk()->assertJsonPath('data.handler', 'human');
        $this->say($this->siteA, $token, $convId, 'how do I reset my password?')->assertCreated()->assertJsonPath('bot_reply', null);

        $this->actingAs($this->agentB, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/resume-ai")->assertNotFound();
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/resume-ai")->assertOk()->assertJsonPath('data.handler', 'ai');
        $this->assertDatabaseHas('audit_logs', ['action' => 'conversation.ai_resumed', 'actor_id' => $this->agentA->id]);
    }

    public function test_agent_reply_hands_over_and_records_first_human_response(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'hi there');
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/messages", ['content' => 'Hello, Chandrika here'])->assertCreated();
        $conv = Conversation::find($convId);
        $this->assertSame('human', $conv->handler);
        $this->assertNotNull($conv->first_human_response_at);
    }

    public function test_inbox_reply_on_a_ticketed_chat_updates_and_claims_the_ticket(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'My site is down, I want to talk to a human');
        $ticket = Ticket::firstOrFail();
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/messages", ['content' => 'Looking at it now'])->assertCreated();
        $t = $ticket->fresh();
        $this->assertSame('pending_customer', $t->status);
        $this->assertSame($this->agentA->id, $t->assigned_to);
        $this->assertNotNull($t->first_response_at);
        $this->assertTrue(\App\Models\TicketEvent::where('ticket_id', $t->id)->where('type', 'replied')->exists());
    }

    public function test_restricted_actions_are_never_performed_and_go_to_staff(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'Please refund my last payment of Rs 2000');
        $ticket = Ticket::firstOrFail();
        $this->assertSame('billing', $ticket->category);
        $reply = Message::where('conversation_id', $convId)->where('sender_type', 'bot')->orderByDesc('seq')->value('content');
        $this->assertStringContainsString("can't process refunds", $reply);
        $this->assertStringContainsString($ticket->reference, $reply);
    }

    public function test_telegram_ticket_alert_is_logged_failures_retried_and_token_never_exposed(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)->push(['ok' => true, 'result' => []], 200)]);
        $token = '1234567:' . str_repeat('A', 35);
        $res = $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/settings/telegram', [
            'name' => 'Support group', 'bot_token' => $token, 'chat_id' => '-1001234567890', 'events' => ['ticket.created', 'human.requested'],
        ])->assertCreated();
        $this->assertStringNotContainsString($token, $res->getContent());
        $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/settings/telegram')->assertForbidden();

        $this->startChat($this->siteA, 'talk to a human');
        $delivery = NotificationDelivery::where('event', 'ticket.created')->firstOrFail();
        $this->assertSame('failed', $delivery->status, 'A failed send is never reported as delivered.');
        $this->assertStringContainsString('chat not found', $delivery->last_error);
        $this->assertStringNotContainsString($token, (string) $delivery->last_error);

        $this->travel(5)->minutes();
        $this->artisan('chatbox:reminders')->assertSuccessful();
        $this->assertSame('sent', $delivery->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org/bot') && $r['chat_id'] === '-1001234567890' && str_contains($r['text'], 'AMB-000001'));
        $this->assertNotNull(NotificationChannel::first()->last_success_at);
    }

    public function test_quotation_reminders_follow_original_time_and_customer_reply_cancels_them(): void
    {
        $this->tenantA->forceFill(['settings' => ['sales_followup_user_id' => $this->agentA->id]])->save();
        [$token, $convId] = $this->startChat($this->siteA, 'price for VPS?');
        $quote = $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/chat/conversations/{$convId}/messages", ['content' => 'VPS 4GB costs Rs 1499/month'])->assertCreated()->json('data.id');
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/chat/messages/{$quote}/quotation")->assertCreated();

        $task = Task::where('type', 'quotation_followup')->firstOrFail();
        $this->assertSame($this->agentA->id, $task->owner_id, 'Reminders go to the sales follow-up owner (Chandrika).');
        $base = Message::find($quote)->created_at;
        $this->assertSame([10, 30, 120], $task->reminders->map(fn ($r) => (int) round($base->diffInMinutes($r->remind_at)))->all());

        $this->travel(11)->minutes();
        $this->artisan('chatbox:reminders');
        $this->assertSame(1, StaffNotification::where('user_id', $this->agentA->id)->where('type', 'task.reminder')->count());

        $this->say($this->siteA, $token, $convId, 'Thanks, I will think about it')->assertCreated();
        $this->travel(3)->hours();
        $this->artisan('chatbox:reminders');
        $this->assertSame(1, StaffNotification::where('user_id', $this->agentA->id)->where('type', 'task.reminder')->count(), 'Remaining reminders were cancelled by the reply.');
        $this->assertSame(2, TaskReminder::whereNotNull('cancelled_at')->count());
        $this->assertSame('pending', $task->fresh()->status, 'The follow-up task itself is not auto-completed.');
    }

    public function test_sales_enquiry_alert_after_thirty_working_minutes_only_once(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        NotificationChannel::create(['tenant_id' => $this->tenantA->id, 'name' => 'Aarav', 'bot_token' => '1234567:' . str_repeat('B', 35), 'chat_id' => '12345', 'events' => ['sales.overdue']]);
        $sales = \App\Models\Department::create(['property_id' => $this->siteA->id, 'name' => 'Sales', 'is_active' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 17:50', 'Asia/Kolkata'));
        $init = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key])->json('data.visitor.token');
        $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/start', ['property_key' => $this->siteA->public_key, 'visitor_token' => $init, 'department_id' => $sales->id, 'initial_message' => 'quote for 10 domains'])->assertCreated();

        // 17:50 -> next day 09:25 = 10 + 25 = 35 working minutes, but only 10 by 18:00.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 23:00', 'Asia/Kolkata'));
        $this->artisan('chatbox:reminders');
        Http::assertNothingSent();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:25', 'Asia/Kolkata'));
        $this->artisan('chatbox:reminders');
        $this->artisan('chatbox:reminders');
        Http::assertSentCount(1);
    }

    public function test_working_minutes_calculation(): void
    {
        $p = $this->siteA;
        $at = fn ($s) => CarbonImmutable::parse($s, 'Asia/Kolkata');
        $this->assertSame(30, BusinessHours::workingMinutesBetween($p, $at('2026-11-05 10:00'), $at('2026-11-05 10:30')));
        $this->assertSame(10, BusinessHours::workingMinutesBetween($p, $at('2026-11-05 17:50'), $at('2026-11-05 23:00')));
        $this->assertSame(70, BusinessHours::workingMinutesBetween($p, $at('2026-11-05 17:00'), $at('2026-11-06 09:10')));
        $this->assertTrue(BusinessHours::isOpen($p, $at('2026-11-08 09:00')), 'Every day, including Sunday.');
        $this->assertFalse(BusinessHours::isOpen($p, $at('2026-11-05 18:00')));
    }

    public function test_daily_digest_lists_unresolved_tickets_and_resolved_ones_stop(): void
    {
        $this->startChat($this->siteA, 'human please');
        [, $conv2] = $this->startChat($this->siteA2, 'human please');
        $resolved = Ticket::where('conversation_id', $conv2)->first();
        TicketService::changeStatus($resolved, 'resolved', $this->ownerA);

        $this->artisan('chatbox:daily-digest')->assertSuccessful();
        $digest = StaffNotification::where('type', 'tickets.digest')->where('user_id', $this->ownerA->id)->firstOrFail();
        $this->assertStringContainsString('AMB-000001', $digest->body);
        $this->assertStringNotContainsString($resolved->reference, $digest->body);

        // Reading the notification does not resolve the ticket.
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/notifications/{$digest->id}/read")->assertOk();
        $this->assertSame(1, Ticket::whereIn('status', Ticket::UNRESOLVED)->count());
    }

    public function test_pending_ticket_reminders_include_hours_and_client_email(): void
    {
        $this->assertSame('45m', Notifier::formatWaitDuration(45));
        $this->assertSame('2h', Notifier::formatWaitDuration(120));
        $this->assertSame('37h 37m', Notifier::formatWaitDuration(2257));
        $this->assertSame('87h 6m', Notifier::formatWaitDuration(5226));

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        NotificationChannel::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Support Group',
            'bot_token' => '1234567:' . str_repeat('B', 35),
            'chat_id' => '-100999888777',
            'events' => ['tickets.reminders'],
        ]);

        [$token, $convId] = $this->startChat($this->siteA, 'need domain price');
        $customer = \App\Models\CustomerAccount::create([
            'tenant_id' => $this->tenantA->id,
            'property_id' => $this->siteA->id,
            'email' => 'client@salutehost.com',
            'email_normalized' => 'client@salutehost.com',
            'pin_hash' => bcrypt('123456'),
        ]);
        \App\Models\Conversation::whereKey($convId)->update(['customer_account_id' => $customer->id]);

        $ticket = Ticket::where('conversation_id', $convId)->first();
        if (! $ticket) {
            [$ticket] = TicketService::createOrReuse(\App\Models\Conversation::find($convId), ['source' => 'transfer_timeout']);
        }
        $ticket->forceFill([
            'customer_account_id' => $customer->id,
            'created_at' => now()->subMinutes(2257),
            'last_reminded_at' => null,
        ])->save();

        $count = Notifier::checkPendingTicketReminders();
        $this->assertGreaterThanOrEqual(1, $count);

        Http::assertSent(function ($request) {
            $text = $request['text'] ?? '';

            return str_contains($text, 'Pending Support Tickets Alert')
                && str_contains($text, 'waiting 37h 37m')
                && str_contains($text, 'client@salutehost.com');
        });
    }
}
