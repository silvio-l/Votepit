<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * POST /{board}/ideas/{id}/comments/{commentId}/delete — remove a comment
 * (admin-only moderation).
 *
 * AuthZ: accountAdmin (via AuthZMiddleware::accountAdmin() in AppFactory;
 * anon → 401, missing account role → 403).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() — unknown slug or idea outside the board
 * → 404 (no cross-board leak, no mutation). The comment is additionally
 * loaded idea-scoped via CommentRepository::findForIdea() — a comment of
 * a foreign idea is never addressable (404, analogous to
 * IdeaPinAction/IdeaStatusAction).
 *
 * Hard delete (no soft hide — the roadmap lists "delete/hide" as
 * equivalent options; hard delete mirrors the existing
 * IdeaWithdrawAction precedent and keeps the schema unchanged).
 *
 * Always responds JSON { ok: true } (status 200).
 */
final readonly class CommentModerationAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private AuditLogger $audit,
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
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $boardId   = (int) $board['id'];
        $ideaId    = (int) ($args['id'] ?? 0);
        $commentId = (int) ($args['commentId'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $comment = $this->commentRepo->findForIdea($ideaId, $commentId);
        if (!is_array($comment)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Comment not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $this->commentRepo->delete($ideaId, $commentId);

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('comment.moderated_delete', [
            'board_id'   => $boardId,
            'idea_id'    => $ideaId,
            'comment_id' => $commentId,
            'actor_id'   => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
