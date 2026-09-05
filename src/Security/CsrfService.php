<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;

/**
 * CSRF synchronizer token (ADR-6, Amendment 2026-06-22).
 *
 * The original slim/csrf needs server-side $_SESSION storage, which
 * contradicts the deliberately stateless signed-cookie session
 * (SessionService). Instead, the CSRF token lives in its own HMAC-signed
 * cookie (same base64url+SHA256 scheme as SessionService) and is mirrored
 * by the server into every form — a real synchronizer token without
 * server-side state.
 *
 * Cookie layout: <token-hex> '.' base64url(hmac_sha256(token, app_key)).
 * The token itself is hex (no dots), the MAC is base64url (no dots) →
 * unambiguously splittable. Verification is constant-time via hash_equals.
 */
final readonly class CsrfService
{
    private const COOKIE_NAME = 'votepit_csrf';
    private const FIELD_NAME  = '_csrf';

    public function __construct(
        private string $appKey,
        private int $lifetime,
        private bool $secure,
    ) {}

    /** Generates a fresh random token (32 bytes → 64 hex). */
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Signs a token into a cookie value. */
    public function sign(string $token): string
    {
        return $token . '.' . $this->mac($token);
    }

    /** Reads and verifies a cookie value; returns the token or null. */
    public function read(?string $cookie): ?string
    {
        if ($cookie === null || !str_contains($cookie, '.')) {
            return null;
        }
        [$token, $mac] = explode('.', $cookie, 2);
        if ($token === '' || $mac === '') {
            return null;
        }
        if (!hash_equals($this->mac($token), $mac)) {
            return null;
        }
        return $token;
    }

    /** Sets the CSRF cookie on the response (HttpOnly: only the server mirrors the token). */
    public function issue(ResponseInterface $response, string $token): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=' . $this->sign($token)
            . '; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=' . $this->lifetime
        );
    }

    public function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    private function mac(string $token): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $token, $this->appKey, true)), '+/', '-_'), '=');
    }
}
