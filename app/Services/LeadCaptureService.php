<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Automatic lead capture from the chat widget.
 *
 * Rules (Leads brief, "Important Data Rules"):
 *  - one lead per visitor identity (visitor_id is unique on leads); a new message never creates a duplicate
 *  - matching uses ONLY the authorised visitor identity; never name, never an unverified e-mail
 *  - only empty fields are filled automatically; staff edits are never overwritten
 *  - an anonymous visitor who has shared nothing does not become a lead
 *  - capture failures are logged and never break the chat
 */
class LeadCaptureService
{
    private const SERVICE_KEYWORDS = [
        // Order matters: the most specific product wins ("VPS hosting" => VPS, "hosting for my domain" => Hosting).
        'vps' => ['vps', 'virtual private server', 'cloud server', 'kvm'],
        'dedicated' => ['dedicated server', 'dedicated', 'bare metal'],
        'reseller' => ['reseller'],
        'email' => ['email hosting', 'business email', 'webmail', 'mailbox', 'google workspace'],
        'hosting' => ['hosting', 'cpanel', 'web space', 'website host'],
        'ssl' => ['ssl', 'https certificate', 'certificate'],
        'domain' => ['domain', 'domian', 'domin', 'nameserver', '.com', '.in ', 'register a name'],
        'wordpress' => ['wordpress', ' wp '],
    ];

    /**
     * @param  array{name?:?string, email?:?string, phone?:?string, website?:?string, interested_service?:?string, inquiry?:?string}  $data
     */
    public static function capture(Visitor $visitor, ?Conversation $conversation = null, array $data = []): ?Lead
    {
        try {
            return self::doCapture($visitor, $conversation, $data);
        } catch (\Throwable $e) {
            Log::warning('Lead capture failed: ' . $e->getMessage(), ['visitor_id' => $visitor->id]);

            return null;
        }
    }

    private static function doCapture(Visitor $visitor, ?Conversation $conversation, array $data): ?Lead
    {
        $name = $data['name'] ?? $visitor->name;
        $email = $data['email'] ?? $visitor->email;
        $phone = $data['phone'] ?? $visitor->phone;
        $inquiry = isset($data['inquiry']) ? trim((string) $data['inquiry']) : null;

        $lead = Lead::where('visitor_id', $visitor->id)->first();

        if (! $lead) {
            $hasSomething = $name || $email || $phone || ($inquiry !== null && $inquiry !== '') || $conversation;
            if (! $hasSomething) {
                return null;
            }

            $property = $visitor->relationLoaded('property') ? $visitor->property : Property::find($visitor->property_id);
            try {
                $lead = Lead::create([
                    'tenant_id' => $property->tenant_id,
                    'property_id' => $visitor->property_id,
                    'visitor_id' => $visitor->id,
                    'source' => 'chat',
                    'status' => 'new',
                    'last_interaction_at' => now(),
                ]);
            } catch (QueryException $e) {
                // Another request created this visitor's lead at the same moment (unique visitor_id).
                $lead = Lead::where('visitor_id', $visitor->id)->first();
                if (! $lead) {
                    throw $e;
                }
            }
        }

        // Fill only what is still empty; never overwrite what staff (or earlier capture) stored.
        $fill = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'website' => $data['website'] ?? self::detectWebsite($inquiry),
            'interested_service' => $data['interested_service'] ?? self::detectService($inquiry),
            'inquiry_summary' => $inquiry !== null && $inquiry !== '' ? mb_substr($inquiry, 0, 1000) : null,
        ];
        foreach ($fill as $field => $value) {
            if (($lead->{$field} === null || $lead->{$field} === '') && $value !== null && $value !== '') {
                $lead->{$field} = $value;
            }
        }
        $lead->last_interaction_at = now();
        if ($lead->isDirty()) {
            $lead->save();
        }

        if ($conversation) {
            DB::table('lead_conversation')->insertOrIgnore([
                'lead_id' => $lead->id,
                'conversation_id' => $conversation->id,
                'created_at' => now(),
            ]);
        }

        return $lead;
    }

    public static function detectService(?string $text): ?string
    {
        if (! $text) {
            return null;
        }
        $haystack = ' ' . mb_strtolower($text) . ' ';
        foreach (self::SERVICE_KEYWORDS as $service => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    return $service;
                }
            }
        }

        return null;
    }

    /** First URL or bare domain the visitor mentioned (e.g. "example.in"). */
    public static function detectWebsite(?string $text): ?string
    {
        if (! $text) {
            return null;
        }
        if (preg_match('~\bhttps?://[^\s<>"\']{3,200}~i', $text, $m)) {
            return rtrim($m[0], '.,;:!?)');
        }
        if (preg_match('~\b((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|in|net|org|co\.in|io|co|biz|info|online|store|shop|tech|site|xyz|us|uk|co\.uk))\b~i', $text, $m)) {
            // Ignore e-mail addresses: the match must not be preceded by "@".
            $pos = stripos($text, $m[1]);
            if ($pos === 0 || $text[$pos - 1] !== '@') {
                return strtolower($m[1]);
            }
        }

        return null;
    }
}
