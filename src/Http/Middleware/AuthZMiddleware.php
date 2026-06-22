<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * AuthZ: Route-Level-Autorisierung (deny-by-default).
 *
 * Jede Route MUSS eine AuthZ-Middleware mit dem geforderten Trust-Level
 * tragen. Konvention (durch Code-Review erzwungen): eine Route ohne AuthZ
 * gilt als Befund.
 *
 *   AuthZ::anon()  — öffentlich (auch ohne Login).
 *   AuthZ::user()  — eingeloggt erforderlich.
 *   AuthZ::admin() — Admin erforderlich (is_admin).
 *
 * Sprint 0: nur Smoke-Routen nutzen 'anon'. 'user'/'admin' greifen, sobald
 * Sprint 2 die User-Hydratation liefert (vorher ist ATTR_USER immer null →
 * 'user'/'admin' weisen konsequent ab).
 */
final class AuthZMiddleware implements MiddlewareInterface
{
    public const LEVEL_ANON  = 'anon';
    public const LEVEL_USER  = 'user';
    public const LEVEL_ADMIN = 'admin';

    private function __construct(
        private readonly string $required,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public static function anon(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_ANON, $rf);
    }

    public static function user(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_USER, $rf);
    }

    public static function admin(ResponseFactoryInterface $rf): self
    {
        return new self(self::LEVEL_ADMIN, $rf);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        if ($this->required === self::LEVEL_ANON) {
            return $handler->handle($request);
        }

        // Ab hier: Login erforderlich.
        if ($user === null) {
            return $this->deny(401);
        }

        if ($this->required === self::LEVEL_ADMIN) {
            // Sprint 2+: is_admin aus dem hydratisierten User. Bis dahin
            // weisen Admin-Routen konsequent ab (User ist in Sprint 0 null).
            $isAdmin = is_array($user) && ($user['is_admin'] ?? false);
            if (!$isAdmin) {
                return $this->deny(403);
            }
        }

        return $handler->handle($request);
    }

    private function deny(int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(self::class . ': access denied.');
        return $response;
    }
}
