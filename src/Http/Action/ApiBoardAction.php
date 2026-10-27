<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardStatus;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\TagRepository;

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
 *
 * Besides id/slug/name/intro the board carries what an agent needs to use
 * the write endpoints (competitor-parity issue 15): `statuses` — the board's
 * status registry (owner-defined keys included; the valid values for the
 * status change) — and `tags` (the ids for tag assign/remove). Both are
 * board-visible data, no PII. A default board's registry costs no query.
 */
final readonly class ApiBoardAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private BoardStatusRepository $statusRepo,
        private TagRepository $tagRepo,
    ) {}

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
     * @return array{id: int, slug: string, name: string, intro: string, statuses: list<array{key: string, category: string, label: string, is_hidden: bool, allow_votes: bool, allow_comments: bool}>, tags: list<array{id: int, name: string, color: string}>}|null
     */
    public function resolveBoard(int $accountId, int $boardId): ?array
    {
        $board = $this->boardRepo->findByIdForAccount($boardId, $accountId);
        if (!is_array($board)) {
            return null;
        }

        $registry = $this->statusRepo->registryFor($board);

        return [
            'id'       => (int) $board['id'],
            'slug'     => (string) $board['slug'],
            'name'     => (string) $board['name'],
            'intro'    => is_string($board['intro'] ?? null) ? $board['intro'] : '',
            'statuses' => array_map(static fn (BoardStatus $s): array => [
                'key'            => $s->key,
                'category'       => $s->category,
                'label'          => $registry->label($s->key, 'en'),
                'is_hidden'      => $s->isHidden,
                'allow_votes'    => $s->allowVotes,
                'allow_comments' => $s->allowComments,
            ], $registry->all()),
            'tags'     => $this->tagRepo->listByBoard((int) $board['id']),
        ];
    }
}
