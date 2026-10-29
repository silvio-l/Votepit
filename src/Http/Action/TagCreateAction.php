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
 * POST /{board}/tags — create a board-scoped tag.
 *
 * AuthZ: accountModerate (owner|admin|moderator, anon → 401, missing
 * account role → 403); CSRF globally enforced.
 *
 * Board lookup via findBySlugForAccount() (not the public variant — a
 * moderator manages tags regardless of the board's own visibility, same
 * pattern as IdeaPinAction/IdeaStatusAction).
 *
 * Input: `name` (required, trimmed, 1..TagRepository::NAME_MAX_LENGTH chars),
 * `color` (optional hex, BrandingValidator::color() format, defaults to
 * '#3b82f6' when omitted/invalid). A duplicate name within the board → 422.
 *
 * Always responds JSON { ok: true, tag: {id, name, color} } (status 201) on
 * success.
 */
final readonly class TagCreateAction
{
    private const DEFAULT_COLOR = '#3b82f6';

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
        $color    = $rawColor !== '' ? BrandingValidator::color($rawColor) : self::DEFAULT_COLOR;
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

        $boardId = (int) $board['id'];
        $tagId   = $this->tagRepo->create($boardId, $name, $color);
        if ($tagId === null) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['name' => 'A tag with this name already exists.'],
                ],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('tag.created', [
            'board_id' => $boardId,
            'tag_id'   => $tagId,
            'actor_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'color' => $color]]));

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
