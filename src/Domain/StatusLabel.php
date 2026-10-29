<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Validation for owner-defined status labels (broaden-use-cases issue 03).
 *
 * Shared-origin invariant (CLAUDE.md): every tenant shares one origin, so a
 * label is strictly single-line plaintext — no markup characters, no control
 * or invisible formatting characters (bidi overrides, zero-width), no
 * handler/scheme fragments, 1–40 characters after trimming. Rendered as
 * React text only. Used on write (strict, field error) and again on read
 * (BoardStatuses::fromRows(), an invalid stored label is dropped).
 */
final class StatusLabel
{
    public const MAX_LENGTH = 40;

    /** Null when $value is acceptable (blank = "no label"), else a generic error message. */
    public static function error(string $value): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return 'This label contains characters that are not allowed.';
        }

        $trimmed = trim($value);
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            return 'A label can be at most ' . self::MAX_LENGTH . ' characters long.';
        }

        if (preg_match('/[<>\p{Cc}\p{Cf}]/u', $trimmed) !== 0
            || preg_match('/\bon\w+\s*=/i', $trimmed) === 1
            || preg_match('/javascript\s*:/i', $trimmed) === 1
        ) {
            return 'This label contains characters that are not allowed.';
        }

        return null;
    }

    /** Trimmed label, or null when blank or invalid. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || self::error($value) !== null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
