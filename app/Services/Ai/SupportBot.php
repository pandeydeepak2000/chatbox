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
        'identity' => [
            'en' => "I'm {brand}'s automated support assistant. A member of our team can join this chat when needed. Would you like me to bring the team in?",
            'hi' => 'मैं {brand} का ऑटोमेटेड सपोर्ट असिस्टेंट हूँ। ज़रूरत पड़ने पर हमारी टीम का कोई सदस्य इस चैट में जुड़ सकता है। क्या आप चाहेंगे कि मैं टीम को बुलाऊँ?',
            'hi-Latn' => 'Main {brand} ka automated support assistant hoon. Zaroorat padne par hamari team ka koi member is chat mein join kar sakta hai. Kya aap chahenge ki main team ko bulaun?',
        ],
        'escalated' => [
            'en' => "I'm sending your request to our senior team for review. Please stay in this chat.",
            'hi' => 'मैं आपकी रिक्वेस्ट सीनियर टीम के रिव्यू के लिए भेज रहा हूँ। कृपया इसी चैट में बने रहिए।',
            'hi-Latn' => 'Main aapki request senior team ke review ke liye bhej raha hoon. Aap isi chat mein bane rahiye.',
        ],
        'assigned' => [
            'en' => 'Your request has been assigned to our support team.',
            'hi' => 'आपका अनुरोध हमारी सपोर्ट टीम को असाइन कर दिया गया है।',
            'hi-Latn' => 'Aapki request support team ko assign kar di gayi hai.',
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
            'en' => "I can't process {action} directly in chat — our support team will assist you.",
            'hi' => "मैं चैट में सीधे {action} प्रोसेस नहीं कर सकता — हमारी सपोर्ट टीम आपकी सहायता करेगी।",
            'hi-Latn' => "Main chat mein directly {action} process nahi kar sakta — hamari support team aapki help karegi.",
        ],
        'clarify' => [
            'en' => 'Could you share a bit more detail — for example the domain or service involved and what you see (error message, step where it fails)?',
            'hi' => 'कृपया थोड़ा और विवरण दें — जैसे कौन सा डोमेन या सर्विस है और आपको क्या दिख रहा है (एरर मैसेज, किस स्टेप पर दिक्कत आती है)?',
            'hi-Latn' => 'Thoda aur detail share kariye — jaise kaunsa domain ya service hai aur aapko kya dikh raha hai (error message, kis step par problem aati hai)?',
        ],
        'clarify_sales' => [
            'en' => 'Happy to help. Which service or plan would you like to know about?',
            'hi' => 'ज़रूर, मदद करूँगा। आप किस सर्विस या प्लान के बारे में जानना चाहते हैं?',
            'hi-Latn' => 'Zaroor, main help karunga. Aap kis service ya plan ke baare mein jaanna chahte hain?',
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

        // "Are you a bot / a real person?" is answered truthfully and never turned into a fake transfer.
        if (self::asksIfAutomated($text)) {
            return self::botReply($conversation, self::t('identity', $lang, ['brand' => self::brand($conversation->property)]), ['source' => 'identity']);
        }

        if (self::wantsHuman($text)) {
            return self::transferSupportTeam($conversation, $message, $lang, 'customer_request');
        }

        if ($restricted = self::restrictedAction($text)) {
            $prefix = self::t('restricted', $lang, ['action' => $restricted[1]]);
            return self::transferSupportTeam($conversation, $message, $lang, 'restricted_action', $prefix, $restricted[0]);
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

            $isProblem = (bool) preg_match('/\b(error|failed?|not\s+work|issue|problem|dikk?at|kharab|down|bug|403|500|slow|cannot|cant|nahi\s+chal|kam\s+nahi)\b/i', $text);
            $clarifyKey = $isProblem ? 'clarify' : 'clarify_sales';

            return self::botReply($conversation, self::t($clarifyKey, $lang), ['source' => 'clarify']);
        }
        if (self::isGreetingOnly($text)) {
            return null; // the opening greeting already answered
        }

        return self::transferSeniorDepartment($conversation, $message, $lang, 'ai_escalation');
    }

    /** Hand over to staff: routes through transferSupportTeam for the 1-minute live wait window. */
    public static function requestHuman(Conversation $conversation, Message $message, string $lang, string $reason, string $prefix = ''): ?Message
    {
        return self::transferSupportTeam($conversation, $message, $lang, $reason, $prefix);
    }

    /** Escalate to support team with a 1-minute live wait window before announcing the ticket. */
    public static function transferSupportTeam(Conversation $conversation, Message $message, string $lang, string $source, string $prefix = '', string $category = 'general', ?array $summary = null): ?Message
    {
        // A ticket exists at once when nobody can take the chat live (team offline / outside hours) or when the
        // request is for staff-only work (billing, domain, account...). When a team member is online the chat
        // waits in the live queue first; a ticket is added later if nobody joins (see WidgetChatController::syncMessages).
        $ticket = null;
        $needsTicketNow = ! ConversationService::teamAvailable($conversation->property) || $category !== 'general' || $summary !== null;
        if ($needsTicketNow) {
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
        }

        // Automation stops now; the state machine records the escalation. Nothing is announced twice.
        $newlyQueued = ConversationService::queueForTeam($conversation, $source);
        $conversation->forceFill(['handler' => 'human', 'handover_at' => $conversation->handover_at ?? now(), 'ai_pending_since' => null])->save();
        if ($newlyQueued) {
            \App\Services\Notifier::chatStarted($conversation, $message, $ticket);   // one Telegram alert (deduped)
            if ($source === 'customer_request') {
                \App\Services\Notifier::humanRequested($conversation);                   // in-app only: Telegram window already used
            }
        }

        return self::escalationNotice($conversation, $ticket, $lang, $prefix);
    }

    /**
     * The one place that words an escalation for the customer, from REAL state:
     *  - team online in working hours -> "sent for review", no promise that anyone is free;
     *  - team offline / outside hours -> the ticket reference and the working hours (no pretending).
     * A transfer is never announced as completed: that only happens when an agent joins.
     */
    public static function escalationNotice(Conversation $conversation, ?\App\Models\Ticket $ticket, string $lang, string $prefix = ''): ?Message
    {
        $available = ConversationService::teamAvailable($conversation->property);
        if ($available) {
            $body = self::t('escalated', $lang);
            $meta = ['source' => 'handover', 'waiting_senior' => true, 'ticket_id' => $ticket?->id];
        } else {
            $body = $ticket
                ? self::t('ticket_created', $lang, ['ref' => $ticket->reference]) . self::hoursNote($conversation->property, $lang)
                : self::t('escalated', $lang) . self::hoursNote($conversation->property, $lang);
            $meta = ['source' => 'ticket', 'ticket_id' => $ticket?->id];
        }
        $reply = $prefix !== '' ? rtrim($prefix) . "\n\n" . $body : $body;

        return self::botReply($conversation, $reply, $meta, true);
    }
    public static function transferSeniorDepartment(Conversation $conversation, Message $message, string $lang, string $source, string $prefix = '', string $category = 'general', ?array $summary = null): ?Message
    {
        return self::transferSupportTeam($conversation, $message, $lang, $source, $prefix, $category, $summary);
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

    /** Customer asks whether they are talking to a bot / AI / person (not asking to be connected). */
    public static function asksIfAutomated(string $text): bool
    {
        if (preg_match('/\b(talk|speak|connect|transfer|baat\s+kar(a|ao|va)|want|need|chahiye|chahta|chahti)\b/iu', $text)) {
            return false; // an actual request for a person goes to the escalation flow
        }

        return (bool) preg_match('/\b(are|r)\s+(you|u)\s+(a\s+|an\s+)?(real\s+)?(bot|robot|chat\s*bot|ai|automated|machine|human|person|real|live|agent)\b'
            . '|\b(is\s+this|this\s+is)\s+(a\s+|an\s+)?(bot|robot|ai|automated|human|real\s+person|auto\s*reply)\b'
            . '|\b(kya|kyaa)\s+(aap|ap|tum|ye|yeh|this)\b.{0,25}\b(bot|robot|ai|human|insaan|insan|real|automatic|auto)\b'
            . '|\b(aap|ap|tum|ye|yeh)\s+(bot|robot|insaan|insan|human)\s*(ho|hai|hain|h)?\b'
            . '|\b(bot|robot)\s+(ho|hai|hain|h)\b/iu', $text)
            || (bool) preg_match('/(क्या\s+(आप|यह|ये).{0,20}(बॉट|रोबोट|इंसान|एआई|ऑटोमैटिक))|((बॉट|रोबोट)\s+(हो|है|हैं))/u', $text);
    }

    /** Customer-facing company name: configured brand, else the website name. */
    public static function brand(?Property $property): string
    {
        $name = trim((string) ($property?->widget_config['brand_name'] ?? $property?->site_name ?? ''));

        return $name !== '' ? $name : 'our';
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

    /** [customer asks about (regex), FAQ question/category is about (regex), score boost]. */
    private const INTENT_BOOSTS = [
        ['/\b(plan|plans|price|pricing|cost|kitna|kimat)\b/i', '/\b(plan|plans|price|pricing|cost|package)\b/i', 0.6],
        ['/\b(editor|login|canvas|access|open|khol)\b/i', '/\b(editor|login|canvas|access|sign\s*in)\b/i', 0.8],
        ['/\b(webhook|telegram|whatsapp)\b/i', '/\b(webhook|telegram|whatsapp)\b/i', 1.2],
        ['/\b(upgrade|scale|cpu|vcpu|ram|resource)\b/i', '/\b(upgrade|scale|cpu|vcpu|ram|resource)\b/i', 1.0],
    ];

    /** True when any 3+ character query term appears literally in the haystack (question/category text). */
    private static function literalHit(array $terms, string $haystack): bool
    {
        foreach ($terms as $t) {
            if (mb_strlen($t) >= 3 && str_contains($haystack, $t)) {
                return true;
            }
        }

        return false;
    }

    /** Deterministic FAQ match (no LLM cost). Robust scoring across questions, categories, and keywords. */
    public static function faqMatch(?Property $property, string $text): ?Faq
    {
        if (! $property) {
            return null;
        }

        $faqs = Cache::remember("prop_faqs_all_{$property->id}", 60, fn () => $property->faqs()->where('is_active', true)->get());
        if ($faqs->isEmpty()) {
            return null;
        }

        $cleanText = mb_strtolower(trim($text));
        $q = SearchText::terms($cleanText);
        $brand = SearchText::terms($property->site_name ?? '');
        $q = array_values(array_diff($q, $brand));
        if (empty($q)) {
            return null;
        }

        // Distinctive keywords that carry high domain and product affinity
        $distinctiveKeywords = [
            'n8n' => ['n8n', 'automation', 'vps'],
            'hermes' => ['hermes', 'agent', 'vps'],
            'herm' => ['hermes', 'agent', 'vps'],
            'nameserver' => ['nameserver', 'ns1', 'ns2', 'point'],
            'dns' => ['dns', 'record', 'cname', 'txt'],
            'cpanel' => ['cpanel', 'control panel', 'login'],
            'wordpress' => ['wordpress', 'wp'],
            'ssl' => ['ssl', 'https', 'certificate'],
            'webmail' => ['email', 'webmail', 'mailbox'],
            'backup' => ['backup', 'restore', 'jetbackup'],
            'refund' => ['refund', 'money back'],
            'wallet' => ['wallet', 'add money', 'balance'],
            'inode' => ['inode', 'file count', 'usage'],
            'whois' => ['whois', 'privacy'],
            'migration' => ['migration', 'migrate', 'transfer site'],
            'webhook' => ['webhook', 'telegram', 'whatsapp'],
            '2fa' => ['2fa', 'two-factor', 'authentication'],
        ];

        $best = null;
        $bestScore = 0.0;

        foreach ($faqs as $faq) {
            $fQuestionTerms = array_values(array_diff(SearchText::terms($faq->question), $brand));
            $fCategoryTerms = array_values(array_diff(SearchText::terms($faq->category ?? ''), $brand));

            // 1. Direct Question Match: fraction of query terms present in question
            $qInQuestion = count(array_intersect($q, $fQuestionTerms));
            $questionRatio = count($q) > 0 ? ($qInQuestion / count($q)) : 0.0;

            // 2. Direct Category Match: fraction of query terms in category
            $qInCategory = count(array_intersect($q, $fCategoryTerms));
            $categoryRatio = count($q) > 0 ? ($qInCategory / count($q)) : 0.0;

            // 3. Substring / Literal matches in question, category, answer
            $rawQuestion = mb_strtolower($faq->question);
            $rawAnswer = mb_strtolower($faq->answer);
            $rawCategory = mb_strtolower($faq->category ?? '');

            $substringHits = 0.0;
            foreach ($q as $term) {
                if (mb_strlen($term) >= 3) {
                    if (str_contains($rawQuestion, $term)) {
                        $substringHits += 1.0;
                    } elseif (str_contains($rawCategory, $term)) {
                        $substringHits += 0.8;
                    } elseif (str_contains($rawAnswer, $term)) {
                        $substringHits += 0.3;
                    }
                }
            }
            $subRatio = count($q) > 0 ? ($substringHits / count($q)) : 0.0;

            // Accuracy guard: a match must share at least one real term with the FAQ question or category.
            // A hit found only inside the answer body is too weak to answer a customer with.
            if ($qInQuestion === 0 && $qInCategory === 0 && ! self::literalHit($q, $rawQuestion . ' ' . $rawCategory)) {
                continue;
            }

            $score = ($questionRatio * 1.5) + ($categoryRatio * 0.8) + ($subRatio * 0.7);

            // Distinctive Keyword Boost
            foreach ($distinctiveKeywords as $kw => $aliases) {
                if (in_array($kw, $q, true) || str_contains($cleanText, $kw)) {
                    if (str_contains($rawQuestion, $kw) || str_contains($rawCategory, $kw)) {
                        $score += 1.0;
                    }
                }
            }

            // Intent boosts are data-driven: they favour FAQs whose OWN question/category talks about the
            // same topic. No FAQ ids/positions are hard-coded, so one company's FAQ list can never skew another's.
            $faqHead = $rawQuestion . ' ' . $rawCategory;
            foreach (self::INTENT_BOOSTS as [$askPattern, $faqPattern, $boost]) {
                if (preg_match($askPattern, $cleanText) && preg_match($faqPattern, $faqHead)) {
                    $score += $boost;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $faq;
            }
        }

        return ($bestScore >= 0.65) ? $best : null;
    }
}
