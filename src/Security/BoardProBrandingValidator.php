<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validation of the Pro branding values (migration 0093): typography/shape/
 * density presets, custom wording per locale, header links.
 *
 * Every value is an allow-listed enum or plaintext that the SPA renders as a
 * text node. Presets never carry CSS: the SPA maps an enum to a `data-board-*`
 * attribute and our own stylesheet owns what that attribute means.
 *
 * Every method returns the cleaned value, or null when the input is rejected.
 * Callers treat null as a 422 field error, never as "store the raw value".
 * The same methods re-validate stored values on read (fail-secure).
 */
final class BoardProBrandingValidator
{
    public const TYPOGRAPHY = ['default', 'inter', 'mono'];
    public const SHAPES     = ['rounded', 'soft', 'square'];
    public const DENSITIES  = ['comfortable', 'compact'];

    public const LOCALES = ['de', 'en', 'fr', 'es', 'nl', 'it'];

    /** Wording fields and their maximum length in characters (minimum is always 1). */
    public const WORDING_MAX = [
        'idea_singular' => 30,
        'idea_plural'   => 30,
        'vote_for'      => 24,
        'vote_against'  => 24,
        'submit_button' => 40,
        'empty_state'   => 200,
        'thank_you'     => 80,
    ];

    public const HEADER_LINKS_MAX = 5;
    public const HEADER_LABEL_MAX = 30;

    /** Markup characters and every control character (incl. newlines and NUL). Braces are reserved for i18n templates. */
    private const PLAINTEXT_FORBIDDEN = '/[<>{}\p{Cc}]/u';

    /**
     * An enum preset: null/'' → the Votepit default; an allow-listed value → itself;
     * anything else → null (rejected).
     *
     * @param list<string> $allowed
     */
    public static function enum(mixed $raw, array $allowed): ?string
    {
        if ($raw === null) {
            return $allowed[0];
        }
        if (!is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));
        if ($value === '') {
            return $allowed[0];
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    public static function typography(mixed $raw): ?string
    {
        return self::enum($raw, self::TYPOGRAPHY);
    }

    public static function shape(mixed $raw): ?string
    {
        return self::enum($raw, self::SHAPES);
    }

    public static function density(mixed $raw): ?string
    {
        return self::enum($raw, self::DENSITIES);
    }

    /**
     * Wording: `{locale: {field: text}}`. Empty texts are dropped (the locale
     * then falls back to Votepit's own text). Unknown locales or fields, a
     * non-string, an over-long or markup-carrying text reject the whole value.
     *
     * @return array<string, array<string, string>>|null
     */
    public static function wording(mixed $raw): ?array
    {
        if (in_array($raw, [null, '', []], true)) {
            return [];
        }
        if (!is_array($raw)) {
            return null;
        }

        $clean = [];
        foreach ($raw as $locale => $fields) {
            if (!is_string($locale) || !in_array($locale, self::LOCALES, true) || !is_array($fields)) {
                return null;
            }
            $localeClean = [];
            foreach ($fields as $field => $text) {
                if (!is_string($field) || !array_key_exists($field, self::WORDING_MAX)) {
                    return null;
                }
                if ($text === null) {
                    continue;
                }
                if (!is_string($text)) {
                    return null;
                }
                $value = self::plaintext($text, self::WORDING_MAX[$field]);
                if ($value === null) {
                    return null;
                }
                if ($value !== '') {
                    $localeClean[$field] = $value;
                }
            }
            if ($localeClean !== []) {
                $clean[$locale] = $localeClean;
            }
        }

        return $clean;
    }

    /**
     * Header links: at most HEADER_LINKS_MAX items of {url, label}. Items with
     * both fields empty are skipped (blank rows in the editor). A half-filled
     * item, an unsafe URL or an over-long label rejects the whole list.
     *
     * @return list<array{url: string, label: string}>|null
     */
    public static function headerLinks(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $clean = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                return null;
            }
            $url   = is_string($item['url'] ?? null) ? trim($item['url']) : '';
            $label = is_string($item['label'] ?? null) ? trim($item['label']) : '';
            if ($url === '' && $label === '') {
                continue;
            }
            $cleanUrl = BoardLinkValidator::backLinkUrl($url);
            $cleanLabel = self::plaintext($label, self::HEADER_LABEL_MAX);
            if ($cleanUrl === null || $cleanLabel === null || $cleanLabel === '') {
                return null;
            }
            $clean[] = ['url' => $cleanUrl, 'label' => $cleanLabel];
        }

        return count($clean) <= self::HEADER_LINKS_MAX ? $clean : null;
    }

    /**
     * Stored wording JSON, re-validated on read. Anything unreadable yields no
     * wording at all (the default texts apply), never a partial guess.
     *
     * @return array<string, array<string, string>>
     */
    public static function decodeWording(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? (self::wording($decoded) ?? []) : [];
    }

    /** @return list<array{url: string, label: string}> */
    public static function decodeHeaderLinks(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? (self::headerLinks($decoded) ?? []) : [];
    }

    /**
     * Plaintext of at most $maxChars characters. Returns '' for an empty value
     * (callers decide whether that is allowed) and null for a rejected one.
     */
    private static function plaintext(string $raw, int $maxChars): ?string
    {
        $value  = trim($raw);
        $length = mb_strlen($value, 'UTF-8');
        if ($length > $maxChars) {
            return null;
        }
        if (preg_match(self::PLAINTEXT_FORBIDDEN, $value) === 1) {
            return null;
        }

        return $value;
    }
}
