<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Persistence\BoardRepository;

/**
 * GET /api/v1/board — board info for the Agent API.
 *
 * AuthZ: Bearer token (ApiTokenAuthMiddleware) — no slug in the path, the board
 * comes exclusively from the resolved token scope (ATTR_BOARD_ID/
 * ATTR_ACCOUNT_ID). A token can structurally never read a board other than
 * the one it was issued for.
 *
 * resolveBoard() is pure domain resolution without HTTP framing — shared
 * with the MCP resource wrapper (`McpAction`), so query logic isn't
 * duplicated between REST and MCP.
 */
final readonly class ApiBoardAction
{
    public function __construct(private BoardRepository $boardRepo) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boardId   = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);

        $board = $this->resolveBoard($accountId, $boardId);
        if ($board === null) {
            // Structurally unreachable (token points to a deleted
            // board) — fail-secure instead of 500.
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode(['board' => $board]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Pure board resolution (no HTTP framing) — used by both __invoke() and
     * the MCP `get_board` tool.
     *
     * @return array{id: int, slug: string, name: string, intro: string}|null
     */
    public function resolveBoard(int $accountId, int $boardId): ?array
    {
        $board = $this->boardRepo->findByIdForAccount($boardId, $accountId);
        if (!is_array($board)) {
            return null;
        }

        return [
            'id'    => (int) $board['id'],
            'slug'  => (string) $board['slug'],
            'name'  => (string) $board['name'],
            'intro' => is_string($board['intro'] ?? null) ? $board['intro'] : '',
        ];
    }
}
