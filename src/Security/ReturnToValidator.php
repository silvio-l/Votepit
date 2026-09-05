<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates return-to (redirect) paths to prevent open-redirect attacks.
 *
 * Only accepts relative internal paths: starts with a single '/', no scheme,
 * no host, no protocol-relative '//', no backslash.
 *
 * Security hardening:
 * - Rejects ASCII control characters (\x00-\x1F, \x7F) in raw or decoded forms
 *   (prevents WHATWG URL parser tab/newline/CR stripping bypasses like /\t/evil.com).
 * - Decodes URL iteratively to catch multi-level / double-encoded bypasses
 *   (e.g. /%252f%252fevil.com or /%255cevil.com).
 * - Checks normalized forms with whitespace stripped to prevent space-padded
 *   protocol-relative tricks (e.g. / /evil.com, / \evil.com).
 * - Ensures path starts with '/' and does NOT start with '//', '/\', '\/', or '\\'.
 * - Rejects any scheme indicator (':') or backslash ('\').
 */
final class ReturnToValidator
{
    /**
     * Returns true only for safe, relative internal paths.
     *
     * When in doubt → reject → caller falls back to the default path.
     */
    public static function isValid(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // 1. Control characters in raw input are invalid in internal paths
        // and can trigger WHATWG URL parsing stripping (tab 0x09, LF 0x0A, CR 0x0D, etc.).
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        // 2. Iteratively URL-decode to catch multi-level or double-encoded bypasses
        // (e.g. /%252f%252fevil.com -> /%2f%2fevil.com -> //evil.com).
        $decoded = $url;
        for ($i = 0; $i < 5; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        // 3. Control characters in decoded input are also invalid.
        if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return false;
        }

        // 4. Must start with a single '/'.
        if (!str_starts_with($decoded, '/')) {
            return false;
        }

        // 5. Must not start with protocol-relative patterns ('//', '/\', '\/', '\\').
        if (str_starts_with($decoded, '//')
            || str_starts_with($decoded, '/\\')
            || str_starts_with($decoded, '\\/')
            || str_starts_with($decoded, '\\\\')
        ) {
            return false;
        }

        // 6. Contains no scheme indicator ':' (no https:, http:, javascript:, data:, etc.)
        if (str_contains($decoded, ':')) {
            return false;
        }

        // 7. Contains no backslash '\' (browser trick converting \ to /)
        if (str_contains($decoded, '\\')) {
            return false;
        }

        // 8. Check collapsed whitespace version to prevent space-padded bypasses
        // (e.g., '/ /evil.com' or '/ \evil.com').
        $collapsed = preg_replace('/\s+/', '', $decoded);
        if ($collapsed === null || !str_starts_with($collapsed, '/')) {
            return false;
        }

        return !(
            str_starts_with($collapsed, '//')
            || str_starts_with($collapsed, '/\\')
            || str_starts_with($collapsed, '\\/')
            || str_starts_with($collapsed, '\\\\')
            || str_contains($collapsed, ':')
            || str_contains($collapsed, '\\')
        );
    }
}
