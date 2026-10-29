<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

/**
 * Which redirect URIs a dynamically registered MCP client may use
 * (RFC 7591 registration, checked again on every authorization request by
 * exact string match against the registered list).
 *
 * Allowed:
 *  - `https://…` with a host — hosted clients (e.g. a web app's callback).
 *  - `http://` on a loopback host only (127.0.0.1, localhost, [::1], any
 *    port) — native/CLI clients receiving the code on a local listener
 *    (RFC 8252 §7.3).
 *
 * Rejected: every other scheme, including private-use/custom schemes
 * (`cursor://`, `vscode://`, …) — a custom scheme can be claimed by any
 * locally installed app, and a public client has no secret to prove it is
 * the one that registered it. Clients that only support such schemes can
 * keep using a static Bearer token. Also rejected: plain http on a
 * non-loopback host, userinfo (`user@host`), fragments, whitespace/control
 * characters and anything longer than MAX_LENGTH.
 */
final class RedirectUriPolicy
{
    public const MAX_LENGTH = 512;

    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    public static function isAllowed(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F-\xFF\\\\]/', $uri) === 1) {
            return false;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || str_contains($uri, '#')) {
            return false;
        }

        // parse_url() is lenient — the scheme and authority must be spelled
        // exactly as parsed (lower-case scheme, "//" authority), so the
        // string a browser navigates to is the one that was validated.
        $scheme = $parts['scheme'];
        $host   = strtolower($parts['host']);
        if (!str_starts_with($uri, $scheme . '://')) {
            return false;
        }

        if ($scheme === 'https') {
            return preg_match('/\A[a-z0-9.-]+\z/', $host) === 1 && !str_starts_with($host, '.') && !str_ends_with($host, '.');
        }

        return $scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true);
    }
}
