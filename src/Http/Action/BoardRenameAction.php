<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\SlugInvalidReason;
use Votepit\Security\SlugValidator;

/**
 * PUT /admin/boards/{slug} — renames a board's title and/or slug (AuthZ:
 * accountAdmin, board-scoped via the current slug in the path, CSRF
 * globally enforced).
 *
 * Title and slug are independent: the request may include either field,
 * both, or neither (an omitted field keeps its current value — a no-op for
 * that field, not an error; an explicitly empty value IS a validation
 * error, same as BoardCreateAction). The same title may be reused across
 * different slugs — there is no uniqueness constraint on `name`, only on
 * `slug` (per account).
 *
 * Slug validation (format via SlugValidator, collision within the same
 * account, tombstone cooldown) mirrors BoardCreateAction exactly — see
 * BoardRepository::renameBoard() for the tombstone/collision persistence
 * details. A frozen (downgrade-reconciled) board rejects this write with
 * 423, same as every other board-scoped write action
 * (Http\Support\FrozenBoardGuard).
 */
final readonly class BoardRenameAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function rename(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
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

        $currentSlug = (string) $board['slug'];
        $currentName = (string) $board['name'];

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];

        $hasName = array_key_exists('name', $fields);
        $hasSlug = array_key_exists('slug', $fields);

        $newName = $hasName ? trim((string) $fields['name']) : $currentName;
        $newSlug = $hasSlug ? trim((string) $fields['slug']) : $currentSlug;

        $errors = [];
        if ($hasName) {
            if ($newName === '') {
                $errors['name'] = 'The name must not be empty.';
            } elseif (mb_strlen($newName, 'UTF-8') > 128) {
                $errors['name'] = 'The name must be at most 128 characters long.';
            }
        }

        if ($hasSlug) {
            $slugReason = SlugValidator::validate($newSlug);
            if ($slugReason instanceof SlugInvalidReason) {
                $errors['slug'] = $this->slugErrorMessage($slugReason);
            } elseif ($newSlug !== $currentSlug && $this->boardRepo->findBySlugForAccount($newSlug, $accountId) !== null) {
                $errors['slug'] = 'This slug is already taken in your account.';
            }
        }

        if ($errors !== []) {
            return $this->errorResponse($response, $errors);
        }

        $ok = $this->boardRepo->renameBoard((int) $board['id'], $accountId, $newSlug, $newName);
        if (!$ok) {
            // Race/tombstone backstop caught at persistence time (e.g. a
            // concurrent request just took the same slug, or it just cooled
            // down into a tombstone) — same generic message as
            // BoardCreateAction, no tombstone-existence leak to the caller.
            return $this->errorResponse($response, [
                'slug' => 'This slug is already taken in your account.',
            ]);
        }

        $this->audit->log('board.renamed', [
            'board_id'  => (int) $board['id'],
            'old_slug'  => $currentSlug,
            'new_slug'  => $newSlug,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'slug' => $newSlug, 'name' => $newName]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, string> $fields */
    private function errorResponse(ResponseInterface $response, array $fields): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'validation_error',
                'message' => 'Validation failed.',
                'fields'  => $fields,
            ],
        ]));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }

    private function slugErrorMessage(SlugInvalidReason $reason): string
    {
        return match ($reason) {
            SlugInvalidReason::InvalidLength => 'The slug must be between 1 and 64 characters long.',
            SlugInvalidReason::InvalidCharacters => 'The slug may only contain lowercase letters, digits and hyphens.',
            SlugInvalidReason::LeadingHyphen => 'The slug must not start with a hyphen.',
            SlugInvalidReason::TrailingHyphen => 'The slug must not end with a hyphen.',
            SlugInvalidReason::DoubleHyphen => 'The slug must not contain consecutive hyphens.',
            SlugInvalidReason::ReservedWord => 'This slug is reserved and cannot be used.',
        };
    }
}
