<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Comment persistence.
 *
 * Prepared-statements-only via DBAL. No query-string concatenation.
 *
 * `comments` has no own board_id column (schema: idea_id + author_id
 * only, see db/schema.sql) — board/account scoping therefore does NOT happen here,
 * but at the caller (action): the idea is first loaded board-scoped via
 * IdeaRepository::findInBoard() (foreign/unknown idea → 404, no
 * row reachable); only a confirmed board-scoped $ideaId reaches this
 * class. listByIdea()/create() bind idea_id as a parameter — a comment is
 * thereby structurally bound to exactly one (already board-checked) idea.
 * Deleting (moderation) additionally binds idea_id in the WHERE clause, so
 * a comment is never addressable via a foreign idea_id (defense in depth,
 * analogous to IdeaRepository::withdraw()).
 */
final readonly class CommentRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Creates a new comment on an (already board-scoped, checked) idea.
     * Plaintext only — no HTML/Markdown interpretation (shared-origin invariant).
     *
     * @throws DbalException
     * @return int The new comment ID (last insert id).
     */
    public function create(int $ideaId, int $authorId, string $body): int
    {
        $this->conn->executeStatement(
            'INSERT INTO comments (idea_id, author_id, body, created_at)
             VALUES (:idea_id, :author_id, :body, CURRENT_TIMESTAMP)',
            [
                'idea_id'   => $ideaId,
                'author_id' => $authorId,
                'body'      => $body,
            ],
        );

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Returns all comments of an (already board-scoped, checked) idea,
     * chronologically ascending (oldest first — flat list, no threads).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listByIdea(int $ideaId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, idea_id, author_id, body, created_at
             FROM comments
             WHERE idea_id = :idea_id
             ORDER BY created_at ASC, id ASC',
            ['idea_id' => $ideaId],
        );

        return $rows;
    }

    /**
     * Returns a single comment, scoped to the (already board-checked)
     * idea. Returns null if the comment is unknown OR does not belong to
     * this idea (cross-idea leak structurally excluded).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findForIdea(int $ideaId, int $commentId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, idea_id, author_id, body, created_at
             FROM comments
             WHERE idea_id = :idea_id AND id = :id',
            ['idea_id' => $ideaId, 'id' => $commentId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Deletes a comment (hard delete, idea-scoped, moderation mutation).
     *
     * Binds id AND idea_id as parameters — a comment of a foreign idea
     * is structurally never deleted (defense in depth beyond the action
     * guard, analogous to IdeaRepository::setPinned()).
     * Returns: true if exactly one row was deleted, false otherwise.
     *
     * @throws DbalException
     */
    public function delete(int $ideaId, int $commentId): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM comments WHERE id = :id AND idea_id = :idea_id',
            ['id' => $commentId, 'idea_id' => $ideaId],
        );

        return $affected === 1;
    }

    /**
     * Lists ALL comments of an account, across all boards/ideas
     * (customer self-export). Account-scoped via a double
     * JOIN (comments → ideas → boards), since comments itself carries neither
     * board_id nor account_id — a comment of a foreign account
     * structurally never shows up here.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT c.id, c.idea_id, c.author_id, c.body, c.created_at
             FROM comments c
             INNER JOIN ideas i ON i.id = c.idea_id
             INNER JOIN boards b ON b.id = i.board_id
             WHERE b.account_id = :account_id
             ORDER BY c.id ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }
}
