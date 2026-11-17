<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Faq;
use App\Models\KnowledgeDocument;
use App\Models\Property;
use App\Support\SearchText;
use Illuminate\Support\Facades\Cache;

/**
 * "Is this about THIS company's services?" ChatBox's assistant is a support desk, not a general chatbot.
 *
 * The deterministic gate costs nothing (no AI call) and is deliberately conservative: a message is called off-topic
 * only when it matches an unmistakably unrelated subject (sport, politics, entertainment, weather, jokes, trivia...)
 * AND shows no service signal AND shares no vocabulary with the company's own scope (the services the administrator
 * listed, the titles of its approved documents and its FAQ questions). Anything unclear goes on to the normal flow
 * (FAQ -> grounded AI -> human escalation), so a real customer is never turned away by a keyword.
 */
final class ServiceScope
{
    /** Words that mark a support/service conversation for ANY hosting-type company (EN + Roman Hindi). */
    private const SERVICE_SIGNALS = '/\b(domain|domains|hosting|host|server|servers|vps|dns|nameserver|name\s*server|ssl|https|cpanel|whm|plesk|email|e-?mail|mail|webmail|mailbox|website|site|wordpress|wp|plugin|theme|plan|plans|pricing|price|prices|cost|billing|bill|invoice|payment|paid|refund|renew|renewal|order|account|login|log\s*in|password|username|ticket|support|backup|restore|database|mysql|php|ftp|sftp|ssh|ip\s*address|uptime|downtime|bandwidth|storage|disk|inode|migrate|migration|transfer|cloud|n8n|vpn|firewall|malware|virus|hacked|cdn|cache|redirect|subdomain|whois|registrar|license|upgrade|downgrade|cancel|suspend|suspended|activation|activate|error|500|502|503|404|403|slow|down|crash|nahi\s+chal|nahi\s+khul|khul\s+nahi|kaam\s+nahi|dikkat|samasya|problem|issue|bug)\b/iu';

    /** Unmistakably unrelated subjects. Matched only when no service signal is present. */
    private const OFF_TOPIC = [
        // sport
        '/\b(cricket|ipl|t20|football|fifa|world\s*cup|tennis|nba|nfl|kabaddi|hockey|badminton|olympics?|match\s+(score|result|today|tomorrow)|who\s+(will|would)\s+win|kaun\s+jeet)/iu',
        '/(क्रिकेट|मैच|फुटबॉल)/u',
        // politics
        '/\b(election|elections|prime\s+minister|chief\s+minister|politic\w*|modi|trump|biden|putin|vote\s+for|bjp|congress\s+party|chunav)\b/iu',
        '/(चुनाव|प्रधानमंत्री|राजनीति)/u',
        // entertainment
        '/\b(movie|movies|film|films|song|songs|lyrics|bollywood|hollywood|actor|actress|netflix|web\s*series|celebrity|singer|gaana|gana)\b/iu',
        '/(गाना|फिल्म|अभिनेता)/u',
        // weather
        '/\b(weather|will\s+it\s+rain|rain\s+today|temperature\s+(today|tomorrow|outside)|barish|mausam)\b/iu',
        '/(मौसम|बारिश)/u',
        // fun / chit-chat requests
        '/\b(tell\s+me\s+a\s+joke|joke\s+(sunao|bolo)|riddle|poem|shayari|sing\s+a\s+song|kahani\s+sunao|tell\s+me\s+a\s+story|write\s+(me\s+)?(a\s+)?(poem|story|essay))\b/iu',
        // food, health, astrology
        '/\b(recipe|how\s+to\s+cook|biryani|pizza|diet\s+plan|weight\s+loss|symptoms\s+of|medicine\s+for|horoscope|rashifal|zodiac|astrology)\b/iu',
        // markets and prices of unrelated things
        '/\b(bitcoin|crypto\s+price|stock\s+tips?|share\s+market|gold\s+rate|petrol\s+price|nifty|sensex)\b/iu',
        // general knowledge / homework
        '/\b(capital\s+of|who\s+(is|was)\s+the\s+(president|ceo|founder|prime\s+minister)\s+of|largest\s+(country|ocean|city)|who\s+invented|how\s+old\s+is|meaning\s+of\s+life|solve\s+(this\s+)?(equation|sum)|math\s+homework)\b/iu',
    ];

    /** @return bool true when the message clearly has nothing to do with the company's services. */
    public static function isOffTopic(string $text, ?Property $property): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match(self::SERVICE_SIGNALS, $text)) {
            return false;
        }
        if (self::sharesCompanyVocabulary($text, $property)) {
            return false;
        }
        foreach (self::OFF_TOPIC as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /** True when the message mentions a service signal or the company's own vocabulary (used to veto an AI "out of scope"). */
    public static function looksServiceRelated(string $text, ?Property $property): bool
    {
        return (bool) preg_match(self::SERVICE_SIGNALS, $text) || self::sharesCompanyVocabulary($text, $property);
    }

    private static function sharesCompanyVocabulary(string $text, ?Property $property): bool
    {
        if (! $property) {
            return false;
        }
        $vocab = self::vocabulary($property);
        if (! $vocab) {
            return false;
        }
        foreach (SearchText::terms($text) as $t) {
            if (mb_strlen($t) >= 4 && isset($vocab[$t])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The company's own words: administrator's service list, approved document titles, FAQ questions of this website.
     * Tenant-scoped; cached briefly.
     *
     * @return array<string,true>
     */
    public static function vocabulary(Property $property): array
    {
        return Cache::remember("ai_scope_vocab_{$property->tenant_id}_{$property->id}", 300, function () use ($property) {
            $parts = [(string) AiSetting::where('tenant_id', $property->tenant_id)->value('service_scope')];
            $parts = array_merge(
                $parts,
                KnowledgeDocument::where('tenant_id', $property->tenant_id)->where('status', 'approved')
                    ->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $property->id))->limit(100)->pluck('title')->all(),
                Faq::where('property_id', $property->id)->where('is_active', true)->limit(200)->pluck('question')->all()
            );
            $set = [];
            foreach (SearchText::terms(implode(' ', $parts)) as $t) {
                $set[$t] = true;
                if (count($set) >= 600) {
                    break;
                }
            }

            return $set;
        });
    }

    /** The company's service list as prompt text (administrator's own words, plus the titles of approved documents). */
    public static function promptSummary(Property $property): string
    {
        $own = trim((string) AiSetting::where('tenant_id', $property->tenant_id)->value('service_scope'));
        $titles = KnowledgeDocument::where('tenant_id', $property->tenant_id)->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $property->id))->orderBy('title')->limit(25)->pluck('title')->all();
        $lines = [];
        if ($own !== '') {
            $lines[] = "Services this company offers (written by its administrator):\n" . mb_substr($own, 0, 1500);
        }
        if ($titles) {
            $lines[] = 'Topics covered by its approved documents: ' . implode('; ', array_map(fn ($t) => mb_substr((string) $t, 0, 80), $titles));
        }

        return implode("\n", $lines);
    }
}
