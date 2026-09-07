<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Persistence\UserRepository;

/**
 * AuthN: hydrates the authenticated user from the session user ID.
 *
 * Without a login (session = null) the user stays null. When a uid is present,
 * the user record is loaded via UserRepository::findById (including is_admin /
 * is_blocked / token_version); if the record is missing, the session is
 * discarded (user stays null — fail-secure). The request attribute ATTR_USER
 * is the single place from which action handlers obtain identity — client
 * signals are never trusted (zero-trust).
 *
 * Without a UserRepository (DB-less smoke test) hydration is skipped; the user
 * stays null. Revocation check: the session payload's v must match
 * users.token_version — otherwise the session is discarded (revoked).
 *
 * Walks the verified session candidates from SessionMiddleware (newest
 * first), not just the single best-guess one, and hydrates from the first
 * that also passes the token_version check — but ONLY among candidates that
 * share the same uid as the newest one. This matters when a browser holds a
 * duplicate `votepit_sess` FOR THE SAME LOGIN (e.g. a stale cookie left over
 * on a broader domain that a narrower-scoped `clear()` couldn't remove): the
 * newest cookie is normally the right one, but if it was since logged out
 * (revoked token_version) while an older duplicate for that *same* uid is
 * still a live, valid session, "newest wins, otherwise anonymous" would
 * bounce the request to logged-out instead of falling through to that
 * still-valid session — the stuck-in-a-login-loop failure mode this closes.
 *
 * Falling through to a candidate for a *different* uid is deliberately
 * refused: two verified cookies can legitimately belong to two different
 * users (e.g. a previous account's session on a shared browser). Accepting
 * cross-uid fallback would mean "log out / reset password" on the newer
 * account silently re-authenticates the request as the older account
 * instead of going anonymous — a session-fixation-style account takeover.
 * Fail-secure here means: once the newest uid's session is confirmed dead,
 * treat the request as anonymous rather than trying a different identity.
 */
final readonly class AuthNMiddleware implements MiddlewareInterface
{
    public const ATTR_USER = 'user';

    public function __construct(private ?UserRepository $users = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user          = null;
        $matchedSession = null;

        if ($this->users instanceof UserRepository) {
            /** @var list<array<string, mixed>> $candidates */
            $candidates = $request->getAttribute(SessionMiddleware::ATTR_SESSION_CANDIDATES) ?? [];

            $primaryUid = $candidates[0]['uid'] ?? null;

            foreach ($candidates as $session) {
                $uid = $session['uid'] ?? null;
                if (!is_int($uid)) {
                    continue;
                }

                // Only ever fall through within the same identity as the newest
                // candidate — a different uid means "different user", not "same
                // session, different copy of the cookie" (see class doc).
                if ($uid !== $primaryUid) {
                    break;
                }

                $loaded = $this->users->findById($uid); // null if record is missing → fail-secure
                if (!is_array($loaded)) {
                    continue;
                }

                // Revocation check: v from the session payload must match token_version in the DB.
                // Mismatch → session revoked (e.g. after logout); try the next candidate.
                if ((int) ($session['v'] ?? -1) === (int) $loaded['token_version']) {
                    $user           = $loaded;
                    $matchedSession = $session;
                    break;
                }
            }
        }

        // Overwrite SessionMiddleware's best-guess ATTR_SESSION/ATTR_USER_ID
        // with whichever candidate actually got hydrated above (or null, if
        // none did) — the two attributes must never disagree with ATTR_USER
        // about who is logged in. SessionMiddleware itself always sets them
        // to the newest candidate before AuthN's fallback runs, which can
        // legitimately pick an older one instead.
        $request = $request
            ->withAttribute(self::ATTR_USER, $user)
            ->withAttribute(SessionMiddleware::ATTR_SESSION, $matchedSession)
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, $matchedSession['uid'] ?? null);

        return $handler->handle($request);
    }
}
