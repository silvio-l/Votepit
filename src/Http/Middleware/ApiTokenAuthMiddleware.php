<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\ApiTokenAuthenticator;

/**
 * Bearer-token AuthN+AuthZ for the Agent API (Agent API / Votepit MCP).
 * Deny-by-default: every route carrying this middleware requires a valid
 * `Authorization: Bearer <token>` header — missing/invalid/revoked → 401,
 * NO fallback to the session (deliberately separate path from
 * AuthZMiddleware, see the class doc there: session roles and bearer
 * tokens are different trust models, no shared composition point needed).
 *
 * A token grants access to a SET of boards within one account, each at its
 * OWN 'read'|'write' scope (see migrations 0044 + 0047 — a token can write
 * on one board and only read another). This middleware resolves the single
 * "active board for this request" from an optional `?board=<slug>` query
 * parameter (must be one of the token's granted boards, else 403
 * board_not_granted) — or, when omitted, from the token's sole granted
 * board (400 board_required when the token has more than one and no slug
 * was given). The resolved id is attached as ATTR_BOARD_ID, unchanged for
 * downstream consumers (ApiIdeaAction/ApiBoardAction/McpAction never need to
 * know about multi-board grants). ATTR_ACCOUNT_ID reuses the same contract
 * as AccountContextMiddleware; ATTR_TOKEN carries the resolved grant with
 * `scope` narrowed to the EFFECTIVE scope for the resolved board (not the
 * whole token) — downstream scope checks in ApiIdeaAction::create()/
 * McpAction stay a single `$token['scope'] !== 'write'` comparison, unaware
 * that the underlying grant is per-board.
 */
final readonly class ApiTokenAuthMiddleware implements MiddlewareInterface
{
    /** Request attribute name; value shape: array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string} — `scope` is the resolved board's own scope, see class doc. */
    public const ATTR_TOKEN = 'api_token';

    public const ATTR_BOARD_ID = 'api_token_board_id';

    public function __construct(
        private ApiTokenAuthenticator $authenticator,
        private BoardRepository $boards,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $grant = $this->authenticator->resolve($request);

        if ($grant === null) {
            return $this->errorResponse(401, 'unauthorized', 'A valid bearer token is required.');
        }

        $boardId = $this->resolveActiveBoardId($request, $grant);
        if ($boardId instanceof ResponseInterface) {
            return $boardId;
        }

        $token = [
            'account_id'         => $grant['account_id'],
            'scope'              => $grant['board_scopes'][$boardId],
            'token_id'           => $grant['token_id'],
            'created_by_user_id' => $grant['created_by_user_id'],
            'label'              => $grant['label'],
        ];

        $request = $request
            ->withAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID, $grant['account_id'])
            ->withAttribute(self::ATTR_BOARD_ID, $boardId)
            ->withAttribute(self::ATTR_TOKEN, $token);

        return $handler->handle($request);
    }

    /**
     * @param array{account_id: int, board_scopes: array<int, string>, token_id: int, created_by_user_id: int, label: string} $grant
     */
    private function resolveActiveBoardId(ServerRequestInterface $request, array $grant): int|ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $requestedSlug = $queryParams['board'] ?? null;
        $grantedBoardIds = array_keys($grant['board_scopes']);

        if (is_string($requestedSlug) && $requestedSlug !== '') {
            $board = $this->boards->findBySlugForAccount($requestedSlug, $grant['account_id']);
            if ($board === null || !array_key_exists((int) $board['id'], $grant['board_scopes'])) {
                return $this->errorResponse(403, 'board_not_granted', 'This token is not authorized for the requested board.');
            }
            return (int) $board['id'];
        }

        if (count($grantedBoardIds) === 1) {
            return $grantedBoardIds[0];
        }

        return $this->errorResponse(400, 'board_required', 'This token grants access to multiple boards — specify one via ?board=<slug>.');
    }

    private function errorResponse(int $status, string $key, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
