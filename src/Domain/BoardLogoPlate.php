<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * The plate behind a board's round logo on the top bar (dogfood v0.1.5, item 09).
 *
 * The plate is an opaque disc that sits behind the logo, so transparent corners of
 * the image never show the bar's colour through:
 *   - auto:  the board's secondary colour, unless the logo would vanish on it, then white
 *   - brand: always the board's secondary colour (the bar colour)
 *   - white: always white
 *   - none:  no plate at all
 *
 * NULL = auto (the default). Lite and higher may set it; a downgrade keeps the stored
 * value and it is simply not applied (Q19).
 */
final class BoardLogoPlate
{
    public const AUTO = 'auto';
    public const BRAND = 'brand';
    public const WHITE = 'white';
    public const NONE = 'none';

    public const DEFAULT = null;

    /** @var list<string> */
    public const ALL = [self::AUTO, self::BRAND, self::WHITE, self::NONE];

    /**
     * Validated write/read value: a known plate name, or null (auto). Anything else
     * is null here; callers that must reject unknown input check isKnown() first.
     */
    public static function normalize(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, self::ALL, true) && $raw !== self::AUTO ? $raw : null;
    }

    public static function isKnown(mixed $raw): bool
    {
        return is_string($raw) && in_array($raw, self::ALL, true);
    }
}
