<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * Strict MAJOR.MINOR.PATCH handling for Community release versions. Pre-release
 * and build-metadata forms are rejected on purpose: the update check only ever
 * considers stable releases, and anything else is treated as "no answer".
 */
final class SemanticVersion
{
    public static function isValid(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+\z/', $version) === 1;
    }

    /** True only when both sides are valid and the candidate is strictly newer. */
    public static function isNewer(string $candidate, string $installed): bool
    {
        return self::isValid($candidate)
            && self::isValid($installed)
            && version_compare($candidate, $installed, '>');
    }

    /** "v0.1.8" -> "0.1.8"; anything that is not a stable version tag gives null. */
    public static function fromTag(string $tag): ?string
    {
        $version = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;

        return self::isValid($version) ? $version : null;
    }
}
