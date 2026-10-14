<?php

namespace App\Services;

use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Dispatch an event to the configured property webhook.
     */
    public static function dispatch(Property $property, string $event, array $payload): void
    {
        $config = $property->widget_config ?? [];
        $webhookUrl = $config['webhook_url'] ?? null;

        if (empty($webhookUrl) || ! filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return;
        }

        $events = $config['webhook_events'] ?? ['lead.captured', 'conversation.started', 'message.sent', 'conversation.closed', 'rating.submitted'];
        if (! in_array($event, $events)) {
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

        $secret = $config['webhook_secret'] ?? null;
        $jsonString = json_encode($body);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'ChatBox-Webhook/1.0',
        ];

        if (! empty($secret)) {
            $headers['X-ChatBox-Signature'] = 'sha256=' . hash_hmac('sha256', $jsonString, $secret);
        }

        try {
            Http::timeout(4)
                ->withHeaders($headers)
                ->withBody($jsonString, 'application/json')
                ->post($webhookUrl);
        } catch (\Throwable $e) {
            Log::warning("ChatBox Webhook failed for Property [{$property->id}]: " . $e->getMessage());
        }
    }

    /**
     * Test webhook delivery from dashboard.
     */
    public static function testPing(string $webhookUrl, ?string $secret = null): array
    {
        if (empty($webhookUrl) || ! filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => 'Invalid Webhook URL.'];
        }

        $body = [
            'event' => 'ping.test',
            'timestamp' => now()->toIso8601String(),
            'message' => 'ChatBox Webhook Connection Successful! Integration is working.',
            'sample_lead' => [
                'name' => 'Demo Visitor',
                'email' => 'visitor@example.com',
                'phone' => '+1234567890',
                'inquiry' => 'Testing Zapier / WhatsApp webhook integration.',
            ],
        ];

        $jsonString = json_encode($body);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'ChatBox-Webhook/1.0',
        ];

        if (! empty($secret)) {
            $headers['X-ChatBox-Signature'] = 'sha256=' . hash_hmac('sha256', $jsonString, $secret);
        }

        try {
            $res = Http::timeout(5)->withHeaders($headers)->withBody($jsonString, 'application/json')->post($webhookUrl);
            return [
                'success' => $res->successful(),
                'status' => $res->status(),
                'message' => $res->successful() ? "Webhook delivered successfully! Status: {$res->status()}" : "Webhook returned error status: {$res->status()}",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }
}
