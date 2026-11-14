<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Message;
use App\Models\Property;
use App\Services\BusinessHours;
use App\Services\ConversationService;
use App\Services\Knowledge\KnowledgeSearch;
use App\Services\TicketService;
use App\Support\SearchText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hybrid support engine (Phase 2 §6):
 *   human request -> restricted action -> FAQ (free) -> approved knowledge + LLM -> clarify once -> escalate
 *
 * Guarantees:
 *  - never replies while a human handles the conversation (re-checked under a row lock before saving)
 *  - never claims a ticket exists before it was committed
 *  - never performs or confirms payments, refunds, renewals, DNS changes, suspensions or credential resets
 *  - answers only from approved FAQs/knowledge (cited); otherwise asks once, then escalates
 *  - customer text is treated as data, never as instructions
 */
class SupportBot
{
    private const HUMAN_PATTERNS = [
        '/\b(talk|speak|chat|connect)\s+(me\s+)?(to|with)\s+(an?\s+|the\s+|your\s+)?(agent|human|person|someone|representative|executive|staff|support|team)\b/i',
        '/\b(want|need|get)\s+(to\s+(talk|speak)\s+to\s+)?(an?\s+|the\s+)?(real\s+)?(human|person|agent|representative|executive)\b/i',
        '/\b(human|person|agent)\s+please\b/i', '/\b(transfer|escalate)\s+(me|this|to)\b/i',
        '/\b(human|live)\s+(agent|support|person|chat)\b/i', '/\breal\s+person\b/i', '/\bcustomer\s+care\b/i',
        '/\b(agent|insaan|insan)\s+(se\s+)?baat\b/i', '/\bbaat\s+kar(ni|na|wa)\b/i', '/\bcall\s+me\b/i',
        '/किसी\s+(व्यक्ति|इंसान)\s+से\s+बात/u', '/एजेंट\s+से\s+बात/u',
    ];

    /** Requests for actions the bot must never perform: [pattern, category, label]. */
    private const RESTRICTED = [
        ['/\b(refund|money\s+back|paisa\s+wapas|chargeback)\b|रिफंड/iu', 'billing', 'refunds'],
        ['/\b(mark|update)\b.*\b(paid|payment)\b|\bpayment\b.*\b(not\s+(updated|reflect|showing)|deducted|failed)\b|\b(paid|payment\s+done)\b.*\b(invoice|unpaid|pending)\b/i', 'billing', 'payments'],
        ['/\b(renew|extend)\b.*\b(domain|hosting|service|plan|my)\b|\b(domain|hosting)\b.*\brenew\b/i', 'domain', 'renewals'],
        ['/\b(change|update|point|set)\b.*\b(dns|nameserver|name\s*server|a\s+record|mx|cname)\b/i', 'domain', 'DNS changes'],
        ['/\b(suspend|unsuspend|cancel|terminate|delete)\b.*\b(account|hosting|service|domain|server|plan)\b/i', 'account', 'account changes'],
        ['/\b(reset|change)\b.*\b(cpanel|hosting|server|root|ftp|email|client\s*area|whm)\b.*\b(password|credential|login)\b|\b(cpanel|root|whm)\s+password\b.*\b(reset|change|bhul|forgot)/i', 'technical', 'credential resets'],
    ];

    private const T = [
        'human_live' => [
            'en' => "Please wait, I am checking your request...\n\nWe are transferring your chat to our senior department. Please wait for 1 minute.",
            'hi' => "कृपया प्रतीक्षा करें, मैं आपके अनुरोध की जांच कर रहा हूँ...\n\nहम आपकी चैट को हमारे सीनियर डिपार्टमेंट में ट्रांसफर कर रहे हैं। कृपया 1 मिनट प्रतीक्षा करें।",
            'hi-Latn' => "Please wait, main aapki request check kar raha hoon...\n\nHum aapki chat senior department ko transfer kar rahe hain. Please 1 minute wait karein.",
        ],
        'ticket_created' => [
            'en' => "Your request has been registered as ticket {ref}. Our team will reply within 1 to 2 hours. You can sign in anytime with your email & PIN to track updates.",
            'hi' => 'आपका अनुरोध टिकट {ref} के रूप में दर्ज कर लिया गया है। हमारी टीम 1 से 2 घंटे के भीतर जवाब देगी। आप अपडेट देखने के लिए कभी भी अपने ईमेल और पिन से साइन इन कर सकते हैं।',
            'hi-Latn' => 'Aapki request ticket {ref} ke roop mein register ho gayi hai. Hamari team 1 se 2 ghante mein reply karegi. Aap updates dekhne ke liye kisi bhi time email aur PIN se sign in kar sakte hain.',
        ],
        'ticket_existing' => [
            'en' => 'Your request is already with our team as ticket {ref}. They will reply in this chat.',
            'hi' => 'आपका अनुरोध पहले से टिकट {ref} के रूप में हमारी टीम के पास है। वे इसी चैट में जवाब देंगे।',
            'hi-Latn' => 'Aapki request pehle se ticket {ref} ke roop mein team ke paas hai. Woh isi chat mein reply karenge.',
        ],
        'ticket_failed' => [
            'en' => "Sorry — could not create a ticket right now. Please leave your message and email here.",
            'hi' => 'क्षमा करें — अभी टिकट नहीं बन पाया। कृपया अपना मैसेज और ईमेल यहाँ छोड़ दें।',
            'hi-Latn' => 'Sorry — abhi ticket create nahi ho paaya. Please apna message aur email yahan chhod dein.',
        ],
        'restricted' => [
            'en' => "I can't handle {action} in chat — our senior department will assist you.",
            'hi' => 'मैं चैट में {action} नहीं कर सकता — हमारा सीनियर डिपार्टमेंट आपकी सहायता करेगा।',
            'hi-Latn' => 'Main chat mein {action} handle nahi kar sakta — hamara senior department aapki help karega.',
        ],
        'clarify' => [
            'en' => 'Could you share a bit more detail — for example the domain or service involved and what you see (error message, step where it fails)?',
            'hi' => 'कृपया थोड़ा और विवरण दें — जैसे कौन सा डोमेन या सर्विस है और आपको क्या दिख रहा है (एरर मैसेज, किस स्टेप पर दिक्कत आती है)?',
            'hi-Latn' => 'Thoda aur detail share kariye — jaise kaunsa domain ya service hai aur aapko kya dikh raha hai (error message, kis step par problem aati hai)?',
        ],
        'after_hours' => [
            'en' => 'Our team works {hours} IST every day; they will reply from {next}.',
            'hi' => 'हमारी टीम हर दिन {hours} IST काम करती है; वे {next} से जवाब देंगे।',
            'hi-Latn' => 'Hamari team roz {hours} IST kaam karti hai; woh {next} se reply karenge.',
        ],
        'faq_footer' => [
            'en' => '',
            'hi' => '',
            'hi-Latn' => '',
        ],
    ];

    /**
     * Handle a new customer message. Returns the bot Message created synchronously (or null when
     * the AI step was deferred / a human handles the chat).
     */
    public static function handle(Conversation $conversation, Message $message): ?Message
    {
        $conversation->refresh()->loadMissing('property.tenant');
        if ($conversation->handler === 'human') {
            return null;
        }
        $text = (string) $message->content;
        $lang = self::updateLanguage($conversation, $text);

        if (self::wantsHuman($text)) {
            return self::requestHuman($conversation, $message, $lang, 'customer_request');
        }

        if ($restricted = self::restrictedAction($text)) {
            return self::escalate($conversation, $message, $lang, 'ai_escalation', self::t('restricted', $lang, ['action' => $restricted[1]]) . ' ', $restricted[0]);
        }

        if ($faq = self::faqMatch($conversation->property, $text)) {
            return self::botReply($conversation, $faq->answer . self::t('faq_footer', $lang), ['source' => 'faq', 'faq_id' => $faq->id]);
        }

        $settings = AiSetting::forTenant($conversation->property->tenant_id);
        $block = AiBudgetService::blockReason($settings, $conversation->visitor_id);
        if ($block === null) {
            $conversation->forceFill(['ai_pending_since' => now()])->save();
            $convId = $conversation->id;
            $msgId = $message->id;
            if (app()->runningInConsole()) {
                app(AiResponder::class)->run($convId, $msgId);
            } else {
                dispatch(fn () => app(AiResponder::class)->run($convId, $msgId))->afterResponse();
            }

            return null;
        }

        // No AI available (disabled, budget reached, visitor limit): FAQ-only + human fallback.
        if (count(SearchText::terms($text)) <= 3 && $conversation->clarifications_asked < 1 && ! self::isGreetingOnly($text)) {
            $conversation->increment('clarifications_asked');

            return self::botReply($conversation, self::t('clarify', $lang), ['source' => 'clarify']);
        }
        if (self::isGreetingOnly($text)) {
            return null; // the opening greeting already answered
        }

        return self::requestHuman($conversation, $message, $lang, 'ai_escalation');
    }

    /** Hand over to staff: live when someone is online, otherwise a ticket (idempotent per message). */
    public static function requestHuman(Conversation $conversation, Message $message, string $lang, string $reason, string $prefix = ''): ?Message
    {
        try {
            $result = ConversationService::requestHuman($conversation, 'msg-' . $message->id, $reason);
        } catch (\Throwable $e) {
            Log::error('Escalation failed: ' . $e->getMessage(), ['conversation' => $conversation->id]);

            return self::botReply($conversation, $prefix . self::t('ticket_failed', $lang), ['source' => 'escalation_failed'], true);
        }

        if ($result['mode'] === 'live') {
            return self::botReply($conversation, $prefix . self::t('human_live', $lang), ['source' => 'handover'], true);
        }
        $key = $result['created'] ? 'ticket_created' : 'ticket_existing';

        return self::botReply($conversation, $prefix . self::t($key, $lang, ['ref' => $result['ticket']->reference]) . self::hoursNote($conversation->property, $lang), ['source' => 'ticket', 'ticket_id' => $result['ticket']->id], true);
    }

    /** Escalate to senior department with a 1-minute live wait window before announcing the ticket. */
    public static function transferSeniorDepartment(Conversation $conversation, Message $message, string $lang, string $source, string $prefix = '', string $category = 'general', ?array $summary = null): ?Message
    {
        try {
            [$ticket, $created] = TicketService::createOrReuse($conversation, array_filter([
                'source' => $source,
                'category' => $category,
                'summary' => $summary,
                'idempotency_key' => 'msg-' . $message->id,
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::error('Ticket creation failed: ' . $e->getMessage(), ['conversation' => $conversation->id]);

            return self::botReply($conversation, $prefix . self::t('ticket_failed', $lang), ['source' => 'escalation_failed'], true);
        }

        $conversation->update([
            'handler' => 'human',
            'handover_at' => now(),
            'ai_pending_since' => null,
        ]);
        \App\Services\Notifier::chatStarted($conversation, $message);

        return self::botReply($conversation, $prefix . self::t('human_live', $lang), ['source' => 'handover', 'waiting_senior' => true, 'ticket_id' => $ticket->id], true);
    }

    /** Escalate with a ticket (summary optional, e.g. from the LLM). */
    public static function escalate(Conversation $conversation, Message $message, string $lang, string $source, string $prefix = '', string $category = 'general', ?array $summary = null): ?Message
    {
        try {
            [$ticket, $created] = TicketService::createOrReuse($conversation, array_filter([
                'source' => $source,
                'category' => $category,
                'summary' => $summary,
                'idempotency_key' => 'msg-' . $message->id,
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::error('Ticket creation failed: ' . $e->getMessage(), ['conversation' => $conversation->id]);

            return self::botReply($conversation, $prefix . self::t('ticket_failed', $lang), ['source' => 'escalation_failed'], true);
        }

        return self::botReply($conversation, $prefix . self::t($created ? 'ticket_created' : 'ticket_existing', $lang, ['ref' => $ticket->reference]) . self::hoursNote($conversation->property, $lang), ['source' => 'ticket', 'ticket_id' => $ticket->id], true);
    }

    /**
     * Save a bot message only if the AI still owns the conversation (row lock). $afterHandover
     * allows the single escalation notice that is sent at the moment of handing over.
     */
    public static function botReply(Conversation $conversation, string $content, array $meta = [], bool $afterHandover = false): ?Message
    {
        return DB::transaction(function () use ($conversation, $content, $meta, $afterHandover) {
            $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->first();
            if (! $locked || ($locked->handler === 'human' && ! $afterHandover)) {
                return null;
            }

            return Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'bot',
                'message_type' => 'text',
                'content' => $content,
                'is_automated' => in_array($meta['source'] ?? '', ['handover', 'ticket', 'escalation_failed'], true),
                'metadata' => $meta,
                'is_read' => true,
            ]);
        });
    }

    public static function wantsHuman(string $text): bool
    {
        foreach (self::HUMAN_PATTERNS as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }

        return in_array(mb_strtolower(trim($text, " .!?\t\n")), ['agent', 'human', 'talk to agent', 'live agent', 'support'], true);
    }

    /** @return array{0: string, 1: string}|null [ticket category, action label] for action REQUESTS (not how-to questions) */
    public static function restrictedAction(string $text): ?array
    {
        $t = mb_strtolower($text);
        if (preg_match('/\b(how|kaise|kya|steps?|guide|process|kitna|price|cost)\b|\?\s*$|कैसे/u', $t) && ! preg_match('/\b(refund)\b/', $t)) {
            return null; // informational question: answer from knowledge instead
        }
        foreach (self::RESTRICTED as [$pattern, $category, $label]) {
            if (preg_match($pattern, $t)) {
                return [$category, $label];
            }
        }

        return null;
    }

    public static function t(string $key, string $lang, array $vars = []): string
    {
        $s = self::T[$key][$lang] ?? self::T[$key]['en'];
        foreach ($vars as $k => $v) {
            $s = str_replace('{' . $k . '}', (string) $v, $s);
        }

        return $s;
    }

    public static function hoursNote(?Property $property, string $lang): string
    {
        if (BusinessHours::isOpen($property)) {
            return '';
        }
        $cfg = BusinessHours::config($property);
        $next = BusinessHours::nextOpening($property);

        return ' ' . self::t('after_hours', $lang, [
            'hours' => "{$cfg['start']}–{$cfg['end']}",
            'next' => $next->format('D, d M h:i A'),
        ]);
    }

    private static function updateLanguage(Conversation $conversation, string $text): string
    {
        $requested = SearchText::requestedLanguage($text);
        if ($requested) {
            $conversation->forceFill(['language' => $requested])->save();

            return $requested;
        }
        if (! $conversation->language) {
            $conversation->forceFill(['language' => SearchText::language($text)])->save();
        }

        return $conversation->language ?? 'en';
    }

    private static function isGreetingOnly(string $text): bool
    {
        return (bool) preg_match('/^\s*(hi+|hello+|hey+|namaste|namaskar|good\s+(morning|afternoon|evening)|नमस्ते|ok|okay|thanks?|thank\s+you|dhanyavaad)[\s!.,]*$/iu', $text);
    }

    /** Deterministic FAQ match (no LLM cost). Requires a strong question overlap. */
    public static function faqMatch(?Property $property, string $text): ?Faq
    {
        if (! $property) {
            return null;
        }
        $faqs = Cache::remember("prop_faqs_all_{$property->id}", 60, fn () => $property->faqs()->where('is_active', true)->get());
        $q = SearchText::terms($text);
        if (count($q) === 0 || $faqs->isEmpty()) {
            return null;
        }
        $brand = SearchText::terms($property->site_name);
        $q = array_values(array_diff($q, $brand));
        if (! $q) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($faqs as $faq) {
            $ft = array_values(array_diff(SearchText::terms($faq->question), $brand));
            if (! $ft) {
                continue;
            }
            $common = count(array_intersect($q, $ft));
            // both directions must overlap: most of the question's terms AND most of the FAQ's terms
            $score = min($common / count($q), $common / count($ft));
            if ($common >= 1 && $score > $bestScore) {
                $bestScore = $score;
                $best = $faq;
            }
        }

        return $bestScore >= 0.6 ? $best : null;
    }
}
