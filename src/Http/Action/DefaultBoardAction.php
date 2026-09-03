<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;

/**
 * GET /api/board/default — returns the slug of the default board for the
 * SPA root route `/` (core/app/src/pages/BoardPage.tsx navigates here when
 * :boardSlug is missing).
 *
 * AuthZ: anon (reading is public, same visibility rule as BoardHomeAction).
 * No public board present (fresh/empty installation) → 404, `no_board`.
 */
final readonly class DefaultBoardAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountMemberRepository $accountMembers,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $viewerIsMember = $userId > 0 && $this->accountMembers->roleFor($accountId, $userId) !== null;

        $board = $this->boardRepo->findDefaultPublicForAccount($accountId, $viewerIsMember);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'no_board', 'message' => 'No public board available.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode([
            'slug' => (string) $board['slug'],
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
