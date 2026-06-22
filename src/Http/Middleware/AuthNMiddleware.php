<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * AuthN: hydratisiert den authentifizierten Nutzer anhand der Session-User-ID.
 *
 * Sprint 0 (Gerüst): ohne Login (Session = null) ist der User immer null.
 * Sprint 2+ erweitert diese Middleware um die UserRepository-Hydratation
 * (Nutzung von ATTR_USER_ID → laden des User-Datensatzes, inkl. is_admin /
 * is_blocked). Das Request-Attribut ATTR_USER ist die einzige Stelle, aus der
 * Action-Handler die Identität beziehen — Client-Signale sind nie vertrauens-
 * würdig (Zero-Trust).
 */
final class AuthNMiddleware implements MiddlewareInterface
{
    public const ATTR_USER = 'user';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $request->getAttribute(SessionMiddleware::ATTR_USER_ID);

        // Sprint 0: keine User-Hydratation (Repository folgt in Sprint 2/3).
        // Wenn keine Session → User bleibt null. Eine später gesetzte user_id
        // würde hier um den DBAL-Lookup ergänzt werden.
        $user = null;

        return $handler->handle($request->withAttribute(self::ATTR_USER, $user));
    }
}
