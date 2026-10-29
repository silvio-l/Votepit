<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * Provider-agnostic seam for machine translation of idea/comment content
 * (sibling to Votepit\Security\OAuth's interface+curl-impl+in-memory-fake
 * pattern). Exactly one method: translate a piece of text into a target
 * locale, letting the provider auto-detect the source language — no
 * `$sourceLocale` parameter is ever accepted, since nothing in this
 * codebase tags idea/comment rows with a source language and every
 * provider under consideration already does auto-detection as part of the
 * same call.
 *
 * Implementations MUST throw TranslationUnavailableException on every
 * failure (auth, network, non-2xx, quota, malformed/empty response) —
 * never return a degraded result, never leak a provider-specific exception
 * type across this seam.
 */
interface TranslationProvider
{
    /**
     * $sourceLocale, when given, skips auto-detection and translates as if
     * the text were already known to be in that locale — for a short/
     * ambiguous string (e.g. an idea title dominated by English loanwords
     * like "Mobile App" or "iOS") whose OWN auto-detection is unreliable,
     * pass the locale already reliably detected from a longer companion
     * text (e.g. that same idea's body) instead of trusting a fresh guess.
     *
     * @return array{text: string, detected_source_locale: string}
     * @throws TranslationUnavailableException on any failure
     */
    public function translate(string $text, string $targetLocale, ?string $sourceLocale = null): array;
}
