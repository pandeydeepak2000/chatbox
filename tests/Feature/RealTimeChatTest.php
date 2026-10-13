<?php

namespace Tests\Feature;

use App\Events\ConversationCreated;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RealTimeChatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $agent;
    private Property $property;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'ChatBox Technologies',
            'email' => 'tech@chatbox.com',
            'plan' => 'enterprise',
            'status' => 'active',
        ]);

        $this->agent = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent Alex',
            'email' => 'alex@chatbox.com',
            'password' => Hash::make('secretPass123'),
            'role' => 'agent',
            'is_online' => true,
        ]);

        $this->property = Property::create([
            'tenant_id' => $this->tenant->id,
            'site_name' => 'ChatBox Website',
            'site_url' => 'https://chatbox.com',
            'public_key' => 'pk_live_test_1234567890',
            'secret_key' => 'sk_live_test_1234567890',
            'allowed_domains' => ['chatbox.com', 'localhost'],
            'is_active' => true,
        ]);

        $this->department = Department::create([
            'property_id' => $this->property->id,
            'name' => 'Customer Care',
            'is_active' => true,
        ]);
    }

    public function test_widget_init_returns_config_and_visitor_token(): void
    {
        $response = $this->postJson('/api/v1/widget/init', [
            'property_key' => $this->property->public_key,
            'origin' => 'https://chatbox.com',
            'name' => 'Suresh Raina',
            'email' => 'suresh@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'visitor' => ['id', 'token', 'name', 'email'],
                    'property' => ['id', 'site_name', 'widget_config'],
                    'departments',
                    'broadcasting' => ['key', 'host', 'port', 'scheme'],
                ],
            ]);

        $this->assertDatabaseHas('visitors', [
            'property_id' => $this->property->id,
            'email' => 'suresh@example.com',
        ]);
    }

    public function test_widget_init_rejects_unauthorized_domain_origin(): void
    {
        $response = $this->postJson('/api/v1/widget/init', [
            'property_key' => $this->property->public_key,
            'origin' => 'https://malicious-site.com',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_visitor_can_start_conversation_and_broadcast_events(): void
    {
        Event::fake([ConversationCreated::class, MessageSent::class]);

        $visitor = Visitor::create([
            'property_id' => $this->property->id,
            'visitor_token' => 'vtok_abc123',
            'name' => 'Rahul Sharma',
            'email' => 'rahul@example.com',
        ]);

        $response = $this->postJson('/api/v1/widget/start', [
            'property_key' => $this->property->public_key,
            'visitor_token' => $visitor->visitor_token,
            'department_id' => $this->department->id,
            'initial_message' => 'Hello, I need help with server hosting!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('conversations', [
            'property_id' => $this->property->id,
            'visitor_id' => $visitor->id,
            'status' => 'unassigned',
        ]);

        Event::assertDispatched(ConversationCreated::class);
        Event::assertDispatched(MessageSent::class);
    }

    public function test_message_content_is_sanitized_against_xss(): void
    {
        Event::fake([MessageSent::class]);

        $visitor = Visitor::create([
            'property_id' => $this->property->id,
            'visitor_token' => 'vtok_xss_test',
        ]);

        $conversation = Conversation::create([
            'property_id' => $this->property->id,
            'visitor_id' => $visitor->id,
            'status' => 'open',
        ]);

        $xssPayload = '<script>alert("hacked")</script><b>Bold test</b>';

        $response = $this->postJson('/api/v1/widget/messages', [
            'property_key' => $this->property->public_key,
            'visitor_token' => $visitor->visitor_token,
            'conversation_id' => $conversation->id,
            'content' => $xssPayload,
        ]);

        $response->assertStatus(201);
        $savedMessage = Message::where('conversation_id', $conversation->id)->first();

        // Script tags must not exist in saved message
        $this->assertStringNotContainsString('<script>', $savedMessage->content);
        $this->assertStringNotContainsString('</script>', $savedMessage->content);
    }

    public function test_agent_can_view_conversations_and_reply(): void
    {
        Event::fake([MessageSent::class]);

        $token = $this->agent->createToken('agent-token')->plainTextToken;

        $visitor = Visitor::create([
            'property_id' => $this->property->id,
            'visitor_token' => 'vtok_agent_test',
            'name' => 'Vikram',
        ]);

        $conversation = Conversation::create([
            'property_id' => $this->property->id,
            'visitor_id' => $visitor->id,
            'status' => 'unassigned',
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'visitor',
            'content' => 'Please help me',
            'is_read' => false,
        ]);

        // Agent fetches conversations list
        $listResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/chat/conversations?filter=unassigned');

        $listResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        // Agent replies to conversation
        $replyResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/chat/conversations/{$conversation->id}/messages", [
                'content' => 'Hello Vikram! How can I help you today?',
            ]);

        $replyResponse->assertStatus(201)
            ->assertJsonPath('data.sender_type', 'agent')
            ->assertJsonPath('data.sender_id', $this->agent->id);

        // Conversation should be auto-assigned to agent and status updated to open
        $conversation->refresh();
        $this->assertEquals($this->agent->id, $conversation->assigned_agent_id);
        $this->assertEquals('open', $conversation->status);

        Event::assertDispatched(MessageSent::class);
    }

    public function test_agent_can_close_conversation(): void
    {
        $token = $this->agent->createToken('agent-token')->plainTextToken;

        $visitor = Visitor::create([
            'property_id' => $this->property->id,
            'visitor_token' => 'vtok_close_test',
        ]);

        $conversation = Conversation::create([
            'property_id' => $this->property->id,
            'visitor_id' => $visitor->id,
            'status' => 'open',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/chat/conversations/{$conversation->id}/status", [
                'status' => 'closed',
            ]);

        $response->assertStatus(200);

        $conversation->refresh();
        $this->assertEquals('closed', $conversation->status);
        $this->assertNotNull($conversation->closed_at);
    }
}
