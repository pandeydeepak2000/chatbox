<?php

namespace App\Services;

use App\Models\Property;
use App\Support\OutboundUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public const DEFAULT_EVENTS = ['lead.captured', 'conversation.started', 'message.sent', 'conversation.closed', 'rating.submitted'];

    /**
     * Queue a webhook delivery. It runs after the HTTP response has been sent
     * (cPanel compatible, no worker needed), so a slow endpoint never delays the visitor (audit M2).
     */
    public static function dispatch(Property $property, string $event, array $payload): void
    {
        $config = $property->widget_config ?? [];
        $webhookUrl = $config['webhook_url'] ?? null;
        if (empty($webhookUrl)) {
            return;
        }

        $events = $config['webhook_events'] ?? self::DEFAULT_EVENTS;
        if (! in_array($event, $events, true)) {
            return;
        }

        $body = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'property' => [
                'id' => $property->id,
                'name' => $property->site_name,
                'url' => $property->site_url,
            ],
            'data' => $payload,
        ];
        $secret = $property->effectiveWebhookSecret();
        $propertyId = $property->id;

        dispatch(function () use ($webhookUrl, $body, $secret, $propertyId) {
            $result = WebhookService::send($webhookUrl, $body, $secret, 4);
            if (! $result['success']) {
                Log::warning("ChatBox webhook not delivered for property [{$propertyId}]: {$result['message']}");
            }
        })->afterResponse();
    }

    /**
     * Deliver one signed JSON POST after SSRF validation. Never follows redirects.
     *
     * @return array{success: bool, status?: int, message: string}
     */
    public static function send(string $url, array $body, ?string $secret, int $timeout = 5): array
    {
        $check = OutboundUrlGuard::check($url);
        if (! $check['ok']) {
            return ['success' => false, 'message' => 'Webhook URL rejected: ' . $check['reason']];
        }

        $json = json_encode($body);
        $headers = ['User-Agent' => 'ChatBox-Webhook/1.0'];
        if (! empty($secret)) {
            $headers['X-ChatBox-Signature'] = 'sha256=' . hash_hmac('sha256', $json, $secret);
        }

        try {
            $res = Http::timeout($timeout)
                ->withOptions(OutboundUrlGuard::httpOptions($check))
                ->withHeaders($headers)
                ->withBody($json, 'application/json')
                ->post($url);

            return [
                'success' => $res->successful(),
                'status' => $res->status(),
                'message' => $res->successful()
                    ? "Webhook delivered successfully. Status: {$res->status()}"
                    : "Webhook endpoint returned status {$res->status()}.",
            ];
        } catch (\Throwable $e) {
            // Generic message: low-level network errors would reveal details about other hosts.
            \Illuminate\Support\Facades\Log::info('Webhook delivery failed', ['host' => parse_url($url, PHP_URL_HOST), 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return ['success' => false, 'message' => 'Connection failed: the webhook endpoint could not be reached.'];
        }
    }

    public static function testPing(string $webhookUrl, ?string $secret = null): array
    {
        return self::send($webhookUrl, [
            'event' => 'ping.test',
            'timestamp' => now()->toIso8601String(),
            'message' => 'ChatBox webhook connection test.',
            'sample_lead' => [
                'name' => 'Demo Visitor',
                'email' => 'visitor@example.com',
                'phone' => '+1234567890',
                'inquiry' => 'Testing webhook integration.',
            ],
        ], $secret, 5);
    }
}
