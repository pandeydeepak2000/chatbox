<?php

namespace Tests\Feature\Security;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\CannedResponse;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 3 regression tests: positive behaviour of the audit fixes
 * (the Phase 1 audit tests only prove that the old defect is gone).
 */
#[Group('security')]
class Phase3SecurityFixesTest extends TestCase
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

    private function conversationFor(Property $p, string $token = 'vtok_abcdef01'): Conversation
    {
        $v = Visitor::create(['property_id' => $p->id, 'visitor_token' => $token, 'name' => 'Guest']);

        return Conversation::create(['property_id' => $p->id, 'visitor_id' => $v->id, 'status' => 'open']);
    }

    // ---------------------------------------------------------------- C1 password reset

    public function test_password_reset_full_flow_uses_emailed_token_and_revokes_sessions(): void
    {
        Notification::fake();
        $oldToken = $this->adminA->createToken('t')->plainTextToken;

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@a.test'])->assertOk()->assertJsonPath('success', true);

        $token = null;
        Notification::assertSentTo($this->adminA, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;
            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'admin@a.test', 'token' => 'wrong-token', 'password' => 'new-password-123'])
            ->assertStatus(422);
        $this->assertTrue(Hash::check('old-password-A', $this->adminA->fresh()->password));

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'admin@a.test', 'token' => $token, 'password' => 'new-password-123'])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $this->adminA->fresh()->password));
        $this->assertSame(0, $this->adminA->tokens()->count(), 'Old login tokens must be revoked after reset.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'actor_id' => $this->adminA->id]);

        // The same token cannot be used twice.
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'admin@a.test', 'token' => $token, 'password' => 'another-password-1'])
            ->assertStatus(422);
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_forgot_password_response_does_not_reveal_whether_account_exists(): void
    {
        Notification::fake();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@a.test'])->json('message');
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@nowhere.test'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
    }

    // ---------------------------------------------------------------- C3 / H5 secrets

    public function test_secrets_are_visible_only_where_needed(): void
    {
        $agentView = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/properties')->assertOk()->getContent();
        $this->assertStringNotContainsString('whsec_TOP_SECRET', $agentView);
        $this->assertStringNotContainsString('hooks.a.test', $agentView);
        $this->assertStringNotContainsString($this->propA->secret_key, $agentView);

        $adminView = $this->actingAs($this->adminA, 'sanctum')->getJson('/api/v1/properties')->assertOk();
        $this->assertStringContainsString($this->propA->secret_key, $adminView->getContent());
        $this->assertStringContainsString('hooks.a.test', $adminView->getContent());
        $this->assertStringNotContainsString('whsec_TOP_SECRET', $adminView->getContent(), 'The webhook secret is write-only.');
        $this->assertTrue($adminView->json('data.0.widget_config.webhook_secret_set'));
    }

    public function test_saving_settings_with_blank_secret_keeps_existing_secret_in_encrypted_column(): void
    {
        $this->actingAs($this->adminA, 'sanctum')->putJson("/api/v1/properties/{$this->propA->id}", [
            'widget_config' => ['primary_color' => '#111111', 'webhook_url' => 'https://93.184.216.34/hook', 'webhook_secret' => ''],
        ])->assertOk();

        $fresh = $this->propA->fresh();
        $this->assertSame('whsec_TOP_SECRET', $fresh->effectiveWebhookSecret());
        $this->assertArrayNotHasKey('webhook_secret', $fresh->widget_config, 'Secret must move out of widget_config.');
        $this->assertNotSame('whsec_TOP_SECRET', $fresh->getRawOriginal('webhook_secret'), 'Secret must be stored encrypted.');
    }

    public function test_webhook_url_pointing_to_internal_network_cannot_be_saved(): void
    {
        $this->actingAs($this->adminA, 'sanctum')->putJson("/api/v1/properties/{$this->propA->id}", [
            'widget_config' => ['webhook_url' => 'http://127.0.0.1/admin'],
        ])->assertStatus(422);
    }

    public function test_webhook_dispatch_skips_internal_targets_and_signs_public_ones(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->propA->update(['widget_config' => array_merge($this->propA->widget_config, ['webhook_url' => 'http://10.0.0.5/hook'])]);
        \App\Services\WebhookService::send('http://10.0.0.5/hook', ['x' => 1], 's');
        Http::assertNothingSent();

        $result = \App\Services\WebhookService::send('https://93.184.216.34/hook', ['x' => 1], 'sec');
        $this->assertTrue($result['success']);
        Http::assertSent(fn ($req) => str_starts_with($req->header('X-ChatBox-Signature')[0] ?? '', 'sha256='));
    }

    // ---------------------------------------------------------------- C4 / H9 attachments

    public function test_attachment_upload_is_private_and_served_only_by_signed_link(): void
    {
        $conv = $this->conversationFor($this->propA);
        // Uploads need the visitor's own conversation (no anonymous file hosting).
        $this->postJson('/api/v1/widget/upload', [
            'property_key' => $this->propA->public_key, 'visitor_token' => 'vtok_abcdef01', 'file' => UploadedFile::fake()->image('x.png'),
        ])->assertStatus(422);
        $res = $this->postJson('/api/v1/widget/upload', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_abcdef01',
            'conversation_id' => $conv->id,
            'file' => UploadedFile::fake()->image('screen.png'),
        ])->assertOk();

        $attachment = Attachment::findOrFail($res->json('attachment_id'));
        $this->assertSame('image/png', $attachment->mime);
        $this->assertStringEndsWith('.png', $attachment->path);
        $this->assertFileDoesNotExist(public_path($attachment->path));

        $this->get($res->json('url'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/v1/attachments/' . $attachment->id)->assertStatus(403);
        $this->get($res->json('url') . 'tampered')->assertStatus(403);

        // Visitor sends it in their own conversation.
        $msg = $this->postJson('/api/v1/widget/messages', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_abcdef01',
            'conversation_id' => $conv->id,
            'attachment_id' => $attachment->id,
        ])->assertCreated();
        $this->assertSame('image', $msg->json('data.message_type'));
        $this->assertStringContainsString('signature=', $msg->json('data.attachment_url'));
    }

    public function test_html_disguised_as_image_and_external_attachment_urls_are_rejected(): void
    {
        $first = $this->conversationFor($this->propA);
        $html = UploadedFile::fake()->createWithContent('photo.png', '<html><script>alert(1)</script></html>');
        $this->postJson('/api/v1/widget/upload', [
            'property_key' => $this->propA->public_key, 'visitor_token' => 'vtok_abcdef01', 'conversation_id' => $first->id, 'file' => $html,
        ])->assertStatus(422);

        $conv = Conversation::first();
        $this->postJson('/api/v1/widget/messages', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_abcdef01',
            'conversation_id' => $conv->id,
            'content' => 'look',
            'attachment_url' => 'javascript:alert(document.cookie)',
        ])->assertStatus(422);
    }

    public function test_visitor_cannot_attach_another_visitors_upload(): void
    {
        $own = $this->conversationFor($this->propA, 'vtok_owner001');
        $up = $this->postJson('/api/v1/widget/upload', [
            'property_key' => $this->propA->public_key, 'visitor_token' => 'vtok_owner001', 'conversation_id' => $own->id, 'file' => UploadedFile::fake()->image('a.png'),
        ])->assertOk();

        $other = $this->conversationFor($this->propA, 'vtok_attack01');
        // An attacker cannot upload into someone else's conversation either.
        $this->postJson('/api/v1/widget/upload', [
            'property_key' => $this->propA->public_key, 'visitor_token' => 'vtok_attack01', 'conversation_id' => $own->id, 'file' => UploadedFile::fake()->image('b.png'),
        ])->assertNotFound();
        $this->postJson('/api/v1/widget/messages', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_attack01',
            'conversation_id' => $other->id,
            'attachment_id' => $up->json('attachment_id'),
        ])->assertStatus(422);
    }

    // ---------------------------------------------------------------- B4 sync

    public function test_messages_get_strictly_increasing_sequence_and_sync_by_seq(): void
    {
        $conv = $this->conversationFor($this->propA);
        foreach (['one', 'two', 'three'] as $text) {
            Message::create(['conversation_id' => $conv->id, 'sender_type' => 'visitor', 'message_type' => 'text', 'content' => $text]);
        }

        $this->assertSame([1, 2, 3], Message::where('conversation_id', $conv->id)->orderBy('seq')->pluck('seq')->all());
        $this->assertSame(3, $conv->fresh()->last_seq);

        $res = $this->getJson("/api/v1/widget/conversations/{$conv->id}/sync?visitor_token=vtok_abcdef01&after_seq=1")->assertOk();
        $this->assertSame(['two', 'three'], collect($res->json('data.messages'))->pluck('content')->all());
        $this->assertSame(3, $res->json('data.last_seq'));

        $this->getJson("/api/v1/widget/conversations/{$conv->id}/sync?visitor_token=wrong&after_seq=0")->assertStatus(404);
    }

    public function test_legacy_html_encoded_messages_display_as_plain_text_without_rewriting_storage(): void
    {
        $conv = $this->conversationFor($this->propA);
        $legacy = Message::create(['conversation_id' => $conv->id, 'sender_type' => 'visitor', 'message_type' => 'text', 'content' => 'don&#039;t &amp; relax']);
        $legacy->forceFill(['content_html_encoded' => true])->save();
        $plain = Message::create(['conversation_id' => $conv->id, 'sender_type' => 'visitor', 'message_type' => 'text', 'content' => 'x &amp; y typed literally']);

        $res = $this->getJson("/api/v1/widget/conversations/{$conv->id}/sync?visitor_token=vtok_abcdef01&after_seq=0")->assertOk();
        $this->assertSame(["don't & relax", 'x &amp; y typed literally'], collect($res->json('data.messages'))->pluck('content')->all());
        $this->assertSame('don&#039;t &amp; relax', $legacy->fresh()->getRawOriginal('content'));
        $this->assertFalse((bool) $plain->fresh()->content_html_encoded);
    }

    // ---------------------------------------------------------------- H1 origin on all widget endpoints

    public function test_wrong_origin_is_rejected_on_message_endpoints_too(): void
    {
        $conv = $this->conversationFor($this->propA);
        $this->withHeaders(['Origin' => 'https://evil.test'])->postJson('/api/v1/widget/messages', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_abcdef01',
            'conversation_id' => $conv->id,
            'content' => 'hi',
        ])->assertStatus(403);

        $this->withHeaders(['Origin' => 'https://www.a.test'])->postJson('/api/v1/widget/messages', [
            'property_key' => $this->propA->public_key,
            'visitor_token' => 'vtok_abcdef01',
            'conversation_id' => $conv->id,
            'content' => 'hi',
        ])->assertCreated();
    }

    // ---------------------------------------------------------------- H2 / H3 tenant isolation

    public function test_cross_tenant_department_and_agent_references_are_rejected(): void
    {
        $deptB = Department::create(['property_id' => $this->propB->id, 'name' => 'B Sales', 'is_active' => true]);
        $conv = $this->conversationFor($this->propA);

        $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$conv->id}/transfer", ['department_id' => $deptB->id])
            ->assertStatus(422);
        $this->actingAs($this->agentA, 'sanctum')
            ->postJson("/api/v1/chat/conversations/{$conv->id}/transfer", ['agent_id' => $this->agentB->id])
            ->assertStatus(422);
        $this->assertNull($conv->fresh()->department_id);

        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/agents', [
            'name' => 'New', 'email' => 'new@a.test', 'password' => 'password-123', 'department_ids' => [$deptB->id],
        ])->assertStatus(422);

        Visitor::create(['property_id' => $this->propA->id, 'visitor_token' => 'vtok_dept0001']);
        $this->postJson('/api/v1/widget/start', [
            'property_key' => $this->propA->public_key, 'visitor_token' => 'vtok_dept0001',
            'department_id' => $deptB->id, 'initial_message' => 'hello',
        ])->assertStatus(422);
    }

    public function test_agent_still_sees_own_tenant_canned_responses(): void
    {
        CannedResponse::create(['property_id' => $this->propA->id, 'shortcut' => '/hi', 'content' => 'OWN_TEXT']);
        CannedResponse::create(['property_id' => $this->propB->id, 'shortcut' => '/hi', 'content' => 'OTHER_TEXT']);

        $all = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/chat/canned-responses')->getContent();
        $this->assertStringContainsString('OWN_TEXT', $all);
        $this->assertStringNotContainsString('OTHER_TEXT', $all);

        $scoped = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/chat/canned-responses?property_id=' . $this->propA->id)->getContent();
        $this->assertStringContainsString('OWN_TEXT', $scoped);
    }

    // ---------------------------------------------------------------- M4 CORS

    public function test_cors_is_open_for_widget_but_not_for_staff_api(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.test'])
            ->getJson('/api/v1/widget/status?property_key=' . $this->propA->public_key)
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $staff = $this->actingAs($this->agentA, 'sanctum')->withHeaders(['Origin' => 'https://evil.test'])->getJson('/api/v1/chat/stats');
        $this->assertFalse($staff->headers->has('Access-Control-Allow-Origin'));
    }

    // ---------------------------------------------------------------- M6 registration

    public function test_self_registration_can_be_disabled(): void
    {
        config(['chatbox.public_registration' => false]);
        $this->postJson('/api/v1/auth/register-tenant', [
            'company_name' => 'X', 'name' => 'X', 'email' => 'x@x.test', 'password' => 'password-123',
        ])->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'x@x.test']);
    }

    // ---------------------------------------------------------------- H10 transcripts

    public function test_transcripts_only_go_to_the_chat_email_and_are_rate_limited(): void
    {
        Mail::fake();
        $conv = $this->conversationFor($this->propA);
        $conv->visitor->update(['email' => 'me@example.com']);
        $url = "/api/v1/widget/conversations/{$conv->id}/transcript";
        $base = ['property_key' => $this->propA->public_key, 'visitor_token' => 'vtok_abcdef01'];

        // Not a relay: arbitrary recipients are refused.
        $this->postJson($url, $base + ['email' => 'victim@corp.test'])->assertStatus(422);
        $this->postJson($url, $base + ['email' => 'ME@example.com'])->assertOk()->assertJsonPath('sent', true);
        // Once per recipient per day.
        $this->postJson($url, $base + ['email' => 'me@example.com'])->assertStatus(429);
    }

    // ---------------------------------------------------------------- M1 / M3 export + audit

    public function test_legacy_csv_export_escapes_every_formula_cell_and_is_audited(): void
    {
        Visitor::create(['property_id' => $this->propA->id, 'visitor_token' => 'vtok_csv00001', 'name' => '=HYPERLINK("http://evil.test","x")', 'phone' => '+1 555', 'metadata' => ['initial_inquiry' => '@SUM(1)']]);
        Visitor::create(['property_id' => $this->propB->id, 'visitor_token' => 'vtok_csv00002', 'name' => 'Tenant B Person']);

        $csv = $this->actingAs($this->agentA, 'sanctum')->get('/api/v1/crm/leads/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM(1)", $csv);
        $this->assertStringContainsString("'+1 555", $csv);
        $this->assertStringNotContainsString('Tenant B Person', $csv);
        $this->assertTrue(AuditLog::where('action', 'crm.leads.exported')->where('actor_id', $this->agentA->id)->exists());
    }

    public function test_login_success_and_failure_are_audited(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@a.test', 'password' => 'nope'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@a.test', 'password' => 'old-password-A'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'subject_id' => $this->adminA->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $this->adminA->id]);
        $this->assertStringNotContainsString('old-password-A', (string) AuditLog::pluck('meta')->toJson());
    }

    // ---------------------------------------------------------------- B6 overnight hours

    public function test_operating_hours_support_overnight_ranges(): void
    {
        $c = \App\Http\Controllers\Api\WidgetChatController::class;
        $this->assertTrue($c::isWithinHours('23:30', '22:00', '06:00'));
        $this->assertTrue($c::isWithinHours('05:59', '22:00', '06:00'));
        $this->assertFalse($c::isWithinHours('12:00', '22:00', '06:00'));
        $this->assertTrue($c::isWithinHours('09:00', '09:00', '18:00'));
        $this->assertFalse($c::isWithinHours('18:00', '09:00', '18:00'));
    }

    // ---------------------------------------------------------------- H7 token expiry

    public function test_staff_tokens_expire(): void
    {
        $this->assertNotNull(config('sanctum.expiration'));
        $token = $this->agentA->createToken('t')->plainTextToken;
        $this->travel((int) config('sanctum.expiration') + 1)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ---------------------------------------------------------------- demo account command

    public function test_clean_accounts_command_keeps_real_users_and_uses_random_passwords(): void
    {
        User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Demo', 'email' => 'rahul@chatbox.com', 'password' => Hash::make('x'), 'role' => 'agent']);

        $this->artisan('chatbox:clean-accounts')
            ->expectsConfirmation('This creates/updates master accounts and deletes the known demo accounts. Continue?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'agent@b.test']);
        $this->assertDatabaseHas('users', ['email' => 'admin@a.test']);
        $this->assertDatabaseMissing('users', ['email' => 'rahul@chatbox.com']);
        $this->assertFalse(Hash::check('password123', User::where('email', 'ambitionmanagements@gmail.com')->value('password')));
    }
}
