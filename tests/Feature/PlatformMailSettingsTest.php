<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\MailSettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        return User::create([
            'tenant_id' => null,
            'name' => 'Platform Super Admin',
            'email' => 'super@example.com',
            'password' => Hash::make('Password123!'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function makeCompanyAdmin(): User
    {
        $tenant = Tenant::create([
            'name' => 'SaluteHost',
            'email' => 'owner@salutehost.com',
            'status' => 'active',
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Company Admin',
            'email' => 'admin@salutehost.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_non_super_admin_cannot_access_mail_settings(): void
    {
        $admin = $this->makeCompanyAdmin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/platform/mail-settings')->assertStatus(403);
        $this->postJson('/api/v1/platform/mail-settings', [
            'enabled' => true,
            'host' => 'mail.salutehost.com',
            'port' => 465,
            'encryption' => 'ssl',
            'from_address' => 'support@salutehost.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/platform/mail-settings/test', [
            'to_email' => 'admin@salutehost.com',
        ])->assertStatus(403);
    }

    public function test_super_admin_can_save_and_retrieve_encrypted_mail_settings(): void
    {
        $super = $this->makeSuperAdmin();
        Sanctum::actingAs($super);

        $res = $this->postJson('/api/v1/platform/mail-settings', [
            'enabled' => true,
            'host' => 'mail.salutehost.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'support@salutehost.com',
            'password' => 'SuperSecretSmtpPass!',
            'from_address' => 'support@salutehost.com',
            'from_name' => 'SaluteHost Support',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.host', 'mail.salutehost.com')
            ->assertJsonPath('data.port', 465)
            ->assertJsonPath('data.encryption', 'ssl')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissing(['password' => 'SuperSecretSmtpPass!'])
            ->assertJsonMissingPath('data.password_encrypted');

        // Plaintext password must never appear in raw DB row.
        $raw = DB::table('platform_settings')->where('key', MailSettingsService::SETTING_KEY)->value('value');
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString('SuperSecretSmtpPass!', $raw);

        // Updating without password preserves existing encrypted password.
        $res2 = $this->postJson('/api/v1/platform/mail-settings', [
            'enabled' => true,
            'host' => 'smtp.salutehost.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'support@salutehost.com',
            'password' => '',
            'from_address' => 'support@salutehost.com',
            'from_name' => 'SaluteHost Support',
        ]);

        $res2->assertOk()
            ->assertJsonPath('data.host', 'smtp.salutehost.com')
            ->assertJsonPath('data.has_password', true);

        $get = $this->getJson('/api/v1/platform/mail-settings');
        $get->assertOk()
            ->assertJsonPath('data.host', 'smtp.salutehost.com')
            ->assertJsonPath('data.has_password', true);
    }

    public function test_forgot_password_applies_db_mail_config_and_resets_password(): void
    {
        $super = $this->makeSuperAdmin();
        $admin = $this->makeCompanyAdmin();

        MailSettingsService::save([
            'enabled' => true,
            'host' => 'mail.salutehost.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'support@salutehost.com',
            'password' => 'SecretMailPass123',
            'from_address' => 'noreply@salutehost.com',
            'from_name' => 'SaluteHost Helpdesk',
        ], $super->id);

        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ADMIN@salutehost.com',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('mail.salutehost.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('SecretMailPass123', config('mail.mailers.smtp.password'));
        $this->assertSame('noreply@salutehost.com', config('mail.from.address'));
        $this->assertSame('SaluteHost Helpdesk', config('mail.from.name'));

        $capturedToken = null;
        Notification::assertSentTo($admin, ResetPassword::class, function ($notification) use (&$capturedToken) {
            $capturedToken = $notification->token;

            return true;
        });

        $this->assertNotNull($capturedToken);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'admin@salutehost.com',
            'token' => $capturedToken,
            'password' => 'NewSecurePass99!',
        ])->assertOk()->assertJsonPath('success', true);

        $admin->refresh();
        $this->assertTrue(Hash::check('NewSecurePass99!', $admin->password));
    }

    public function test_super_admin_can_send_test_email(): void
    {
        Mail::fake();
        $super = $this->makeSuperAdmin();
        Sanctum::actingAs($super);

        $res = $this->postJson('/api/v1/platform/mail-settings/test', [
            'to_email' => 'verify@salutehost.com',
            'host' => 'mail.salutehost.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'support@salutehost.com',
            'password' => 'TestPass',
            'from_address' => 'support@salutehost.com',
            'from_name' => 'SaluteHost',
        ]);

        $res->assertOk()->assertJsonPath('success', true);
        $this->assertSame('mail.salutehost.com', config('mail.mailers.smtp.host'));
        $this->assertSame('support@salutehost.com', config('mail.from.address'));
    }
}
