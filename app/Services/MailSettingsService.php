<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\MailFake;

/**
 * Platform-wide SMTP / Mail configuration stored in platform_settings.
 *
 * Allows the Super Admin to configure SMTP credentials from the dashboard
 * so password resets, customer PIN resets, and transcripts work without
 * editing .env on the server. Passwords are encrypted at rest via Crypt.
 */
class MailSettingsService
{
    public const SETTING_KEY = 'mail';

    /**
     * Public-safe settings array for the admin UI (never includes password).
     */
    public static function getPublic(): array
    {
        $stored = self::readRaw();
        $envPort = (int) config('mail.mailers.smtp.port', 587);
        $envScheme = (string) config('mail.mailers.smtp.scheme', '');
        $defaultEncryption = $envScheme === 'smtps' || $envPort === 465 ? 'ssl' : 'tls';

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'mailer' => (string) ($stored['mailer'] ?? 'smtp'),
            'host' => (string) ($stored['host'] ?? config('mail.mailers.smtp.host', '')),
            'port' => (int) ($stored['port'] ?? ($envPort ?: 587)),
            'encryption' => (string) ($stored['encryption'] ?? $defaultEncryption),
            'username' => (string) ($stored['username'] ?? config('mail.mailers.smtp.username', '')),
            'has_password' => ! empty($stored['password_encrypted']),
            'from_address' => (string) ($stored['from_address'] ?? config('mail.from.address', '')),
            'from_name' => (string) ($stored['from_name'] ?? config('mail.from.name', 'ChatBox')),
            'updated_at' => $stored['updated_at'] ?? null,
        ];
    }

    /**
     * Persist mail settings, encrypting any newly supplied SMTP password.
     */
    public static function save(array $input, ?string $userId = null): array
    {
        $existing = self::readRaw();

        $passwordEncrypted = $existing['password_encrypted'] ?? null;
        if (! empty($input['clear_password'])) {
            $passwordEncrypted = null;
        } elseif (isset($input['password']) && $input['password'] !== '') {
            $passwordEncrypted = Crypt::encryptString((string) $input['password']);
        }

        $payload = [
            'enabled' => (bool) ($input['enabled'] ?? true),
            'mailer' => 'smtp',
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => (int) ($input['port'] ?? 587),
            'encryption' => in_array(($input['encryption'] ?? 'tls'), ['tls', 'ssl', 'none'], true)
                ? (string) $input['encryption']
                : 'tls',
            'username' => trim((string) ($input['username'] ?? '')),
            'password_encrypted' => $passwordEncrypted,
            'from_address' => trim((string) ($input['from_address'] ?? '')),
            'from_name' => trim((string) ($input['from_name'] ?? 'ChatBox')),
            'updated_at' => now()->toIso8601String(),
        ];

        $now = now();
        $exists = DB::table('platform_settings')->where('key', self::SETTING_KEY)->exists();
        if ($exists) {
            DB::table('platform_settings')->where('key', self::SETTING_KEY)->update([
                'value' => json_encode($payload, JSON_THROW_ON_ERROR),
                'updated_by' => $userId,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('platform_settings')->insert([
                'key' => self::SETTING_KEY,
                'value' => json_encode($payload, JSON_THROW_ON_ERROR),
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::applyRuntimeConfig();

        return self::getPublic();
    }

    /**
     * Apply stored SMTP settings to Laravel's runtime mail config when enabled.
     * Safe to call in tests (does not clobber Mail::fake()).
     */
    public static function applyRuntimeConfig(?array $override = null): void
    {
        $cfg = $override ?? self::readRaw();
        if (empty($cfg) || empty($cfg['enabled']) || empty($cfg['host'])) {
            return;
        }

        $password = null;
        if (! empty($override['password'])) {
            $password = (string) $override['password'];
        } elseif (! empty($cfg['password_encrypted'])) {
            try {
                $password = Crypt::decryptString((string) $cfg['password_encrypted']);
            } catch (\Throwable $e) {
                Log::warning('Could not decrypt stored SMTP password: ' . $e->getMessage());
            }
        }

        $encryption = (string) ($cfg['encryption'] ?? 'tls');
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        Config::set('mail.mailers.smtp.host', (string) $cfg['host']);
        Config::set('mail.mailers.smtp.port', (int) ($cfg['port'] ?? 587));
        Config::set('mail.mailers.smtp.scheme', $scheme);
        Config::set('mail.mailers.smtp.username', ($cfg['username'] ?? '') !== '' ? (string) $cfg['username'] : null);
        Config::set('mail.mailers.smtp.password', $password);

        if ($encryption === 'none') {
            Config::set('mail.mailers.smtp.auto_tls', false);
        } else {
            Config::set('mail.mailers.smtp.auto_tls', true);
        }

        if (! empty($cfg['from_address'])) {
            Config::set('mail.from.address', (string) $cfg['from_address']);
        }
        if (! empty($cfg['from_name'])) {
            Config::set('mail.from.name', (string) $cfg['from_name']);
        }

        if (Mail::getFacadeRoot() instanceof MailFake) {
            return;
        }

        Config::set('mail.default', 'smtp');
        Mail::purge('smtp');
    }

    /**
     * Send a test email using either the saved config or unsaved form values.
     */
    public static function sendTestEmail(string $toEmail, ?array $draftInput = null): void
    {
        if ($draftInput !== null) {
            $existing = self::readRaw();
            $override = [
                'enabled' => true,
                'host' => trim((string) ($draftInput['host'] ?? '')),
                'port' => (int) ($draftInput['port'] ?? 587),
                'encryption' => (string) ($draftInput['encryption'] ?? 'tls'),
                'username' => trim((string) ($draftInput['username'] ?? '')),
                'password' => ($draftInput['password'] ?? '') !== '' ? (string) $draftInput['password'] : null,
                'password_encrypted' => ! empty($draftInput['clear_password']) ? null : ($existing['password_encrypted'] ?? null),
                'from_address' => trim((string) ($draftInput['from_address'] ?? '')),
                'from_name' => trim((string) ($draftInput['from_name'] ?? 'ChatBox')),
            ];
            self::applyRuntimeConfig($override);
        } else {
            self::applyRuntimeConfig();
        }

        $appName = (string) config('mail.from.name', 'ChatBox');
        $body = "This is a test email from {$appName}.\n\nIf you received this message, your SMTP settings are working correctly for Forgot Password and support notifications.";

        Mail::raw($body, function ($message) use ($toEmail, $appName) {
            $message->to($toEmail)->subject("{$appName} — SMTP Test Email");
        });
    }

    private static function readRaw(): array
    {
        try {
            if (! Schema::hasTable('platform_settings')) {
                return [];
            }

            $row = DB::table('platform_settings')->where('key', self::SETTING_KEY)->first();
            if (! $row || empty($row->value)) {
                return [];
            }

            $decoded = json_decode((string) $row->value, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
