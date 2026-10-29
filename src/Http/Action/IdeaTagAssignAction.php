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
 * POST /{board}/ideas/{id}/tags — assign a tag to an idea.
 *
 * AuthZ: accountModerate (owner|admin|moderator, anon → 401, missing
 * account role → 403); CSRF globally enforced.
 *
 * Structurally board-scoped: both the idea (IdeaRepository::findInBoard())
 * and the tag (TagRepository::findInBoard()) are loaded board-scoped —
 * either one belonging to a foreign board → 404, no mutation. This also
 * excludes cross-board tag assignment (a tag from board A can never be
 * attached to an idea of board B, even within the same account).
 *
 * Input: `tag_id` (required, positive int). Assigning an already-assigned
 * tag is an idempotent no-op (TagRepository::assignToIdea() — the composite
 * primary key silently absorbs the duplicate).
 *
 * Always responds JSON { ok: true, tags: [...] } (status 200) — the full,
 * current tag list of the idea, so the SPA can update in one round trip.
 */
final readonly class IdeaTagAssignAction
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

        $parsed = $request->getParsedBody();
        $tagId  = is_array($parsed) ? (int) ($parsed['tag_id'] ?? 0) : 0;
        if ($tagId <= 0) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_tag', 'message' => 'Invalid tag id.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $tag = $this->tagRepo->findInBoard($boardId, $tagId);
        if ($tag === null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Tag not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $this->tagRepo->assignToIdea($ideaId, $tagId);

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('idea.tag.assigned', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'tag_id'   => $tagId,
            'actor_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'tags' => $this->tagRepo->tagsForIdea($ideaId)]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
