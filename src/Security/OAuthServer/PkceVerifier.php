<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

use Votepit\Security\OAuth\PkceGenerator;

/**
 * PKCE (RFC 7636) for the MCP OAuth server — S256 ONLY. `plain` and a
 * missing challenge are rejected at the authorization request already
 * (AuthorizationRequestValidator); this class checks the two value shapes
 * and the verifier at the token endpoint.
 */
final class PkceVerifier
{
    /** An S256 challenge is always base64url(SHA-256) without padding: exactly 43 chars. */
    public static function isValidChallenge(string $challenge): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $challenge) === 1;
    }

    /** RFC 7636 §4.1: 43–128 chars from the unreserved set. */
    public static function isValidVerifier(string $verifier): bool
    {
        return preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier) === 1;
    }

    /** Constant-time comparison of base64url(SHA-256(verifier)) against the stored challenge. */
    public static function verify(string $verifier, string $challenge): bool
    {
        if (!self::isValidVerifier($verifier) || !self::isValidChallenge($challenge)) {
            return false;
        }

        return hash_equals($challenge, PkceGenerator::challengeFor($verifier));
    }
}
