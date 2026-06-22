<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * BlockCheck: blockierte Nutzer (is_blocked=1) dürfen KEINE Mutation auslösen.
 *
 * Greift nur auf mutierenden Verben (POST/PUT/PATCH/DELETE). Lesen (GET/HEAD/OPTIONS)
 * bleibt erlaubt. Das ist die zentrale Durchgriffs-Sperre aus security.md A01.
 *
 * Sprint 0 (Gerüst): ohne hydratisierten User (Sprint 2) ist ATTR_USER null →
 * BlockCheck ist effektiv noop. Die Logik steht, sobald die User-Hydratation
 * kommt.
 */
final class BlockCheckMiddleware implements MiddlewareInterface
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly ResponseFactoryInterface $responseFactory) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        if (in_array($method, self::MUTATING, true) && is_array($user) && ($user['is_blocked'] ?? false)) {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write('Account blocked.');
            return $response;
        }

        return $handler->handle($request);
    }
}
