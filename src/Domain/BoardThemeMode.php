<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * The display mode a board chooses (P-26, dark mode): follow the viewer's
 * system (`system`, default), always light, or always dark. Stored as a fixed
 * name; the SPA maps it to the DS colour scheme. Available on every plan
 * (owner answer 45a, 2026-10-25), so it is not a PlanPolicy branding field.
 */
final class BoardThemeMode
{
    public const DEFAULT = 'system';

    /** @var list<string> */
    public const ALL = ['system', 'light', 'dark'];

    /** Stored value or the default. Unknown and NULL values read as the default. */
    public static function normalize(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::ALL, true) ? $raw : self::DEFAULT;
    }

    public static function isKnown(mixed $raw): bool
    {
        return is_string($raw) && in_array($raw, self::ALL, true);
    }
}
