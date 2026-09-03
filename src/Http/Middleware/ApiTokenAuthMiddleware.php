<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Security\ApiTokenAuthenticator;

/**
 * Bearer-token AuthN+AuthZ for the Agent API (Agent API / Votepit MCP).
 * Deny-by-default: every route carrying this middleware requires a valid
 * `Authorization: Bearer <token>` header — missing/invalid/revoked → 401,
 * NO fallback to the session (deliberately separate path from
 * AuthZMiddleware, see the class doc there: session roles and bearer
 * tokens are different trust models, no shared composition point needed).
 *
 * On success it attaches the resolved scope as request attributes —
 * ATTR_ACCOUNT_ID reuses the same contract as AccountContextMiddleware (the
 * single chokepoint from which board lookups derive their account context),
 * ATTR_BOARD_ID and ATTR_TOKEN are new. A board is therefore NEVER resolved
 * from a path parameter, only from the token itself — a token can
 * structurally never read/write a board other than its own.
 *
 * A subsequent MCP resource wrapper reads the same contract
 * (ATTR_ACCOUNT_ID / ATTR_BOARD_ID / ATTR_TOKEN) and can attach this
 * middleware unchanged ahead of its own handlers.
 */
final readonly class ApiTokenAuthMiddleware implements MiddlewareInterface
{
    /** Request attribute name; value shape: array{account_id: int, board_id: int, token_id: int, created_by_user_id: int, label: string} */
    public const ATTR_TOKEN = 'api_token';

    public const ATTR_BOARD_ID = 'api_token_board_id';

    public function __construct(
        private ApiTokenAuthenticator $authenticator,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $scope = $this->authenticator->resolve($request);

        if ($scope === null) {
            $response = $this->responseFactory->createResponse(401);
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'unauthorized', 'message' => 'A valid bearer token is required.'],
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $request = $request
            ->withAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID, $scope['account_id'])
            ->withAttribute(self::ATTR_BOARD_ID, $scope['board_id'])
            ->withAttribute(self::ATTR_TOKEN, $scope);

        return $handler->handle($request);
    }
}
