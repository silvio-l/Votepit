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
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const ATTR_SESSION = 'session';
    public const ATTR_USER_ID = 'user_id';

    public function __construct(private SessionService $sessions) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $cookie  = $cookies[$this->sessions->cookieName()] ?? null;
        $payload = $this->sessions->verify(is_string($cookie) ? $cookie : null);

        $request = $request
            ->withAttribute(self::ATTR_SESSION, $payload)
            ->withAttribute(self::ATTR_USER_ID, $payload['uid'] ?? null);

        return $handler->handle($request);
    }
}
