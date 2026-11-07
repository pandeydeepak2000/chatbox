<?php

namespace App\Support;

/**
 * Normalises customer questions and knowledge text into comparable search terms.
 * Handles English, Hindi (Devanagari) and Roman Hinglish filler words and common hosting typos.
 */
class SearchText
{
    private const STOP = [
        'the', 'is', 'are', 'was', 'were', 'be', 'been', 'how', 'to', 'what', 'can', 'i', 'do', 'does', 'did', 'you', 'your', 'my', 'me', 'in', 'on',
        'a', 'an', 'and', 'or', 'of', 'it', 'its', 'please', 'pls', 'plz', 'help', 'want', 'need', 'give', 'show', 'tell', 'about', 'for', 'with',
        'this', 'that', 'there', 'here', 'some', 'any', 'from', 'where', 'when', 'why', 'who', 'which', 'will', 'would', 'could', 'should', 'have',
        'has', 'had', 'not', 'no', 'yes', 'hi', 'hello', 'hey', 'thanks', 'thank', 'sir', 'madam', 'ji', 'at', 'by', 'as', 'if', 'so', 'we', 'our', 'us',
        // Roman Hinglish
        'kya', 'hai', 'hain', 'ho', 'kaise', 'kaisa', 'karein', 'karo', 'karna', 'karni', 'kar', 'mera', 'meri', 'mere', 'aur', 'ko', 'se', 'ke', 'ki',
        'ka', 'mein', 'me', 'par', 'pe', 'bataao', 'batao', 'bataye', 'bataiye', 'chahiye', 'gaye', 'gaya', 'mujhe', 'humko', 'aap', 'apna', 'apne',
        'kyun', 'kab', 'kaha', 'kahan', 'nahi', 'nahin', 'haan', 'bhai', 'please', 'krna', 'krdo', 'kardo', 'raha', 'rahi', 'rahe', 'tha', 'thi',
        // Hindi (Devanagari)
        'है', 'हैं', 'का', 'की', 'के', 'को', 'में', 'से', 'और', 'क्या', 'कैसे', 'मेरा', 'मेरी', 'मुझे', 'आप', 'पर', 'यह', 'वह', 'था', 'थी', 'करना', 'करें', 'कृपया',
    ];

    private const SYNONYMS = [
        'pass' => 'password', 'pwd' => 'password', 'passwrd' => 'password', 'passwords' => 'password',
        'bill' => 'invoice', 'invoices' => 'invoice', 'reciept' => 'receipt', 'billing' => 'invoice',
        'ns' => 'nameserver', 'nameservers' => 'nameserver', 'ns1' => 'nameserver', 'ns2' => 'nameserver',
        'wp' => 'wordpress', 'domian' => 'domain', 'domin' => 'domain', 'doain' => 'domain', 'domains' => 'domain',
        'puchase' => 'purchase', 'purhcase' => 'purchase', 'buy' => 'purchase', 'kharidna' => 'purchase', 'kharid' => 'purchase',
        'tranfer' => 'transfer', 'migrate' => 'transfer', 'migration' => 'transfer',
        'cert' => 'ssl', 'certificate' => 'ssl', 'https' => 'ssl',
        'webmail' => 'email', 'mail' => 'email', 'mailbox' => 'email', 'emails' => 'email',
        'cpannel' => 'cpanel', 'refunds' => 'refund', 'payments' => 'payment', 'upi' => 'payment',
        'renewal' => 'renew', 'renewing' => 'renew', 'expire' => 'renew', 'expired' => 'renew', 'expiry' => 'renew',
        'servers' => 'server', 'websites' => 'website', 'site' => 'website', 'sites' => 'website',
        'डोमेन' => 'domain', 'होस्टिंग' => 'hosting', 'पासवर्ड' => 'password', 'ईमेल' => 'email', 'भुगतान' => 'payment',
    ];

    /** @return array<int, string> unique normalised terms */
    public static function terms(string $text): array
    {
        $text = mb_strtolower($text);
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            $w = self::SYNONYMS[$w] ?? $w;
            if (in_array($w, self::STOP, true) || mb_strlen($w) < 2 || (mb_strlen($w) < 3 && ! preg_match('/\p{Devanagari}/u', $w))) {
                continue;
            }
            // light stemming for English plurals / -ing
            if (preg_match('/^[a-z]{5,}$/', $w)) {
                $w = preg_replace('/(ing|es|s)$/', '', $w) ?: $w;
            }
            $out[$w] = true;
        }

        return array_keys($out);
    }

    public static function keywordString(string $text): string
    {
        return implode(' ', self::terms($text));
    }

    /** en | hi (Devanagari) | hi-Latn (Roman Hinglish) */
    public static function language(string $text): string
    {
        if (preg_match('/\p{Devanagari}/u', $text)) {
            return 'hi';
        }
        $words = preg_split('/[^a-z]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hinglish = ['hai', 'hain', 'kya', 'kaise', 'mera', 'meri', 'mujhe', 'nahi', 'karna', 'karo', 'chahiye', 'kyun', 'aap', 'bhai', 'batao', 'kab', 'raha', 'rahi', 'gaya', 'mein', 'ho', 'ji', 'krna', 'kardo'];
        $hits = count(array_intersect($words, $hinglish));

        return ($hits >= 2 || ($hits >= 1 && count($words) <= 4)) ? 'hi-Latn' : 'en';
    }

    /** Explicit "reply in X" requests. */
    public static function requestedLanguage(string $text): ?string
    {
        $t = mb_strtolower($text);
        if (preg_match('/\b(reply|answer|talk|speak|write)\s+(in\s+)?english\b|english\s+(me|mein)\s+(batao|bolo|likho)/u', $t)) {
            return 'en';
        }
        if (preg_match('/hindi\s+(me|mein)\s+(batao|bolo|likho|samjhao)|\b(reply|answer|speak|write)\s+(in\s+)?hindi\b|हिंदी\s+में/u', $t)) {
            return 'hi';
        }
        if (preg_match('/\bhinglish\b|roman\s+hindi/u', $t)) {
            return 'hi-Latn';
        }

        return null;
    }
}
