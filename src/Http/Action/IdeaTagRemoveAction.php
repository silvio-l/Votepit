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
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;

/**
 * POST /{board}/ideas/{id}/tags/{tagId}/remove — remove a tag from an idea.
 *
 * AuthZ: accountModerate (owner|admin|moderator, anon → 401, missing
 * account role → 403); CSRF globally enforced.
 *
 * Structurally board-scoped: both the idea and the tag are loaded
 * board-scoped first — either one belonging to a foreign board → 404, no
 * mutation. Removing a tag that was never assigned is an idempotent no-op.
 *
 * Always responds JSON { ok: true, tags: [...] } (status 200) — the full,
 * current tag list of the idea.
 */
final readonly class IdeaTagRemoveAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
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
        $ideaId  = (int) ($args['id'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $tagId = (int) ($args['tagId'] ?? 0);
        $tag   = $this->tagRepo->findInBoard($boardId, $tagId);
        if ($tag === null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Tag not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $this->tagRepo->removeFromIdea($ideaId, $tagId);

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('idea.tag.removed', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'tag_id'   => $tagId,
            'actor_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'tags' => $this->tagRepo->tagsForIdea($ideaId)]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
