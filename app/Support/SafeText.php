<?php

namespace App\Support;

/**
 * Input normalisation helpers.
 *
 * Messages are stored as plain text (escaping happens when rendering).
 * Short profile fields (name, phone, browser...) never legitimately contain markup,
 * so tags are removed from them as defence in depth.
 */
class SafeText
{
    /**
     * Plain-text message body: trim, drop NUL/control chars (keep \n and \t) and remove
     * HTML tags as defence in depth. Unlike strip_tags(), text such as "x < 5" or "a & b"
     * is kept intact, and nothing is HTML-encoded (audit B2).
     */
    public static function message(?string $value): string
    {
        $value = (string) $value;
        $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value) ?? '';
        $value = preg_replace('#<!--.*?-->#s', '', $value) ?? '';
        $value = preg_replace('#</?[a-zA-Z][^<>]*>#', '', $value) ?? '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_replace("\r\n", "\n", $value);

        return trim($value);
    }

    /** Single-line profile field: no tags, no control characters, collapsed whitespace. */
    public static function field(?string $value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        // Remove any leftover angle brackets so the value can never form markup.
        $value = str_replace(['<', '>'], '', $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** URL-ish field (page URL): only http(s), otherwise dropped. */
    public static function url(?string $value, int $max = 2000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim($value);
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
