<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Security\SessionService;

/**
 * Reads the session cookie, verifies the HMAC signature, and stores the
 * payload (as well as the user ID) as request attributes.
 *
 * Without a login the session is always null. The infrastructure is in
 * place for once a magic link creates a session.
 *
 * Reads every same-named cookie value straight from the raw `Cookie` header
 * instead of `getCookieParams()` (live-debugged on staging, 2026-09-05): a
 * browser that holds a duplicate `votepit_sess` — a pre-cloud-migration
 * host-only cookie alongside the current Domain-scoped one, or simply two
 * Domain-scoped cookies left over from testing two different accounts in
 * the same browser — sends BOTH in one Cookie header. PSR-7's cookie
 * parsing (and PHP's own $_COOKIE) collapses same-named duplicates down to
 * ONE value; which one survives depends on parsing/traversal order, not on
 * which one is actually valid, so a stale duplicate can silently shadow a
 * perfectly good fresh session.
 *
 * All verified candidates are kept (newest `iat` first, ATTR_SESSION_CANDIDATES)
 * instead of just the first one that verifies its signature: a browser
 * sends duplicate cookies oldest-first (RFC 6265 §5.4), so "first to verify"
 * previously meant a stale foreign session could permanently shadow a
 * freshly issued one. AuthNMiddleware walks the candidates and picks the
 * first that also hydrates to a live user (matching token_version), so a
 * newest-but-since-logged-out session still falls through to an older
 * still-valid one rather than bouncing the request to anonymous. This
 * doesn't depend on ever successfully expiring the stale cookie client-side
 * (SessionService's legacy-cookie clearing on issue()/clear() remains as
 * best-effort hygiene, but this is what actually makes an existing
 * duplicate harmless).
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const ATTR_SESSION = 'session';
    public const ATTR_USER_ID = 'user_id';
    public const ATTR_SESSION_CANDIDATES = 'session_candidates';

    public function __construct(private SessionService $sessions) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $candidates = [];
        foreach ($this->cookieValues($request, $this->sessions->cookieName()) as $candidate) {
            $payload = $this->sessions->verify($candidate);
            if ($payload !== null) {
                $candidates[] = $payload;
            }
        }

        // Newest first: a duplicate cookie is virtually always a stale
        // leftover, never a legitimately newer session than the most
        // recently issued one, so ordering by `iat` descending is a safe
        // resolution rule regardless of *why* the browser is holding more
        // than one. Stable sort (PHP 8 usort) keeps equal-iat candidates in
        // the order they were read.
        usort(
            $candidates,
            static fn (array $a, array $b): int => (int) ($b[SessionService::CLAIM_ISSUED_AT] ?? 0)
                <=> (int) ($a[SessionService::CLAIM_ISSUED_AT] ?? 0),
        );

        $payload = $candidates[0] ?? null;

        $request = $request
            ->withAttribute(self::ATTR_SESSION, $payload)
            ->withAttribute(self::ATTR_USER_ID, $payload['uid'] ?? null)
            ->withAttribute(self::ATTR_SESSION_CANDIDATES, $candidates);

        return $handler->handle($request);
    }

    /**
     * Every raw value of a same-named cookie in the request's Cookie header,
     * in the order the browser sent them. Falls back to the single
     * PSR-7-parsed value if the raw header is unavailable (defensive; every
     * real HTTP request carries the header it was parsed from).
     *
     * @return list<string>
     */
    private function cookieValues(ServerRequestInterface $request, string $name): array
    {
        $header = $request->getHeaderLine('Cookie');
        if ($header === '') {
            $fallback = $request->getCookieParams()[$name] ?? null;
            return is_string($fallback) ? [$fallback] : [];
        }

        $values = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            $eq   = strpos($part, '=');
            if ($eq === false || substr($part, 0, $eq) !== $name) {
                continue;
            }
            $values[] = substr($part, $eq + 1);
        }

        return $values;
    }
}
