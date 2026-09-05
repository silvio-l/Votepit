<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;

/**
 * Stateless signed-cookie session.
 *
 * Cookie layout: base64url(json(payload)) + '.' + base64url(hmac_sha256(...)).
 * The server holds no server-side session (no /tmp, no file locks, shared-
 * hosting-friendly). Payload contains only a user ID (and optionally a
 * CSRF token seed); on logout the cookie is deleted client-side AND its
 * validity is also made revocable via a server-side session table
 * (rotating `session_series`).
 *
 * Constant-time: HMAC verification via hash_equals (timing-attack resistant).
 */
final readonly class SessionService
{
    private const COOKIE_NAME = 'votepit_sess';

    /** Payload claim: absolute expiry (unix seconds). Enforced server-side in verify(). */
    public const CLAIM_EXPIRES = 'exp';

    /** Payload claim: issue time (unix seconds) — diagnostic, not enforced. */
    public const CLAIM_ISSUED_AT = 'iat';

    /**
     * @param ?string $cookieDomain Domain attribute of the Set-Cookie header
     *                              (cloud path routing). Null/unset
     *                              (default, self-host) = no Domain attribute,
     *                              cookie is host-only. A multi-tenant deployment
     *                              sets its app host (Config::sessionCookieDomain).
     */
    public function __construct(
        private string $appKey,
        private int $lifetime,
        private bool $secure,
        private ?string $cookieDomain = null,
    ) {}

    /**
     * Signs a payload into a cookie value.
     *
     * Security review (ASVS V3.3 / CWE-613): every signed session carries an
     * absolute server-side expiry (`exp`, unix seconds = now + lifetime) and
     * an issue timestamp (`iat`). Before this, the cookie's Max-Age was the
     * ONLY lifetime bound — a stolen/replayed cookie value stayed valid
     * forever (until token_version rotated). Callers may pass their own
     * `exp` (tests use this to craft already-expired cookies); otherwise it
     * is stamped here so every code path that signs a session is covered.
     *
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        $now = time();
        if (!isset($payload[self::CLAIM_ISSUED_AT])) {
            $payload[self::CLAIM_ISSUED_AT] = $now;
        }
        if (!isset($payload[self::CLAIM_EXPIRES])) {
            $payload[self::CLAIM_EXPIRES] = $now + $this->lifetime;
        }
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $mac  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->appKey, true)), '+/', '-_'), '=');
        return $body . '.' . $mac;
    }

    /**
     * Verifies a cookie value; returns the payload or null.
     *
     * Fail-secure: a payload without a numeric `exp`, or whose `exp` lies in
     * the past, is treated exactly like a bad MAC (null = anonymous).
     *
     * @return array<string, mixed>|null
     */
    public function verify(?string $cookie): ?array
    {
        $payload = $this->verifySignature($cookie);
        if ($payload === null) {
            return null;
        }
        $exp = $payload[self::CLAIM_EXPIRES] ?? null;
        if (!is_int($exp) || $exp <= time()) {
            return null;
        }
        return $payload;
    }

    /**
     * MAC + decoding only (no expiry check). Kept separate so the expiry rule
     * in verify() cannot be bypassed accidentally by a future caller.
     *
     * @return array<string, mixed>|null
     */
    private function verifySignature(?string $cookie): ?array
    {
        if ($cookie === null || !str_contains($cookie, '.')) {
            return null;
        }
        [$body, $mac] = explode('.', $cookie, 2);
        if ($body === '' || $mac === '') {
            return null;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->appKey, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $mac)) {
            return null;
        }
        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($payload) ? $payload : null;
    }

    /**
     * Sets the session cookie on the response.
     *
     * @param array<string, mixed> $payload
     */
    public function issue(ResponseInterface $response, array $payload): ResponseInterface
    {
        // withAddedHeader (not withHeader): a parallel Set-Cookie (e.g. the
        // CSRF cookie from CsrfMiddleware) must not be overwritten.
        $response = $response->withAddedHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=' . $this->sign($payload)
            . '; Path=/; HttpOnly; SameSite=Strict'
            . $this->domainAttribute()
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=' . $this->lifetime
        );
        return $this->withLegacyHostOnlyCookieCleared($response);
    }

    /** Deletes the session cookie (logout). */
    public function clear(ResponseInterface $response): ResponseInterface
    {
        $response = $response->withHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=; Path=/; HttpOnly; SameSite=Strict'
            . $this->domainAttribute()
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=0'
        );
        return $this->withLegacyHostOnlyCookieCleared($response);
    }

    /**
     * Also expires any pre-existing host-only cookie of the same name (no
     * Domain attribute) on a Domain-scoped deployment (cloud). Before
     * `session_cookie_domain` was introduced, sessions were issued host-only;
     * a browser that still holds one of those from that era stores it
     * alongside the new Domain-scoped cookie (both domain-match the same
     * host) and may send the stale one first — its signature/token_version
     * then never verifies, silently bouncing the user back to /login right
     * after a successful login. A no-op on self-host (no cookieDomain set,
     * nothing to clean up).
     */
    private function withLegacyHostOnlyCookieCleared(ResponseInterface $response): ResponseInterface
    {
        if ($this->cookieDomain === null || $this->cookieDomain === '') {
            return $response;
        }
        return $response->withAddedHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=0'
        );
    }

    /** Domain attribute fragment (empty = host-only, self-host default). */
    private function domainAttribute(): string
    {
        return $this->cookieDomain !== null && $this->cookieDomain !== ''
            ? '; Domain=' . $this->cookieDomain
            : '';
    }

    public function cookieName(): string
    {
        return self::COOKIE_NAME;
    }
}
