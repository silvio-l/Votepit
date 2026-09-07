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
use Votepit\Persistence\VoteRepository;

/**
 * POST /{board}/ideas/{id}/vote — cast / change / retract a vote.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory; anon → 401).
 * CSRF: globally enforced (CsrfMiddleware on the POST path).
 * BlockCheck: global (blocked user → 403, before the action runs).
 * RateLimit: perAction('idea:vote') in AppFactory.
 *
 * Board-scoping is structural: the idea is loaded board-scoped via findInBoard() —
 * unknown slug or idea outside the board → 404 (no cross-board leak,
 * no vote row is created).
 *
 * Input `value` ∈ {up,down} (resp. +1/-1); other values → 422, no mutation.
 *
 * Always responds with JSON { score, my_vote, up_count, down_count }, status 200.
 * Goes through the same middleware pipeline (AuthZ, CSRF, BlockCheck, RateLimit).
 *
 * Board-scoped user block: a thin inline guard (no
 * central middleware), the board is already loaded at this point.
 * Runs additively to the account-wide check (BlockCheckMiddleware,
 * already run before the action).
 */
final readonly class VoteAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteRepository $voteRepo,
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

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);
        $idea    = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write('Idea not found.');

            return $response->withStatus(404);
        }

        $value = $this->parseValue($request);
        if ($value === null) {
            $response->getBody()->write('Invalid vote value.');

            return $response->withStatus(422);
        }

        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        if ($this->blockRepo->isBlocked($accountId, $userId, $boardId)) {
            $response->getBody()->write('Blocked.');

            return $response->withStatus(403);
        }

        $result = $this->voteRepo->cast($boardId, $ideaId, $userId, $value);

        // Masked audit: board/idea ID, direction, result — no PII.
        $this->audit->log('vote.cast', [
            'board_id'  => $boardId,
            'idea_id'   => $ideaId,
            'direction' => $value > 0 ? 'up' : 'down',
            'result'    => $result['my_vote'],
        ]);

        $json = (string) json_encode([
            'score'      => $result['score'],
            'my_vote'    => $result['my_vote'],
            'up_count'   => $result['up_count'],
            'down_count' => $result['down_count'],
        ]);
        $response->getBody()->write($json);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Strictly validates the `value` field: only {up,+1,1} → 1, {down,-1} → -1.
     * Anything else → null (action responds with 422, no mutation).
     */
    private function parseValue(ServerRequestInterface $request): ?int
    {
        $parsed = $request->getParsedBody();
        $raw    = is_array($parsed) ? (string) ($parsed['value'] ?? '') : '';

        return match ($raw) {
            'up', '1', '+1' => 1,
            'down', '-1'    => -1,
            default         => null,
        };
    }
}
