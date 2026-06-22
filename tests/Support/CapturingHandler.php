<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Http\Middleware\AuthNMiddleware;

/**
 * Test-Handler, der das von AuthN hydratisierte ATTR_USER festhält.
 * Erlaubt Assertions über die Middleware-Ausgabe an der öffentlichen Seam.
 */
final class CapturingHandler implements RequestHandlerInterface
{
    /** @var array<string, mixed>|null */
    public ?array $seenUser = null;

    public bool $called = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;
        /** @var array<string, mixed>|null $user */
        $user           = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->seenUser = $user;

        return (new ResponseFactory())->createResponse(200);
    }
}
