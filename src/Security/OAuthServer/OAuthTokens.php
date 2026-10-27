<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

use Votepit\Security\TokenVault;

/**
 * Secret shapes and lifetimes of the MCP OAuth server. Every secret is a
 * prefixed 256-bit random value; only its SHA-256 (TokenVault::hash()) is
 * stored. The prefixes make the three kinds distinguishable for secret
 * scanners and let ApiTokenAuthenticator route an access token to its own
 * lookup — a static API token is plain 64-char hex and never carries one.
 */
final class OAuthTokens
{
    public const CODE_PREFIX    = 'vpac_';
    public const ACCESS_PREFIX  = 'vpat_';
    public const REFRESH_PREFIX = 'vprt_';

    /** Authorization codes are exchanged immediately by the client. */
    public const CODE_TTL_SECONDS = 120;

    /** Short-lived access tokens; clients refresh transparently. */
    public const ACCESS_TTL_SECONDS = 3600;

    /** A refresh token expires after 30 days without use (each use rotates it). */
    public const REFRESH_TTL_SECONDS = 2_592_000;

    /** @return array{token: string, hash: string} */
    public static function generate(string $prefix, TokenVault $vault): array
    {
        $token = $prefix . bin2hex(random_bytes(32));

        return ['token' => $token, 'hash' => $vault->hash($token)];
    }

    public static function format(\DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s');
    }
}
