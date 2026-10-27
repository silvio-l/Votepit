<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Normalizer;

/**
 * Deterministic title normalisation for comparison / duplicate detection.
 *
 * Maps a raw idea title to a stable, compact comparison key. This key is
 * intentionally separate from the display title and is not shown to users.
 *
 * Pipeline (fixed order):
 *   1. mb_strtolower       — case folding (mb-safe)
 *   2. Normalizer::NFC     — compose canonical Unicode equivalents
 *   3. Latin-ASCII         — transliterate accented letters to ASCII (é→e)
 *   4. strip punctuation   — remove \p{P} and \p{S} characters
 *   5. strip whitespace    — remove all whitespace so that "dark-mode",
 *                            "dark mode", and "Darkmode" resolve to the
 *                            same key "darkmode"
 *   6. trim                — remove any leading/trailing remnants
 *
 * No DB or HTTP dependency. Requires ext-intl (declared in composer.json).
 */
final class TitleNormalizer
{
    /**
     * Normalise $title to a deterministic comparison key.
     *
     * Same input always yields the same output. The returned string contains
     * only lowercase ASCII alphanumeric characters — no separators, no
     * diacritics, no punctuation.
     */
    public function normalize(string $title): string
    {
        $key = $this->fold($title);

        // 4. Strip punctuation and symbol characters (Unicode-aware).
        $key = preg_replace('/[\p{P}\p{S}]/u', '', $key) ?? $key;

        // 5. Strip all whitespace — produces a compact, separator-free key so
        //    that "dark-mode" (hyphen removed → "darkmode"), "dark mode"
        //    (space removed → "darkmode"), and "Darkmode" all resolve to the
        //    identical string. This is intentional for fingerprinting.
        $key = preg_replace('/\s+/', '', $key) ?? $key;

        // 6. Trim any residual leading/trailing whitespace (defensive guard).
        return trim($key);
    }

    /**
     * The title's words, folded like normalize() (lowercase ASCII) but split
     * on every non-alphanumeric run instead of being joined — for word-level
     * comparison ("dark-mode" → ["dark", "mode"]). Empty fragments are dropped.
     *
     * @return list<string>
     */
    public function words(string $title): array
    {
        $words = preg_split('/[^a-z0-9]+/', $this->fold($title), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /** Steps 1–3 of the pipeline: lowercase, NFC, Latin-ASCII. */
    private function fold(string $title): string
    {
        // 1. Lowercase (mb-safe, handles multibyte characters)
        $key = mb_strtolower($title, 'UTF-8');

        // 2. Unicode NFC normalisation — compose canonical equivalents so that
        //    NFD-decomposed inputs (e.g. "Cafe\u{0301}") become precomposed
        //    (é = U+00E9) before transliteration.
        $nfc = \Normalizer::normalize($key, \Normalizer::NFC);
        if ($nfc !== false) {
            $key = $nfc;
        }

        // 3. Latin-ASCII transliteration — maps accented characters to their
        //    plain-ASCII equivalents (é→e, ü→u, ß→ss, …). // export-ok: comment-language
        $transliterator = \Transliterator::create('Latin-ASCII');
        if ($transliterator !== null) {
            $result = $transliterator->transliterate($key);
            if ($result !== false) {
                $key = $result;
            }
        }

        return $key;
    }
}
