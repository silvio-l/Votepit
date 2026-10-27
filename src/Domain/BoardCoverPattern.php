<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * The cover patterns a board may select (P-28c). Every pattern is drawn by
 * our own SVG code (packages/ui BoardCover); a stored value only ever picks
 * one of these fixed names, never tenant markup. NULL = the default pattern
 * (the seed-based shapes, unchanged).
 */
final class BoardCoverPattern
{
    public const DEFAULT = null;

    /** @var list<string> */
    public const ALL = ['waves', 'dots', 'stripes', 'bokeh', 'grid'];

    /**
     * Validated write/read value: a known pattern name or null (default).
     * Empty strings and unknown names are null here; callers that must reject
     * unknown input check isKnown() first.
     */
    public static function normalize(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, self::ALL, true) ? $raw : null;
    }

    public static function isKnown(mixed $raw): bool
    {
        return is_string($raw) && in_array($raw, self::ALL, true);
    }
}
