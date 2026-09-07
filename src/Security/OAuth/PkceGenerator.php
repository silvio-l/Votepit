<?php

declare(strict_types=1);

namespace Votepit\Security\OAuth;

/**
 * PKCE (RFC 7636) code_verifier / code_challenge pair — used for the Google
 * authorization-code flow (OAuthProviderRegistry::endpoints('google')['pkce'] === true).
 *
 * code_verifier: 32 random bytes, base64url-encoded (43 chars, within the
 * RFC's 43-128 char requirement). code_challenge: base64url(SHA256(verifier)),
 * method "S256" (plain is never used).
 */
final class PkceGenerator
{
    /** @return array{verifier: string, challenge: string} */
    public static function generate(): array
    {
        $verifier = self::base64url(random_bytes(32));

        return [
            'verifier'  => $verifier,
            'challenge' => self::base64url(hash('sha256', $verifier, true)),
        ];
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
