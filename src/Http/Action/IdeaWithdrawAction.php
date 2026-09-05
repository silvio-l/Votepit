<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * POST /{board}/ideas/{id}/withdraw — withdraw an idea (hard delete).
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 *
 * Ownership check in the action (not in the pipeline guard):
 *   - idea not in the board → 404
 *   - idea present but different author → 403
 *   - anonymous → AuthZMiddleware::user() returns 401 (before the action runs)
 *   - blocked user → BlockCheckMiddleware returns 403 (before the action runs)
 *
 * After hard delete: PRG redirect (302) to the board home (idea list).
 *
 * Board-scoped user block — a thin inline guard (no central middleware),
 * the board is already loaded at this point. Runs additively to the
 * accountwide check (BlockCheckMiddleware, already run before the action).
 */
final readonly class IdeaWithdrawAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private AuditLogger $audit,
        private BlockRepository $blockRepo,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write('Board not found.');

            return $response->withStatus(404);
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            $response->getBody()->write('Board is frozen.');

            return $response->withStatus(423);
        }

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write('Idea not found.');

            return $response->withStatus(404);
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write('Forbidden.');

            return $response->withStatus(403);
        }

        $boardId  = (int) $board['id'];
        $authorId = (int) ($user['id'] ?? 0);

        if ($this->blockRepo->isBlocked($accountId, $authorId, $boardId)) {
            $response->getBody()->write('Blocked.');

            return $response->withStatus(403);
        }

        $this->ideaRepo->withdraw($ideaId, $authorId, $boardId);
        $this->audit->log('idea.withdrawn', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
