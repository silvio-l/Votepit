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
use Votepit\Security\BrandingValidator;

/**
 * POST /{board}/tags/{id} — rename/recolor a board-scoped tag.
 *
 * AuthZ: accountModerate (owner|admin|moderator, anon → 401, missing
 * account role → 403); CSRF globally enforced.
 *
 * Structurally board-scoped: the tag is loaded board-scoped via
 * findInBoard() — unknown id or tag outside the board → 404 (no
 * cross-board leak, no mutation).
 *
 * Input: `name` (required, trimmed, 1..TagRepository::NAME_MAX_LENGTH
 * chars), `color` (optional hex, defaults to the tag's current color when
 * omitted). A collision with another tag's name in the same board → 422.
 *
 * Always responds JSON { ok: true, tag: {id, name, color} } (status 200) on
 * success.
 */
final readonly class TagUpdateAction
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

        $tag = $this->tagRepo->findInBoard($boardId, $tagId);
        if ($tag === null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Tag not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];

        $name = mb_substr(trim((string) ($fields['name'] ?? '')), 0, TagRepository::NAME_MAX_LENGTH, 'UTF-8');
        if ($name === '') {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['name' => 'The tag name must not be empty.'],
                ],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $rawColor = is_string($fields['color'] ?? null) ? $fields['color'] : '';
        $color    = $rawColor !== '' ? BrandingValidator::color($rawColor) : $tag['color'];
        if ($color === null) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['color' => 'Invalid color format.'],
                ],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $result = $this->tagRepo->rename($boardId, $tagId, $name, $color);
        if ($result === null) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['name' => 'A tag with this name already exists.'],
                ],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        if ($result === false) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Tag not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('tag.updated', [
            'board_id' => $boardId,
            'tag_id'   => $tagId,
            'actor_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'color' => $color]]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
