<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * Fail-secure default — wired in whenever Config::translationConfigured()
 * is false (no provider configured at all, the Community Edition default).
 * Always throws immediately, with no side effects (no HTTP call, no log
 * write, no DB write) — this IS what makes "translation disabled by
 * default" a real, tested code path rather than a UI-only assumption.
 */
final class NullTranslationProvider implements TranslationProvider
{
    public function translate(string $text, string $targetLocale, ?string $sourceLocale = null): array
    {
        throw new TranslationUnavailableException('translation: no provider configured');
    }
}
