<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\CustomerAccount;
use App\Models\CustomerOtp;
use App\Models\CustomerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** Stage 1: customer Support PIN identity and conversation ownership (audit H8). */
class CustomerIdentityTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
    }

    private function signup(string $email = 'rahul@example.com', string $pin = '482916', ?string $visitorToken = null)
    {
        return $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/signup', array_filter([
            'property_key' => $this->siteA->public_key, 'email' => $email, 'pin' => $pin, 'visitor_token' => $visitorToken,
        ]));
    }

    private function login(string $email, string $pin)
    {
        return $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/login', [
            'property_key' => $this->siteA->public_key, 'email' => $email, 'pin' => $pin,
        ]);
    }

    public function test_identify_tells_the_chat_whether_to_create_or_enter_a_pin(): void
    {
        $this->signup('known@example.com')->assertCreated();
        $ask = fn ($email) => $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/identify', ['property_key' => $this->siteA->public_key, 'email' => $email]);

        $ask('KNOWN@example.com')->assertOk()->assertJsonPath('data.exists', true);
        $ask('new@example.com')->assertOk()->assertJsonPath('data.exists', false);
        // One company, several websites: the customer is recognised on every website of the SAME company (commit 9321038)...
        $this->withHeaders($this->origin($this->siteA2))->postJson('/api/v1/widget/customer/identify', ['property_key' => $this->siteA2->public_key, 'email' => 'known@example.com'])
            ->assertOk()->assertJsonPath('data.exists', true);
        // ...but never across companies: another company's website sees a brand-new customer.
        $this->withHeaders($this->origin($this->siteB))->postJson('/api/v1/widget/customer/identify', ['property_key' => $this->siteB->public_key, 'email' => 'known@example.com'])
            ->assertOk()->assertJsonPath('data.exists', false);
    }

    public function test_website_admin_can_reset_a_forgotten_pin_and_old_sessions_end(): void
    {
        $oldToken = $this->signup('forgetful@example.com', '482916')->assertCreated()->json('data.token');
        $account = CustomerAccount::where('email_normalized', 'forgetful@example.com')->firstOrFail();

        // Staff list shows the customer, never the PIN.
        $list = $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/customers?search=forgetful')->assertOk();
        $this->assertSame('forgetful@example.com', $list->json('data.0.email'));
        $this->assertStringNotContainsString('pin_hash', $list->getContent());
        $this->assertTrue($list->json('data.0.can_manage'), 'Company owner can manage the customer (Reset PIN shown).');

        // Agents (not website admins) and other companies cannot change PINs.
        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/customers/{$account->id}/reset-pin", ['pin' => '730581'])->assertForbidden();
        $this->actingAs($this->agentB, 'sanctum')->postJson("/api/v1/customers/{$account->id}/reset-pin", ['pin' => '730581'])->assertNotFound();
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/customers/{$account->id}/reset-pin", ['pin' => '111111'])->assertStatus(422);

        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/customers/{$account->id}/reset-pin", ['pin' => '730581'])->assertOk();
        $this->assertTrue(Hash::check('730581', $account->fresh()->pin_hash));
        $this->assertNull($account->fresh()->email_verified_at, 'A staff reset does not prove e-mail ownership.');
        $this->assertStringContainsString('Support PIN was changed', (string) $this->lastMailBody() . ' ' . $this->lastMailSubject());

        app('auth')->forgetGuards();
        $this->withHeaders($this->origin($this->siteA) + ['X-Customer-Token' => $oldToken])->getJson('/api/v1/widget/customer/me?property_key=' . $this->siteA->public_key)->assertStatus(401);
        $this->login('forgetful@example.com', '482916')->assertStatus(422);
        $this->login('forgetful@example.com', '730581')->assertOk();
    }

    public function test_website_admin_can_unlock_a_locked_customer(): void
    {
        $this->signup('locked@example.com', '482916')->assertCreated();
        for ($i = 0; $i < 5; $i++) {
            $this->login('locked@example.com', '000000');
        }
        $account = CustomerAccount::where('email_normalized', 'locked@example.com')->firstOrFail();
        $this->assertTrue($account->isLocked());
        $this->actingAs($this->ownerA, 'sanctum')->postJson("/api/v1/customers/{$account->id}/unlock")->assertOk();
        app('auth')->forgetGuards();
        $this->login('locked@example.com', '482916')->assertOk();
    }

    public function test_signup_without_otp_stores_hashed_pin_and_returns_a_session(): void
    {
        $res = $this->signup('Rahul@Example.com')->assertCreated();
        $token = $res->json('data.token');

        $account = CustomerAccount::firstOrFail();
        $this->assertSame('rahul@example.com', $account->email_normalized);
        $this->assertNull($account->email_verified_at, 'Creating a PIN must not mark the e-mail as verified.');
        $this->assertNotSame('482916', $account->pin_hash);
        $this->assertTrue(Hash::check('482916', $account->pin_hash));
        $this->assertStringNotContainsString('482916', $res->getContent());
        $this->assertStringNotContainsString('pin_hash', $res->getContent());
        $this->assertSame(0, $this->mailCount(), 'No signup OTP / e-mail is required.');

        $this->withHeaders($this->origin($this->siteA) + ['X-Customer-Token' => $token])
            ->getJson('/api/v1/widget/customer/me?property_key=' . $this->siteA->public_key)
            ->assertOk()->assertJsonPath('data.customer.email_verified', false);
        $this->assertSame(hash('sha256', $token), CustomerSession::first()->token_hash, 'Only the token hash is stored.');
    }

    public function test_pin_rules_are_enforced(): void
    {
        $this->signup('a@x.test', '12345')->assertStatus(422);
        $this->signup('a@x.test', 'abcdef')->assertStatus(422);
        $this->signup('a@x.test', '123456')->assertStatus(422); // trivial
        $this->signup('a@x.test', '111111')->assertStatus(422);
        $this->assertSame(0, CustomerAccount::count());
    }

    public function test_signup_with_existing_email_never_overwrites_or_logs_in_and_warns_owner(): void
    {
        $this->signup('owner@example.com', '482916')->assertCreated();
        $before = CustomerAccount::first()->pin_hash;

        $res = $this->signup('OWNER@example.com', '739201')->assertStatus(409)->assertJsonPath('code', 'account_exists');
        $this->assertNull($res->json('data.token'));
        $this->assertSame($before, CustomerAccount::first()->pin_hash);
        $this->assertSame(1, CustomerAccount::count());
        $this->assertStringContainsString('Someone tried to create a new support account', (string) $this->lastMailBody());
    }

    public function test_login_is_generic_and_locks_after_five_failures(): void
    {
        $this->signup('lock@example.com', '482916')->assertCreated();

        $unknown = $this->login('nobody@example.com', '482916')->assertStatus(422)->json('message');
        $wrong = $this->login('lock@example.com', '000001')->assertStatus(422)->json('message');
        $this->assertSame($unknown, $wrong, 'Unknown e-mail and wrong PIN must look identical.');

        for ($i = 0; $i < 4; $i++) {
            $this->login('lock@example.com', '000002');
        }
        $account = CustomerAccount::first();
        $this->assertTrue($account->isLocked());
        $this->assertTrue($account->locked_until->between(now()->addMinutes(14), now()->addMinutes(16)));

        // Even the correct PIN is refused while locked, with the same generic message.
        $this->login('lock@example.com', '482916')->assertStatus(422)->assertJsonPath('message', $wrong);
        $this->assertStringContainsString('locked', (string) $this->lastMailBody());

        $this->travel(16)->minutes();
        $this->login('lock@example.com', '482916')->assertOk();
    }

    public function test_forgot_pin_otp_resets_pin_verifies_email_and_revokes_old_sessions(): void
    {
        $old = $this->signup('forgot@example.com', '482916')->json('data.token');

        $sent = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/forgot-pin', ['property_key' => $this->siteA->public_key, 'email' => 'forgot@example.com'])->assertOk()->json('message');
        $unknown = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/forgot-pin', ['property_key' => $this->siteA->public_key, 'email' => 'nobody@example.com'])->assertOk()->json('message');
        $this->assertSame($sent, $unknown, 'Forgot-PIN must not reveal whether the account exists.');

        $body = (string) $this->lastMailBody();
        // Last mail is for the existing account (unknown address sends nothing).
        preg_match('/\b(\d{6})\b/', $body, $m);
        $code = $m[1];
        $this->assertStringNotContainsString('http', $body, 'Authentication e-mails contain no links.');
        $this->assertStringContainsString('expires in 10 minutes', $body);

        $reset = fn ($c, $pin = '739201') => $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/reset-pin', [
            'property_key' => $this->siteA->public_key, 'email' => 'forgot@example.com', 'code' => $c, 'pin' => $pin,
        ]);

        $reset($code === '000000' ? '000001' : '000000')->assertStatus(422);
        $new = $reset($code)->assertOk()->json('data.token');
        $reset($code, '846203')->assertStatus(422); // single use

        $account = CustomerAccount::first();
        $this->assertTrue(Hash::check('739201', $account->pin_hash));
        $this->assertNotNull($account->email_verified_at, 'OTP proves e-mail ownership.');
        $me = fn ($t) => $this->withHeaders($this->origin($this->siteA) + ['X-Customer-Token' => $t])->getJson('/api/v1/widget/customer/me?property_key=' . $this->siteA->public_key);
        $me($old)->assertStatus(401);
        $me($new)->assertOk();
    }

    public function test_otp_expires_and_attempts_are_limited(): void
    {
        $this->signup('otp@example.com', '482916');
        $account = CustomerAccount::first();
        \App\Services\CustomerAuthService::sendOtp($account, 'pin_reset');
        preg_match('/\b(\d{6})\b/', (string) $this->lastMailBody(), $m);

        for ($i = 0; $i < CustomerOtp::MAX_ATTEMPTS; $i++) {
            \App\Services\CustomerAuthService::verifyOtp($account, 'pin_reset', $m[1] === '999999' ? '999998' : '999999');
        }
        $this->assertSame('expired', \App\Services\CustomerAuthService::verifyOtp($account, 'pin_reset', $m[1]), 'Locked after too many wrong codes.');

        \App\Services\CustomerAuthService::sendOtp($account, 'pin_reset');
        preg_match('/\b(\d{6})\b/', (string) $this->lastMailBody(), $m2);
        $this->travel(11)->minutes();
        $this->assertSame('expired', \App\Services\CustomerAuthService::verifyOtp($account, 'pin_reset', $m2[1]));
    }

    public function test_history_is_available_on_another_device_only_with_the_pin_session(): void
    {
        [$deviceToken, $convId] = $this->startChat($this->siteA, 'My website shows 500 error');
        $session = $this->signup('history@example.com', '482916', $deviceToken)->json('data.token');
        $this->assertSame(CustomerAccount::first()->id, Conversation::find($convId)->customer_account_id, 'The guest chat of this device is attached to the new account.');

        // H8: the old device token alone no longer opens an account-owned conversation.
        $this->getJson("/api/v1/widget/conversations/{$convId}/sync?visitor_token={$deviceToken}&after_seq=0")->assertNotFound();

        // New device (new visitor token) + PIN login sees and continues the same conversation.
        $login = $this->login('history@example.com', '482916')->assertOk();
        $this->assertSame($convId, $login->json('data.conversations.0.id'));
        $token2 = $login->json('data.token');
        $newDevice = $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/init', ['property_key' => $this->siteA->public_key])->json('data.visitor.token');

        $this->withHeaders(['X-Customer-Token' => $token2])->getJson("/api/v1/widget/conversations/{$convId}/sync?visitor_token={$newDevice}&after_seq=0")
            ->assertOk()->assertJsonPath('data.messages.0.content', 'My website shows 500 error');
        $this->say($this->siteA, $newDevice, $convId, 'Still broken', ['X-Customer-Token' => $token2])->assertCreated();
    }

    public function test_customers_cannot_see_each_others_conversations_or_cross_websites(): void
    {
        [$t1, $conv1] = $this->startChat($this->siteA, 'first customer question');
        $s1 = $this->signup('one@example.com', '482916', $t1)->json('data.token');
        $s2 = $this->signup('two@example.com', '739201')->json('data.token');

        $this->withHeaders($this->origin($this->siteA) + ['X-Customer-Token' => $s2])
            ->getJson("/api/v1/widget/customer/conversations/{$conv1}?property_key=" . $this->siteA->public_key)->assertNotFound();
        $this->withHeaders(['X-Customer-Token' => $s2])->getJson("/api/v1/widget/conversations/{$conv1}/sync?visitor_token=x&after_seq=0")->assertNotFound();

        // Same e-mail on another website is a different, independent account.
        $this->withHeaders($this->origin($this->siteA2))->postJson('/api/v1/widget/customer/signup', ['property_key' => $this->siteA2->public_key, 'email' => 'one@example.com', 'pin' => '573920'])->assertCreated();
        $this->assertSame(2, CustomerAccount::where('email_normalized', 'one@example.com')->count());
        // A site A session is not valid on site A2.
        $this->withHeaders($this->origin($this->siteA2) + ['X-Customer-Token' => $s1])->getJson('/api/v1/widget/customer/me?property_key=' . $this->siteA2->public_key)->assertStatus(401);
    }

    public function test_pin_required_mode_blocks_guest_chats(): void
    {
        $this->siteA->update(['identity_mode' => 'pin_required']);
        \Illuminate\Support\Facades\Cache::flush();
        [, , $start] = $this->startChat($this->siteA, 'hello');
        $start->assertStatus(401)->assertJsonPath('code', 'customer_unauthenticated');

        $token = $this->signup('req@example.com', '482916')->json('data.token');
        [, $convId] = $this->startChat($this->siteA, 'need help', ['X-Customer-Token' => $token]);
        $this->assertNotNull(Conversation::find($convId)->customer_account_id);
    }

    public function test_first_ownership_proof_flags_earlier_conversations_for_dispute_review(): void
    {
        [$t, $convId] = $this->startChat($this->siteA, 'question before verification');
        $this->signup('claim@example.com', '482916', $t);
        $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/forgot-pin', ['property_key' => $this->siteA->public_key, 'email' => 'claim@example.com']);
        preg_match('/\b(\d{6})\b/', (string) $this->lastMailBody(), $m);
        $this->withHeaders($this->origin($this->siteA))->postJson('/api/v1/widget/customer/reset-pin', ['property_key' => $this->siteA->public_key, 'email' => 'claim@example.com', 'code' => $m[1], 'pin' => '739201'])->assertOk();

        $this->assertTrue(Conversation::find($convId)->pre_verification);
    }

    public function test_email_verification_with_otp(): void
    {
        $token = $this->signup('verify@example.com', '482916')->json('data.token');
        $h = $this->origin($this->siteA) + ['X-Customer-Token' => $token];
        $this->withHeaders($h)->postJson('/api/v1/widget/customer/verify-email/request', ['property_key' => $this->siteA->public_key])->assertOk();
        preg_match('/\b(\d{6})\b/', (string) $this->lastMailBody(), $m);
        $this->withHeaders($h)->postJson('/api/v1/widget/customer/verify-email/confirm', ['property_key' => $this->siteA->public_key, 'code' => $m[1]])->assertOk();
        $this->assertNotNull(CustomerAccount::first()->email_verified_at);
    }
}
