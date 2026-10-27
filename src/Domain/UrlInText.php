<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Detects links in guest-submitted text (ADR 0006 §6). Guests may not post
 * scheme URLs or www. hosts — links are the main spam/phishing payload on
 * the shared origin. Obfuscation is normalised away first: NFKC folds
 * full-width forms, invisible format characters (zero-width space, soft
 * hyphen, …) are stripped, "hxxp" counts as "http". Bare domains are
 * deliberately allowed (too many false positives; text is never linkified).
 */
final class UrlInText
{
    private const PATTERNS = [
        '/h(?:tt|xx)ps?\s*:\s*\/\s*\//u',
        '/\bwww\s*\.\s*[a-z0-9]/u',
    ];

    public static function containsUrl(string $text): bool
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        if (!is_string($normalized)) {
            // Invalid UTF-8 cannot be normalised — treat as suspicious (fail-secure).
            return true;
        }
        $normalized = mb_strtolower((string) preg_replace('/\p{Cf}+/u', '', $normalized), 'UTF-8');

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
