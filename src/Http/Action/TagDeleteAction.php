<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\TagRepository;

/**
 * POST /{board}/tags/{id}/delete — delete a board-scoped tag.
 *
 * AuthZ: accountModerate (owner|admin|moderator, anon → 401, missing
 * account role → 403); CSRF globally enforced.
 *
 * Structurally board-scoped: the tag is deleted via a board-scoped WHERE
 * clause (id AND board_id) — unknown id or tag outside the board → 404, no
 * mutation. Cascades to idea_tag_assignments via FK ON DELETE CASCADE — every
 * idea that had this tag simply loses it, no separate cleanup step needed.
 *
 * Always responds JSON { ok: true } (status 200) on success.
 */
final readonly class TagDeleteAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private TagRepository $tagRepo,
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

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $tagId   = (int) ($args['id'] ?? 0);

        $deleted = $this->tagRepo->delete($boardId, $tagId);
        if (!$deleted) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Tag not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('tag.deleted', [
            'board_id' => $boardId,
            'tag_id'   => $tagId,
            'actor_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
