<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates return-to (redirect) paths to prevent open-redirect attacks.
 *
 * Only accepts relative internal paths: starts with a single '/', no scheme,
 * no host, no protocol-relative '//', no backslash. One level of URL-decoding
 * is applied before checking to catch encoded bypasses (e.g. /%2Fevil.com).
 */
final class ReturnToValidator
{
    /**
     * Returns true only for safe, relative internal paths.
     *
     * Rules (all must hold after one pass of rawurldecode):
     *   - Non-empty
     *   - Starts with '/'
     *   - Does NOT start with '//' (protocol-relative URL)
     *   - Contains no ':' (no scheme: https:, javascript:, data:, etc.)
     *   - Contains no '\' (backslash — /\evil.com browser trick)
     *
     * When in doubt → reject → caller falls back to the default path.
     */
    public static function isValid(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Decode one level to catch encoded bypasses such as /%2Fevil.com.
        $decoded = rawurldecode($url);

        if (!str_starts_with($decoded, '/')) {
            return false;
        }

        if (str_starts_with($decoded, '//')) {
            return false;
        }

        if (str_contains($decoded, ':')) {
            return false;
        }

        return !str_contains($decoded, '\\');
    }
}
