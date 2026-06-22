<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Security\SessionService;

/**
 * Liest das Session-Cookie, verifiziert die HMAC-Signatur und legt die Payload
 * (sowie die Nutzer-ID) als Request-Attribute ab.
 *
 * Sprint 0 (Gerüst): ohne Login ist die Session immer null. Die Infrastruktur
 * steht für Sprint 2 (Magic-Link erzeugt eine Session).
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public const ATTR_SESSION = 'session';
    public const ATTR_USER_ID = 'user_id';

    public function __construct(private readonly SessionService $sessions) {}

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
