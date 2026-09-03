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
 */
final readonly class AuthNMiddleware implements MiddlewareInterface
{
    public const ATTR_USER = 'user';

    public function __construct(private ?UserRepository $users = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uid     = $request->getAttribute(SessionMiddleware::ATTR_USER_ID);
        $session = $request->getAttribute(SessionMiddleware::ATTR_SESSION);
        $user    = null;

        if ($this->users instanceof UserRepository && is_int($uid)) {
            $loaded = $this->users->findById($uid); // null if record is missing → fail-secure

            if (is_array($loaded)) {
                // Revocation check: v from the session payload must match token_version in the DB.
                // Mismatch → session revoked (e.g. after logout), user stays null.
                $sessionV = is_array($session) ? (int) ($session['v'] ?? -1) : -1;
                if ($sessionV === (int) $loaded['token_version']) {
                    $user = $loaded;
                }
            }
        }

        return $handler->handle($request->withAttribute(self::ATTR_USER, $user));
    }
}
