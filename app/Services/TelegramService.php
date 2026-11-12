<?php

namespace App\Services;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

/**
 * Telegram Bot API client. Only api.telegram.org is ever contacted (fixed host, no SSRF surface).
 * The bot token is part of the URL path, so it is never logged: errors are sanitised.
 */
class TelegramService
{
    /** @return array{ok: bool, error: string} */
    public static function send(NotificationChannel $channel, string $text): array
    {
        $token = (string) $channel->bot_token;
        if (! preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,64}$/', $token)) {
            return ['ok' => false, 'error' => 'Invalid bot token format.'];
        }

        try {
            $response = Http::timeout(8)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $channel->chat_id,
                'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => true,
            ]);
            $json = $response->json();
            if ($response->successful() && ($json['ok'] ?? false) === true) {
                return ['ok' => true, 'error' => ''];
            }

            $desc = mb_substr((string) ($json['description'] ?? 'unknown error'), 0, 200);
            if (stripos($desc, 'chat not found') !== false) {
                $desc .= ' — Open your bot in Telegram and press /start first (for personal chat), or add the bot to your group/channel and use its negative (-100...) chat ID.';
            }

            return ['ok' => false, 'error' => 'Telegram error ' . $response->status() . ': ' . $desc];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Connection failed: ' . str_replace($token, '[token]', $e->getMessage())];
        }
    }
}
