<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The guest idea author's device cookie `vp_anon_author` (ADR 0006 §2).
 *
 * Same shape as AnonymousVoteCookie (raw random token as the value, only
 * its account-bound HMAC is stored — AnonymousAuthorHasher), but a separate
 * cookie with a shorter lifetime (180 days, matching the hash retention):
 * one cookie, one purpose ("see/withdraw my own guest ideas on this
 * device"), technically necessary for the explicitly requested service and
 * therefore consent-free under § 25 (2) No. 2 TDDDG.
 *
 * On first issue a valid `vp_anon_voter` value is taken over as the raw
 * token (initialToken()), so a guest who already voted anonymously keeps
 * one device identity; the stored hashes stay unlinkable because both
 * hashers are domain-separated.
 */
final readonly class AnonymousAuthorCookie
{
    public const COOKIE_NAME = 'vp_anon_author';

    /** 180 days. */
    public const MAX_AGE = 15_552_000;

    /** base64url of 32 random bytes (AnonymousVoterHasher::generateToken()); strict so a cookie can never inject header syntax. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(private bool $secure) {}

    /** The raw token, or null when absent or malformed. */
    public function read(ServerRequestInterface $request): ?string
    {
        return self::validToken($request->getCookieParams()[self::COOKIE_NAME] ?? null);
    }

    /** The raw token for a new cookie: a valid voter token is taken over, otherwise a fresh one. */
    public static function initialToken(ServerRequestInterface $request): string
    {
        return self::validToken($request->getCookieParams()[AnonymousVoteCookie::COOKIE_NAME] ?? null)
            ?? AnonymousVoterHasher::generateToken();
    }

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

    private static function validToken(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::TOKEN_PATTERN, $value) === 1 ? $value : null;
    }
}
