<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Idea-tag persistence (migrations/0051_create_idea_tags.sql).
 *
 * Prepared-statements-only via DBAL. No query-string concatenation.
 * Board-scoped, the same chokepoint reasoning as IdeaRepository/
 * ModerationConfigRepository: idea_tags carries no account_id column, only
 * board_id — a board_id belongs to exactly one account, and every board_id
 * reaching this class already passed BoardRepository::findBySlugForAccount()/
 * findPublicBySlugForAccount() upstream, so a foreign account's tags are
 * structurally unreachable.
 */
final readonly class TagRepository
{
    /** Max tag name length (VARCHAR(40) — see the migration). */
    public const NAME_MAX_LENGTH = 40;

    public function __construct(private Connection $conn) {}

    /**
     * Lists all tags of a board, alphabetically. Board-scoped.
     *
     * @return list<array{id: int, name: string, color: string}>
     * @throws DbalException
     */
    public function listByBoard(int $boardId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, name, color FROM idea_tags WHERE board_id = :board_id ORDER BY name ASC',
            ['board_id' => $boardId],
        );

        /** @var list<array{id: int, name: string, color: string}> */
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'color' => (string) $r['color']],
            $rows,
        );
    }

    /**
     * Returns a single tag (board-scoped). Null if unknown or foreign board.
     *
     * @return array{id: int, name: string, color: string}|null
     * @throws DbalException
     */
    public function findInBoard(int $boardId, int $tagId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, name, color FROM idea_tags WHERE id = :id AND board_id = :board_id',
            ['id' => $tagId, 'board_id' => $boardId],
        );

        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'color' => (string) $row['color']];
    }

    /**
     * Creates a board-scoped tag. Returns the new tag ID, or null if a tag
     * with this name already exists in the board (UNIQUE(board_id, name) —
     * caller responds 422, no exception propagates).
     *
     * @throws DbalException
     */
    public function create(int $boardId, string $name, string $color): ?int
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO idea_tags (board_id, name, color, created_at) VALUES (:board_id, :name, :color, :created_at)',
                [
                    'board_id'   => $boardId,
                    'name'       => $name,
                    'color'      => $color,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Renames/recolors a tag (board-scoped, prepared statement).
     * Returns true if exactly one row was changed, false if the tag does not
     * exist / does not belong to this board, or null if the new name
     * collides with another tag in the same board (UNIQUE violation).
     *
     * @throws DbalException
     */
    public function rename(int $boardId, int $tagId, string $name, string $color): ?bool
    {
        try {
            $affected = $this->conn->executeStatement(
                'UPDATE idea_tags SET name = :name, color = :color WHERE id = :id AND board_id = :board_id',
                ['name' => $name, 'color' => $color, 'id' => $tagId, 'board_id' => $boardId],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $affected === 1;
    }

    /**
     * Deletes a tag (board-scoped, prepared statement). Cascades to
     * idea_tag_assignments via FK ON DELETE CASCADE — no orphan rows.
     * Returns true if exactly one row was deleted, false otherwise.
     *
     * @throws DbalException
     */
    public function delete(int $boardId, int $tagId): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM idea_tags WHERE id = :id AND board_id = :board_id',
            ['id' => $tagId, 'board_id' => $boardId],
        );

        return $affected === 1;
    }

    /**
     * Assigns a tag to an idea (idempotent — a duplicate assignment is
     * silently ignored via the composite primary key). Caller MUST already
     * have verified both the tag and the idea belong to the same board
     * (findInBoard()/IdeaRepository::findInBoard()) — this method performs
     * no board check of its own, purely persistence.
     *
     * @throws DbalException
     */
    public function assignToIdea(int $ideaId, int $tagId): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO idea_tag_assignments (idea_id, tag_id, created_at) VALUES (:idea_id, :tag_id, :created_at)',
                [
                    'idea_id'    => $ideaId,
                    'tag_id'     => $tagId,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Already assigned — idempotent no-op.
        }
    }

    /**
     * Removes a tag from an idea. Unknown/foreign combinations are a no-op.
     *
     * @throws DbalException
     */
    public function removeFromIdea(int $ideaId, int $tagId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM idea_tag_assignments WHERE idea_id = :idea_id AND tag_id = :tag_id',
            ['idea_id' => $ideaId, 'tag_id' => $tagId],
        );
    }

    /**
     * Lists the tags assigned to a single idea, alphabetically.
     *
     * @return list<array{id: int, name: string, color: string}>
     * @throws DbalException
     * @phpstan-impure
     */
    public function tagsForIdea(int $ideaId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.id, t.name, t.color
             FROM idea_tags t
             INNER JOIN idea_tag_assignments a ON a.tag_id = t.id
             WHERE a.idea_id = :idea_id
             ORDER BY t.name ASC',
            ['idea_id' => $ideaId],
        );

        /** @var list<array{id: int, name: string, color: string}> */
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'color' => (string) $r['color']],
            $rows,
        );
    }

    /**
     * Batch variant of tagsForIdea() — one query for many ideas (no N+1),
     * used by the board idea list. Grouped by idea_id, each group
     * alphabetically sorted by tag name.
     *
     * @param list<int> $ideaIds
     * @return array<int, list<array{id: int, name: string, color: string}>>
     * @throws DbalException
     */
    public function tagsForIdeas(array $ideaIds): array
    {
        if ($ideaIds === []) {
            return [];
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.idea_id, t.id, t.name, t.color
             FROM idea_tags t
             INNER JOIN idea_tag_assignments a ON a.tag_id = t.id
             WHERE a.idea_id IN (:idea_ids)
             ORDER BY t.name ASC',
            ['idea_ids' => $ideaIds],
            ['idea_ids' => ArrayParameterType::INTEGER],
        );

        /** @var array<int, list<array{id: int, name: string, color: string}>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $ideaId = (int) $row['idea_id'];
            $grouped[$ideaId] ??= [];
            $grouped[$ideaId][] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'color' => (string) $row['color']];
        }

        return $grouped;
    }
}
