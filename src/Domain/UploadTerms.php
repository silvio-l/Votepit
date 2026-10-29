<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Upload clause (AGB clause 19, Votepit Cloud) — constants for the consent proof.
 *
 * RETENTION_YEARS: after an account is erased, its consent proof stays
 * pseudonymised (migration 0101) for this long, then the cleanup cron deletes
 * it (bin/cleanup-expired-accounts.php). Three years is the regular
 * limitation period (BGB § 195) for claims that may rest on an upload, counted
 * from the erasure. Owner answer 29a (2026-10-25). Lawyer to confirm.
 *
 * UI_LANGUAGES: the UI languages the clause is shown in (the six SPA
 * dictionaries). The language in which the owner accepted is stored as proof.
 */
final class UploadTerms
{
    public const RETENTION_YEARS = 3;

    /** @var list<string> */
    public const UI_LANGUAGES = ['de', 'en', 'fr', 'es', 'nl', 'it'];

    /** The language if it is one of UI_LANGUAGES, otherwise null (never a guessed value). */
    public static function normalizeLanguage(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, self::UI_LANGUAGES, true) ? $raw : null;
    }
}
