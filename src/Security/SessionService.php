<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;

/**
 * Stateless signed-cookie session.
 *
 * Cookie layout: base64url(json(payload)) + '.' + base64url(hmac_sha256(...)).
 * The server holds no server-side session (no /tmp, no file locks, shared-
 * hosting-friendly). Payload carries the user ID (`uid`), the user's
 * token version (`v`) and the exp/iat/lact claims below. Revocation is not
 * a session table: logout (and similar events) bump `users.token_version`,
 * and AuthNMiddleware discards any session whose `v` no longer matches —
 * invalidating every outstanding cookie of that user at once.
 *
 * Constant-time: HMAC verification via hash_equals (timing-attack resistant).
 */
final readonly class SessionService
{
    /**
     * The cookie-prefix rules (RFC 6265bis) require `__Host-` to carry NO
     * Domain attribute (self-host: always host-only) and `__Secure-` to
     * allow one (multi-tenant hosting: `Domain=<app host>`) — see
     * cookieName(). Both prefixes ALSO require the Secure attribute; browsers
     * (Chromium confirmed) silently refuse to store a prefixed cookie without
     * it, so a non-HTTPS deployment ($secure === false: local dev, the E2E
     * suite) cannot use either prefix at all — cookieName() falls back to the
     * plain PRE_HARDENING_COOKIE_NAME there. On a secure deployment,
     * PRE_HARDENING_COOKIE_NAME is kept solely to actively expire stale
     * browser cookies from before this rename (withPreHardeningCookieCleared()).
     */
    private const PRE_HARDENING_COOKIE_NAME = 'votepit_sess';

    private const HOST_COOKIE_NAME   = '__Host-votepit_sess';
    private const SECURE_COOKIE_NAME = '__Secure-votepit_sess';

    /**
     * Domain-separation prefix for the signing HMAC (V9.2.2) — mirrors the
     * `totp_setup:` pattern in TotpSetupToken. A session-cookie MAC input can
     * structurally never verify as a CSRF-cookie MAC input (CsrfService uses
     * its own `csrf:` prefix), even though both use the same base64url+
     * HMAC-SHA256 cookie scheme and, in tests, sometimes the same app_key.
     */
    private const HMAC_PREFIX = 'session:';

    /** Payload claim: absolute expiry (unix seconds). Enforced server-side in verify(). */
    public const CLAIM_EXPIRES = 'exp';

    /** Payload claim: issue time (unix seconds) — diagnostic, not enforced. */
    public const CLAIM_ISSUED_AT = 'iat';

    /**
     * Payload claim: rolling last-activity timestamp (unix seconds).
     * Enforced server-side in verify() against $inactivityWindow,
     * independent of the absolute CLAIM_EXPIRES lifetime (CON.10.A3,
     * ASVS V7.3.1) — AuthNMiddleware refreshes it on use.
     */
    public const CLAIM_LAST_ACTIVE = 'lact';

    /**
     * @param ?string $cookieDomain Domain attribute of the Set-Cookie header
     *                              (cloud path routing). Null/unset
     *                              (default, self-host) = no Domain attribute,
     *                              cookie is host-only. A multi-tenant deployment
     *                              sets its app host (Config::sessionCookieDomain).
     * @param int $inactivityWindow Seconds of inactivity (no CLAIM_LAST_ACTIVE
     *                              refresh) after which a session is treated as
     *                              expired, even if CLAIM_EXPIRES lies in the
     *                              future (see verify()). Default 14 days —
     *                              a NIST 800-63B-informed tradeoff between
     *                              re-authentication and convenience.
     */
    public function __construct(
        private string $appKey,
        private int $lifetime,
        private bool $secure,
        private ?string $cookieDomain = null,
        private int $inactivityWindow = 60 * 60 * 24 * 14,
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
        if (!isset($payload[self::CLAIM_LAST_ACTIVE])) {
            $payload[self::CLAIM_LAST_ACTIVE] = $now;
        }
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $mac  = rtrim(strtr(base64_encode(hash_hmac('sha256', self::HMAC_PREFIX . $body, $this->appKey, true)), '+/', '-_'), '=');
        return $body . '.' . $mac;
    }

    /**
     * Verifies a cookie value; returns the payload or null.
     *
     * Fail-secure: a payload without a numeric `exp`, or whose `exp` lies in
     * the past, is treated exactly like a bad MAC (null = anonymous). Same
     * treatment for `lact` (CLAIM_LAST_ACTIVE) against $inactivityWindow —
     * a rolling inactivity timeout independent of the absolute `exp`
     * lifetime (CON.10.A3, ASVS V7.3.1). AuthNMiddleware refreshes `lact`
     * on use, so an actively-used session never trips this even close to
     * its 30-day `exp`.
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
        $lastActive = $payload[self::CLAIM_LAST_ACTIVE] ?? null;
        if (!is_int($lastActive) || (time() - $lastActive) > $this->inactivityWindow) {
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
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', self::HMAC_PREFIX . $body, $this->appKey, true)), '+/', '-_'), '=');
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
            $this->cookieName() . '=' . $this->sign($payload)
            . '; Path=/; HttpOnly; SameSite=Strict'
            . $this->domainAttribute()
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=' . $this->lifetime
        );
        // Not secure: cookieName() IS the pre-hardening name, so clearing it
        // here would immediately delete the cookie just set above.
        if (!$this->secure) {
            return $response;
        }
        $response = $this->withLegacyHostOnlyCookieCleared($response);
        return $this->withPreHardeningCookieCleared($response);
    }

    /** Deletes the session cookie (logout). */
    public function clear(ResponseInterface $response): ResponseInterface
    {
        $response = $response->withHeader(
            'Set-Cookie',
            $this->cookieName() . '=; Path=/; HttpOnly; SameSite=Strict'
            . $this->domainAttribute()
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=0'
        );
        if (!$this->secure) {
            return $response;
        }
        $response = $this->withLegacyHostOnlyCookieCleared($response);
        return $this->withPreHardeningCookieCleared($response);
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
            $this->cookieName() . '=; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=0'
        );
    }

    /**
     * Actively expires the pre-`__Host-`/`__Secure-` plain cookie name
     * (V3.3.1/V3.3.3 remediation, see PRE_HARDENING_COOKIE_NAME doc) — a
     * one-time cleanup so a browser that already holds the old-named cookie
     * from before this rename doesn't keep it around forever unused.
     * Unconditional (both self-host and cloud): the OLD deployment never
     * distinguished cookie name by domain, so either could have issued it.
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

    /** Domain attribute fragment (empty = host-only, self-host default). */
    private function domainAttribute(): string
    {
        return $this->cookieDomain !== null && $this->cookieDomain !== ''
            ? '; Domain=' . $this->cookieDomain
            : '';
    }

    /**
     * `__Host-` (self-host, never a Domain attribute) or `__Secure-` (cloud,
     * Domain=<app host>) — see RFC 6265bis cookie-prefix rules in the
     * class doc above CLAIM constants. Neither prefix is valid without
     * Secure, so a non-HTTPS deployment falls back to the plain
     * PRE_HARDENING_COOKIE_NAME regardless of cookieDomain.
     */
    public function cookieName(): string
    {
        if (!$this->secure) {
            return self::PRE_HARDENING_COOKIE_NAME;
        }
        return $this->cookieDomain !== null && $this->cookieDomain !== ''
            ? self::SECURE_COOKIE_NAME
            : self::HOST_COOKIE_NAME;
    }
}
