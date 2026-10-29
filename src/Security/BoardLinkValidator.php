<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validation of the board's back link and footer note (P-31, Lite part).
 *
 * Every method returns the cleaned value, or null when the input is rejected.
 * Callers treat null as a 422 field error, never as "store the raw value".
 *
 * Back link: https only, ASCII host with at least one dot, no userinfo, no
 * explicit port, no whitespace/control characters, <= 2048 bytes. An IDN host
 * must be sent in its punycode form, so a visually spoofed Unicode hostname
 * can never be stored.
 *
 * Link text and footer note are PLAINTEXT: no markup characters (< and >) and
 * no control characters. The SPA renders them as text nodes, which is the
 * escaping that matters; rejecting markup characters on write is defence in
 * depth for every other consumer (shell meta tags, exports, mails).
 */
final class BoardLinkValidator
{
    public const URL_MAX_BYTES   = 2048;
    public const LABEL_MAX_CHARS = 40;
    public const NOTE_MAX_CHARS  = 280;

    /** RFC 1123-style hostname: ASCII labels, at least one dot, letter TLD. */
    private const HOST_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /** Blocks markup and every control character (incl. newlines and NUL). */
    private const PLAINTEXT_FORBIDDEN = '/[<>\p{Cc}]/u';

    public static function backLinkUrl(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '' || strlen($value) > self::URL_MAX_BYTES) {
            return null;
        }
        if (preg_match('/[\s\\\\]|\p{Cc}/u', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }
        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        if (!isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (preg_match(self::HOST_PATTERN, $host) !== 1) {
            return null;
        }

        return $value;
    }

    public static function backLinkLabel(string $raw): ?string
    {
        return self::plaintext($raw, self::LABEL_MAX_CHARS, minChars: 1);
    }

    public static function footerNote(string $raw): ?string
    {
        return self::plaintext($raw, self::NOTE_MAX_CHARS, minChars: 0);
    }

    private static function plaintext(string $raw, int $maxChars, int $minChars): ?string
    {
        $value = trim($raw);
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $minChars || $length > $maxChars) {
            return null;
        }
        if (preg_match(self::PLAINTEXT_FORBIDDEN, $value) === 1) {
            return null;
        }

        return $value;
    }
}
