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
    /**
     * `__Host-` (RFC 6265bis) requires Path=/, no Domain attribute, AND the
     * Secure attribute — browsers (Chromium confirmed) silently refuse to
     * store a `__Host-`-prefixed cookie that lacks Secure. A non-HTTPS
     * deployment ($secure === false: local dev, the E2E suite) therefore
     * cannot use this name at all; cookieName() falls back to the plain
     * PRE_HARDENING_COOKIE_NAME there instead, matching pre-hardening
     * behavior for non-HTTPS environments while keeping the hardened name in
     * production. See SessionService::cookieName() for the identical fix.
     */
    private const HOST_COOKIE_NAME = '__Host-votepit_csrf';

    /** Pre-rename name, kept only to actively expire stale browser cookies. */
    private const PRE_HARDENING_COOKIE_NAME = 'votepit_csrf';

    private const FIELD_NAME = '_csrf';

    /**
     * Domain-separation prefix for the signing HMAC (V9.2.2) — mirrors
     * SessionService's `session:` prefix and the `totp_setup:` pattern in
     * TotpSetupToken. A CSRF-cookie MAC input can structurally never verify
     * as a session-cookie MAC input, even under app_key reuse.
     */
    private const HMAC_PREFIX = 'csrf:';

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
        $response = $response->withAddedHeader(
            'Set-Cookie',
            $this->cookieName() . '=' . $this->sign($token)
            . '; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=' . $this->lifetime
        );
        // Not secure: cookieName() IS the pre-hardening name, so clearing it
        // here would immediately delete the cookie just set above.
        return $this->secure ? $this->withPreHardeningCookieCleared($response) : $response;
    }

    /**
     * Actively expires the pre-`__Host-`-rename plain cookie name (see
     * PRE_HARDENING_COOKIE_NAME doc) — a one-time cleanup so a browser that
     * already holds the old-named cookie doesn't keep it around unused.
     * Unconditional, mirroring SessionService::withPreHardeningCookieCleared().
     */
    private function withPreHardeningCookieCleared(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            self::PRE_HARDENING_COOKIE_NAME . '=; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=0'
        );
    }

    public function cookieName(): string
    {
        return $this->secure ? self::HOST_COOKIE_NAME : self::PRE_HARDENING_COOKIE_NAME;
    }

    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    private function mac(string $token): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', self::HMAC_PREFIX . $token, $this->appKey, true)), '+/', '-_'), '=');
    }
}
