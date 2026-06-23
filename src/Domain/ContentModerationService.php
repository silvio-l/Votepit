<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Local word-filter for content moderation (pure deterministic domain service).
 *
 * Loads blocklists from `resources/moderation/{de,en}.txt` once at construction
 * and checks user-submitted plaintext for hard-block matches.
 *
 * Design principles (Issue 08 / Sprint 3):
 *   - No DB, no HTTP, no external calls.
 *   - Whole-word matching only (avoids the Scunthorpe problem).
 *   - Simple leetspeak normalisation before matching.
 *   - Immutable extension via withAdditionalWords() for per-board custom lists.
 *
 * @phpstan-type ModerationResult array{clean: bool, hits: list<string>}
 */
final readonly class ContentModerationService
{
    /** @var list<string> All loaded blocklist phrases, already lowercased */
    private array $blocklist;

    /**
     * @param string $resourceDir Path to the directory containing de.txt / en.txt.
     *                            Defaults to the vendored resources/moderation directory.
     * @param list<string> $additionalWords Extra words injected without mutating the base lists.
     */
    public function __construct(
        private string $resourceDir = __DIR__ . '/../../resources/moderation',
        private array $additionalWords = [],
    ) {
        $this->blocklist = $this->loadBlocklist();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check one or more text fields (e.g. title + body) for blocked content.
     *
     * Returns a result array:
     *   - `clean`  (bool)        — true if no blocked phrase was found
     *   - `hits`   (list<string>) — matched phrases from the blocklist (for masked audit logging)
     *
     * @param string ...$texts One or more plaintext fields to check.
     * @return ModerationResult
     */
    public function check(string ...$texts): array
    {
        $combined = mb_strtolower(implode(' ', $texts), 'UTF-8');
        $normalized = $this->normalizeLeetspeak(mb_trim($combined));

        $hits = [];
        foreach ($this->blocklist as $phrase) {
            if ($this->matchesWholeWord($normalized, $phrase)) {
                $hits[] = $phrase;
            }
        }

        return [
            'clean' => $hits === [],
            'hits'  => array_values(array_unique($hits)),
        ];
    }

    /**
     * Return a new instance that additionally blocks the given words,
     * without mutating the base blocklist (immutable extension for Issue 09/10).
     *
     * @param list<string> $words Additional phrases to block.
     */
    public function withAdditionalWords(array $words): self
    {
        $merged = array_merge($this->additionalWords, $words);
        return new self($this->resourceDir, $merged);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load and merge de.txt + en.txt + $additionalWords into one deduplicated list.
     *
     * @return list<string>
     */
    private function loadBlocklist(): array
    {
        $phrases = [];

        foreach (['de.txt', 'en.txt'] as $file) {
            $path = rtrim($this->resourceDir, '/') . '/' . $file;
            if (!is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                // Skip comment lines and empty lines after trim
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $phrases[] = $this->normalizeLeetspeak(mb_strtolower($line, 'UTF-8'));
            }
        }

        foreach ($this->additionalWords as $word) {
            $word = $this->normalizeLeetspeak(mb_strtolower(trim($word), 'UTF-8'));
            if ($word !== '') {
                $phrases[] = $word;
            }
        }

        return array_values(array_unique($phrases));
    }

    /**
     * Apply simple leetspeak substitutions to $text (lowercase input assumed).
     *
     * Substitutions: 4→a, 3→e, 1→i, 0→o, 5→s, $→s, @→a
     */
    private function normalizeLeetspeak(string $text): string
    {
        return strtr($text, [
            '4' => 'a',
            '3' => 'e',
            '1' => 'i',
            '0' => 'o',
            '5' => 's',
            '$' => 's',
            '@' => 'a',
        ]);
    }

    /**
     * Check whether $phrase appears in $text as a whole word (or whole phrase).
     *
     * Uses a Unicode-aware regex with \b-equivalent boundaries so that partial
     * substring matches inside longer words do not trigger (Scunthorpe guard).
     *
     * For multi-word phrases the boundary check applies to the start of the first
     * word and the end of the last word.
     */
    private function matchesWholeWord(string $text, string $phrase): bool
    {
        // Escape the phrase for safe regex embedding
        $escaped = preg_quote($phrase, '/');

        // Replace literal spaces in the phrase with \s+ to tolerate minor spacing
        $escaped = preg_replace('/\s+/', '\\s+', $escaped);

        // Build a word-boundary regex that is multibyte (Unicode) aware.
        // \b in PCRE does not work correctly with non-ASCII (e.g. DE Umlauts),
        // so we use a lookahead/lookbehind against \p{L}\p{N} (letter or digit).
        $pattern = '/(?<![\\p{L}\\p{N}])' . $escaped . '(?![\\p{L}\\p{N}])/ui';

        return (bool) preg_match($pattern, $text);
    }
}
