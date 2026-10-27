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
use Votepit\Persistence\CommentReactionRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * POST /{board}/ideas/{id}/comments/{commentId}/react — toggle an emoji
 * reaction on a comment.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory; anon → 401).
 * CSRF: globally enforced (CsrfMiddleware on the POST path).
 * BlockCheck: global (blocked user → 403, before the action runs).
 * RateLimit: perAction('comment:react') in AppFactory.
 *
 * Structurally board-/idea-/comment-scoped: the idea is loaded board-scoped
 * via IdeaRepository::findInBoard() and the comment idea-scoped via
 * CommentRepository::findForIdea() — a comment of a foreign idea/board is
 * never addressable (404, no mutation), the same pattern as
 * CommentModerationAction/CommentUpdateAction.
 *
 * Input `reaction` MUST be one of
 * CommentReactionRepository::ALLOWED_REACTIONS — a fixed, small,
 * predefined emoji set (Geteilte-Origin-Invariante: no user-controlled
 * active content, no free-text emoji). Anything else → 422, no mutation.
 *
 * One active reaction per (comment, user) — mirrors the idea-vote pattern
 * (VoteRepository::cast / VoteAction): reacting with the SAME emoji again
 * retracts it, a DIFFERENT emoji switches it in place (no second row).
 *
 * Board-scoped user block: a thin inline guard (no central middleware,
 * board already loaded here), the same pattern as VoteAction/
 * CommentCreateAction — additive to the account-wide BlockCheckMiddleware
 * that already ran before the action.
 *
 * Always responds with JSON { my_reaction, counts }, status 200.
 */
final readonly class CommentReactionAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private CommentReactionRepository $reactionRepo,
        private AuditLogger $audit,
        private BlockRepository $blockRepo,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->jsonError($response, 404, 'not_found', 'Board not found.');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId   = (int) $board['id'];
        $ideaId    = (int) ($args['id'] ?? 0);
        $commentId = (int) ($args['commentId'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            return $this->jsonError($response, 404, 'not_found', 'Idea not found.');
        }

        $comment = $this->commentRepo->findForIdea($ideaId, $commentId);
        if (!is_array($comment)) {
            return $this->jsonError($response, 404, 'not_found', 'Comment not found.');
        }

        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        if ($this->blockRepo->isBlocked($accountId, $userId, $boardId)) {
            return $this->jsonError($response, 403, 'blocked', 'You are blocked from this board.');
        }

        $parsed   = $request->getParsedBody();
        $reaction = is_array($parsed) ? (string) ($parsed['reaction'] ?? '') : '';
        if (!$this->reactionRepo->isAllowed($reaction)) {
            return $this->jsonError($response, 422, 'validation_error', 'Invalid reaction.');
        }

        $result = $this->reactionRepo->toggle($commentId, $userId, $reaction);

        // Masked audit: board/idea/comment ID, reaction, result — no PII.
        $this->audit->log('comment.reaction_toggled', [
            'board_id'    => $boardId,
            'idea_id'     => $ideaId,
            'comment_id'  => $commentId,
            'reaction'    => $reaction,
            'my_reaction' => $result['my_reaction'],
        ]);

        $response->getBody()->write((string) json_encode([
            'my_reaction' => $result['my_reaction'],
            'counts'      => $result['counts'],
        ]));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
