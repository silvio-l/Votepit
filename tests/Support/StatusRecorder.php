<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that lets the inner handler run and records the resulting
 * status code — the "observer" shape of AppExtension::routeMiddleware()
 * (see what core answered on the way out, without changing it).
 */
final class StatusRecorder implements MiddlewareInterface
{
    /** @var list<int> */
    public array $statuses = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response         = $handler->handle($request);
        $this->statuses[] = $response->getStatusCode();

        return $response;
    }
}
