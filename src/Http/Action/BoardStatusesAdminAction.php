<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardStatus;
use Votepit\Domain\BoardStatuses;
use Votepit\Domain\StatusLabel;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;

/**
 * Owner-defined statuses of a board (broaden-use-cases issue 03,
 * migrations/0073_create_board_statuses.sql).
 *
 *   GET  /admin/boards/{slug}/statuses              — the registry incl. idea counts
 *   POST /admin/boards/{slug}/statuses              — create a custom status
 *   PUT  /admin/boards/{slug}/statuses/{key}        — labels / hidden / flags
 *   POST /admin/boards/{slug}/statuses/{key}/delete — delete a custom status
 *
 * AuthZ: accountAdmin (NOT moderator — moderators only change an idea's
 * status), CSRF globally enforced for the writes, per-action rate limit
 * `board:statuses`. The board is looked up account-scoped
 * (findBySlugForAccount — a foreign account's board → 404) BEFORE any
 * board_statuses access, so another tenant's statuses are neither visible
 * nor addressable. Not plan-gated (all tiers).
 *
 * The first write materialises the six system rows (one transaction with
 * the write itself). Every input is validated before anything is written:
 * labels are strict plaintext (StatusLabel), a custom status needs at least
 * one label, a status's category is immutable, `open` cannot be hidden, a
 * system status cannot be deleted, at most BoardStatuses::MAX_STATUSES per
 * board. Deleting a custom status that still holds ideas needs `move_to` =
 * another visible status of the SAME category; ideas are moved and the row
 * deleted in one transaction (409 `status_in_use` otherwise).
 *
 * Audit: board.status.created | board.status.updated | board.status.deleted
 * (ids/keys/flags only — labels are owner content, not logged).
 */
final readonly class BoardStatusesAdminAction
{
    public function __construct(
        private Connection $conn,
        private BoardRepository $boardRepo,
        private BoardStatusRepository $statusRepo,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        return $this->ok($response, $board, 200);
    }

    /** @param array<string, mixed> $args */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $body     = $this->body($request);
        $category = is_string($body['category'] ?? null) ? $body['category'] : '';
        $fields   = [];
        if (!BoardStatuses::isCategory($category)) {
            $fields['category'] = 'Unknown category.';
        }

        [$labelDe, $labelEn] = $this->labels($body, null, null, $fields);
        if ($labelDe === null && $labelEn === null && !isset($fields['label_de']) && !isset($fields['label_en'])) {
            $fields['label_de'] = 'A custom status needs at least one label.';
        }

        // A decided category (done, declined, archived) starts closed for votes, like its system status.
        $allowVotes    = $this->flag($body, 'allow_votes', !BoardStatuses::isCategory($category) || BoardStatuses::defaultAllowVotes($category), $fields);
        $allowComments = $this->flag($body, 'allow_comments', true, $fields);

        if ($fields !== []) {
            return $this->validationError($response, $fields);
        }

        if (count($this->statusRepo->registryFor($board)->all()) >= BoardStatuses::MAX_STATUSES) {
            return JsonError::respond($response, 422, 'status_limit_reached', 'A board can have at most ' . BoardStatuses::MAX_STATUSES . ' statuses.');
        }

        $boardId = (int) $board['id'];
        $key     = $this->conn->transactional(function () use ($board, $request, $boardId, $category, $labelDe, $labelEn, $allowVotes, $allowComments): string {
            $this->statusRepo->materialize($board, $this->accountId($request));

            return $this->statusRepo->createCustom($boardId, $category, $labelDe, $labelEn, $allowVotes, $allowComments);
        });

        $this->audit->log('board.status.created', [
            'board_id'       => $boardId,
            'status_key'     => $key,
            'category'       => $category,
            'allow_votes'    => $allowVotes,
            'allow_comments' => $allowComments,
            'actor_id'       => $this->actorId($request),
        ]);

        return $this->ok($response, $board, 201, $key);
    }

    /** @param array<string, mixed> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $status = $this->statusRepo->registryFor($board)->find(is_string($args['key'] ?? null) ? $args['key'] : '');
        if (!$status instanceof BoardStatus) {
            return JsonError::respond($response, 404, 'not_found', 'Status not found.');
        }

        $body   = $this->body($request);
        $fields = [];
        if (array_key_exists('category', $body) && $body['category'] !== $status->category) {
            $fields['category'] = 'The category of a status cannot be changed.';
        }

        [$labelDe, $labelEn] = $this->labels($body, $status->labelDe, $status->labelEn, $fields);
        if (!$status->isSystem && $labelDe === null && $labelEn === null && !isset($fields['label_de']) && !isset($fields['label_en'])) {
            $fields['label_de'] = 'A custom status needs at least one label.';
        }

        $isHidden      = $this->flag($body, 'is_hidden', $status->isHidden, $fields);
        $allowVotes    = $this->flag($body, 'allow_votes', $status->allowVotes, $fields);
        $allowComments = $this->flag($body, 'allow_comments', $status->allowComments, $fields);
        if ($isHidden && $status->key === 'open') {
            $fields['is_hidden'] = 'The status "open" cannot be hidden.';
        }

        if ($fields !== []) {
            return $this->validationError($response, $fields);
        }

        $boardId = (int) $board['id'];
        $this->conn->transactional(function () use ($board, $request, $boardId, $status, $labelDe, $labelEn, $isHidden, $allowVotes, $allowComments): void {
            $this->statusRepo->materialize($board, $this->accountId($request));
            $this->statusRepo->update($boardId, $status->key, $labelDe, $labelEn, $isHidden, $allowVotes, $allowComments);
        });

        $this->audit->log('board.status.updated', [
            'board_id'       => $boardId,
            'status_key'     => $status->key,
            'is_hidden'      => $isHidden,
            'allow_votes'    => $allowVotes,
            'allow_comments' => $allowComments,
            'actor_id'       => $this->actorId($request),
        ]);

        return $this->ok($response, $board, 200);
    }

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $statuses = $this->statusRepo->registryFor($board);
        $status   = $statuses->find(is_string($args['key'] ?? null) ? $args['key'] : '');
        if (!$status instanceof BoardStatus) {
            return JsonError::respond($response, 404, 'not_found', 'Status not found.');
        }
        if ($status->isSystem) {
            return JsonError::respond($response, 422, 'system_status', 'A system status cannot be deleted. Hide it instead.');
        }

        $boardId = (int) $board['id'];
        $body    = $this->body($request);
        $moveTo  = is_string($body['move_to'] ?? null) && $body['move_to'] !== '' ? $body['move_to'] : null;
        $target  = $moveTo !== null ? $statuses->find($moveTo) : null;
        if ($moveTo !== null && (!$target instanceof BoardStatus || $target->key === $status->key || $target->category !== $status->category || $target->isHidden)) {
            return $this->validationError($response, ['move_to' => 'Choose another visible status of the same category.']);
        }

        $moved = $this->conn->transactional(function () use ($boardId, $status, $target): ?int {
            $inUse = $this->statusRepo->countIdeas($boardId, $status->key);
            if ($inUse > 0 && !$target instanceof BoardStatus) {
                return null;
            }

            $moved = $target instanceof BoardStatus ? $this->statusRepo->moveIdeas($boardId, $status->key, $target->key) : 0;
            $this->statusRepo->deleteCustom($boardId, $status->key);

            return $moved;
        });

        if ($moved === null) {
            return JsonError::respond($response, 409, 'status_in_use', 'Move the ideas of this status to another status of the same category first.', [
                'idea_count' => $this->statusRepo->countIdeas($boardId, $status->key),
            ]);
        }

        $this->audit->log('board.status.deleted', [
            'board_id'    => $boardId,
            'status_key'  => $status->key,
            'moved_to'    => $target?->key,
            'moved_ideas' => $moved,
            'actor_id'    => $this->actorId($request),
        ]);

        return $this->ok($response, $board, 200);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function board(ServerRequestInterface $request, array $args): ?array
    {
        $slug = is_string($args['slug'] ?? null) ? $args['slug'] : '';

        return $this->boardRepo->findBySlugForAccount($slug, $this->accountId($request));
    }

    private function accountId(ServerRequestInterface $request): int
    {
        return (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
    }

    private function actorId(ServerRequestInterface $request): int
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Reads label_de/label_en: absent → keep $currentDe/$currentEn, null or
     * blank → clear, string → validated plaintext. Errors go to $fields.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $fields
     * @return array{0: ?string, 1: ?string}
     */
    private function labels(array $body, ?string $currentDe, ?string $currentEn, array &$fields): array
    {
        $result = ['label_de' => $currentDe, 'label_en' => $currentEn];
        foreach (array_keys($result) as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null) {
                $result[$field] = null;
                continue;
            }
            if (!is_string($value)) {
                $fields[$field] = 'Invalid label.';
                continue;
            }
            $error = StatusLabel::error($value);
            if ($error !== null) {
                $fields[$field] = $error;
                continue;
            }
            $result[$field] = StatusLabel::normalize($value);
        }

        return [$result['label_de'], $result['label_en']];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $fields
     */
    private function flag(array $body, string $field, bool $current, array &$fields): bool
    {
        if (!array_key_exists($field, $body)) {
            return $current;
        }

        $value = is_bool($body[$field]) ? $body[$field] : filter_var($body[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!is_bool($value)) {
            $fields[$field] = 'Must be true or false.';

            return $current;
        }

        return $value;
    }

    /** @param array<string, string> $fields */
    private function validationError(ResponseInterface $response, array $fields): ResponseInterface
    {
        return JsonError::respond($response, 422, 'validation_error', 'Validation failed.', ['fields' => $fields]);
    }

    /** @param array<string, mixed> $board */
    private function ok(ResponseInterface $response, array $board, int $status, ?string $createdKey = null): ResponseInterface
    {
        // Re-read after a write: the board row's flag may be stale.
        $fresh   = $this->boardRepo->findBySlugForAccount((string) $board['slug'], (int) $board['account_id']) ?? $board;
        $payload = ['statuses' => $this->statusRepo->registryWithCounts($fresh)->toArray()];
        if ($createdKey !== null) {
            $payload['key'] = $createdKey;
        }

        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
