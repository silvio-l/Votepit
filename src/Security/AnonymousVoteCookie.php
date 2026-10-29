<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The anonymous voter's dedup cookie (ADR 0004).
 *
 * Unlike SessionService, this cookie carries NO signed payload — its raw
 * value IS the voter token, and the server never needs to verify its
 * integrity beyond re-hashing it (AnonymousVoterHasher::hashToken()) and
 * comparing against votes.voter_token_hash. An attacker who steals the
 * cookie can replay it as that anon identity (same trust level as any
 * other unauthenticated-write cookie); an attacker without it cannot forge
 * a value that hashes to an existing DB row without the server key. No
 * separate HMAC-signed envelope is needed for a value this low-stakes
 * (it grants "may vote as this anonymous identity", nothing else).
 *
 * Strictly purpose-limited (one cookie, one purpose: vote deduplication on
 * this installation) and first-party — no cross-site use, so it is exempt
 * from Germany's TDDDG §25(2) No.2 consent requirement ("technically
 * necessary to provide a service the user explicitly requested" — here:
 * casting exactly this one vote once).
 *
 * Host-only (no Domain attribute), same as SessionService's self-host
 * default — deliberately NOT Domain-scoped to the cloud app host: unlike
 * the login session (one identity across every account a user belongs to,
 * ADR 0001 §2c), an anonymous voter has no cross-account identity to
 * preserve, and every dedup check is already server-side account/board-
 * scoped via (idea_id, voter_token_hash) — Path/Domain scoping the cookie
 * itself would add complexity without a matching security benefit.
 */
final readonly class AnonymousVoteCookie
{
    public const COOKIE_NAME = 'vp_anon_voter';

    /** ~1 year — long-lived on purpose (this is a dedup convenience, not a session). */
    private const MAX_AGE = 31_536_000;

    public function __construct(private bool $secure) {}

    /** The raw token from the request's cookie, or null if absent/empty. */
    public function read(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[self::COOKIE_NAME] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Issues (or re-issues) the cookie with $rawToken as its value. */
    public function issue(ResponseInterface $response, string $rawToken): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=' . $rawToken
            . '; Path=/; HttpOnly; SameSite=Lax'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=' . self::MAX_AGE,
        );
    }
}
