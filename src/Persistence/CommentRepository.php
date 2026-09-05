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
     * Stored verbatim as plain text — no HTML ever, though the frontend
     * renders a small safe Markdown-lite subset of it (see MarkdownLite.tsx).
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
     * Returns the author_id + created_at of the single most recent comment
     * on this idea — by ANY author — or null if the idea has no comments
     * yet. Used by the consecutive-comment anti-spam check: if the most
     * recent comment's author is the requesting user, they'd be posting
     * two in a row with nobody else having replied in between → reject.
     *
     * @return array{author_id: int, created_at: string}|null
     * @throws DbalException
     */
    public function findLastForIdea(int $ideaId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT author_id, created_at
             FROM comments
             WHERE idea_id = :idea_id
             ORDER BY created_at DESC, id DESC
             LIMIT 1',
            ['idea_id' => $ideaId],
        );

        if ($row === false) {
            return null;
        }

        return ['author_id' => (int) $row['author_id'], 'created_at' => (string) $row['created_at']];
    }

    /**
     * DISTINCT author_ids of every comment already on this idea, excluding
     * $excludeAuthorIds (the notification-preferences PRD: used to fan out
     * "thread_reply" notifications to prior commenters, excluding the new
     * commenter themselves and the idea author — the idea author is already
     * covered exclusively by the separate "idea_comment" notification, see
     * migrations/0028_add_user_scoped_notifications.sql). Called AFTER the
     * triggering comment is inserted — $excludeAuthorIds is expected to
     * include the new commenter's own id so their just-inserted comment
     * (and any earlier one of theirs) never makes them a recipient of their
     * own notification.
     *
     * @param list<int> $excludeAuthorIds
     * @return list<int>
     * @throws DbalException
     */
    public function distinctPriorAuthorIds(int $ideaId, array $excludeAuthorIds): array
    {
        if ($excludeAuthorIds === []) {
            /** @var list<int> $ids */
            $ids = array_map(
                static fn (mixed $id): int => (int) $id,
                $this->conn->fetchFirstColumn('SELECT DISTINCT author_id FROM comments WHERE idea_id = :idea_id', ['idea_id' => $ideaId]),
            );

            return $ids;
        }

        /** @var list<int> $ids */
        $ids = array_map(
            static fn (mixed $id): int => (int) $id,
            $this->conn->fetchFirstColumn(
                'SELECT DISTINCT author_id FROM comments WHERE idea_id = :idea_id AND author_id NOT IN (:exclude)',
                ['idea_id' => $ideaId, 'exclude' => $excludeAuthorIds],
                ['exclude' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            ),
        );

        return $ids;
    }

    /**
     * Edits an (already board-scoped, checked) comment's body, idea- AND
     * author-scoped in the WHERE clause — a comment of a foreign idea or a
     * foreign author is structurally never editable via this method
     * (defense in depth beyond the action's own ownership/window check).
     * Stamps edited_at to now. Returns true if exactly one row was updated.
     *
     * @throws DbalException
     */
    public function update(int $ideaId, int $commentId, int $authorId, string $body): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE comments
             SET body = :body, edited_at = CURRENT_TIMESTAMP
             WHERE id = :id AND idea_id = :idea_id AND author_id = :author_id',
            ['body' => $body, 'id' => $commentId, 'idea_id' => $ideaId, 'author_id' => $authorId],
        );

        return $affected === 1;
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
            'SELECT id, idea_id, author_id, body, created_at, edited_at
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
            'SELECT id, idea_id, author_id, body, created_at, edited_at
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
            'SELECT c.id, c.idea_id, c.author_id, c.body, c.created_at, c.edited_at
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
