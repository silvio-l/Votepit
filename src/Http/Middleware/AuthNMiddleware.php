<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Persistence\UserRepository;

/**
 * AuthN: hydratisiert den authentifizierten Nutzer anhand der Session-User-ID.
 *
 * Ohne Login (Session = null) bleibt der User null. Bei vorhandener uid wird der
 * User-Datensatz via UserRepository::findById geladen (inkl. is_admin /
 * is_blocked / token_version); fehlt der Datensatz, wird die Session verworfen
 * (User bleibt null — fail-secure). Das Request-Attribut ATTR_USER ist die
 * einzige Stelle, aus der Action-Handler die Identität beziehen — Client-Signale
 * sind nie vertrauenswürdig (Zero-Trust).
 *
 * Ohne UserRepository (DB-loser Smoke-Test) entfällt die Hydratation; der User
 * bleibt null. Revokationsprüfung (Issue 04): Session-payload.v muss
 * users.token_version matchen — sonst wird die Session verworfen (revoziert).
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
            $loaded = $this->users->findById($uid); // null bei fehlendem Datensatz → fail-secure

            if (is_array($loaded)) {
                // Revokationsprüfung: v aus der Session-Payload muss token_version in DB matchen.
                // Mismatch → Session revoziert (z. B. nach Logout), user bleibt null.
                $sessionV = is_array($session) ? (int) ($session['v'] ?? -1) : -1;
                if ($sessionV === (int) $loaded['token_version']) {
                    $user = $loaded;
                }
            }
        }

        return $handler->handle($request->withAttribute(self::ATTR_USER, $user));
    }
}
