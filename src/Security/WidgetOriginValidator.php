<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates the per-board list of origins that may frame the embeddable
 * widget (ADR 0005). This list ends up verbatim in a `frame-ancestors` CSP
 * directive, so it is load-bearing for clickjacking protection and must
 * never carry anything but a plain origin:
 *
 *  - exactly `https://host` or `https://host:port` — no path, query,
 *    fragment, userinfo, wildcard, whitespace or trailing slash;
 *  - host = ASCII hostname labels `[a-z0-9-]` (an IDN must be entered in
 *    its punycode `xn--` form, no homoglyphs) with at least one dot, so
 *    `localhost` and bare IPs are rejected (https only, ADR 0005);
 *  - port 1–65535 without leading zeros;
 *  - at most MAX_ORIGINS entries, de-duplicated, lower-cased.
 *
 * Used both when the owner saves the list (normalize()) and again as
 * defense in depth right before the CSP header is written
 * (SecurityHeaderMiddleware → isValid()).
 */
final class WidgetOriginValidator
{
    public const MAX_ORIGINS = 10;

    private const MAX_ORIGIN_LENGTH = 255;

    private const ORIGIN_PATTERN = '#^https://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?::([1-9][0-9]{0,4}))?$#D'; // D: $ must not match before a trailing newline (header injection)

    /** True when $origin is exactly one valid, already-normalized origin. */
    public static function isValid(string $origin): bool
    {
        if (strlen($origin) > self::MAX_ORIGIN_LENGTH || preg_match(self::ORIGIN_PATTERN, $origin, $m) !== 1) {
            return false;
        }

        // An all-numeric last label is an IPv4 literal, not a hostname.
        $labels = explode('.', $m[1]);
        if (ctype_digit(end($labels))) {
            return false;
        }

        $port = $m[2] ?? '';

        return $port === '' || (int) $port <= 65535;
    }

    /**
     * Normalizes a submitted list: trims each entry, lower-cases it, drops
     * empty lines and duplicates. Returns the clean list, or an error key
     * (`too_many` / `invalid:<index>`) — nothing invalid is ever silently
     * dropped, the owner must fix the entry.
     *
     * @param list<mixed> $origins
     * @return list<string>|string
     */
    public static function normalize(array $origins): array|string
    {
        $clean = [];
        foreach ($origins as $index => $raw) {
            if (!is_string($raw)) {
                return 'invalid:' . $index;
            }
            $origin = strtolower(trim($raw));
            if ($origin === '') {
                continue;
            }
            if (!self::isValid($origin)) {
                return 'invalid:' . $index;
            }
            $clean[$origin] = true;
        }

        if (count($clean) > self::MAX_ORIGINS) {
            return 'too_many';
        }

        return array_keys($clean);
    }

    /**
     * The origin part of a Referer/Origin header value, or null when it is
     * not a valid widget origin (used for install detection only).
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return null;
        }

        $origin = 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return self::isValid($origin) ? $origin : null;
    }
}
