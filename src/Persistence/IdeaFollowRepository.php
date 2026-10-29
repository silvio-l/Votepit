<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Follow relation between registered users and ideas (close-the-loop slice
 * 01). Every write is called only after the caller has resolved the idea
 * board-scoped (findInBoard), so the board_id stored here always matches the
 * idea's board. Reads that feed mails never trust a caller-supplied board.
 */
final readonly class IdeaFollowRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Idempotent: following twice keeps the original row.
     *
     * @throws DbalException
     */
    public function follow(int $ideaId, int $userId, int $boardId, \DateTimeImmutable $at): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO idea_follows (idea_id, user_id, board_id, created_at) VALUES (:idea_id, :user_id, :board_id, :created_at)',
                ['idea_id' => $ideaId, 'user_id' => $userId, 'board_id' => $boardId, 'created_at' => $at->format('Y-m-d H:i:s')],
            );
        } catch (UniqueConstraintViolationException) {
            // Already following.
        }
    }

    /** @throws DbalException */
    public function unfollow(int $ideaId, int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM idea_follows WHERE idea_id = :idea_id AND user_id = :user_id',
            ['idea_id' => $ideaId, 'user_id' => $userId],
        );
    }

    /** @throws DbalException */
    public function isFollowing(int $ideaId, int $userId): bool
    {
        return $this->conn->fetchOne(
            'SELECT 1 FROM idea_follows WHERE idea_id = :idea_id AND user_id = :user_id',
            ['idea_id' => $ideaId, 'user_id' => $userId],
        ) !== false;
    }

    /**
     * Registered followers of an idea (status updates reach them like voters).
     *
     * @return list<int>
     * @throws DbalException
     */
    public function followerIds(int $ideaId): array
    {
        $ids = $this->conn->fetchFirstColumn(
            'SELECT user_id FROM idea_follows WHERE idea_id = :idea_id',
            ['idea_id' => $ideaId],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /**
     * Boards the user took part in: voted on, commented on, wrote an idea on,
     * or follows an idea of. Basis of the opt-in new-ideas digest.
     *
     * @return list<int> board ids
     * @throws DbalException
     */
    public function participatedBoardIds(int $userId): array
    {
        $ids = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT i.board_id FROM votes v INNER JOIN ideas i ON i.id = v.idea_id WHERE v.user_id = :u1
             UNION
             SELECT DISTINCT i.board_id FROM comments c INNER JOIN ideas i ON i.id = c.idea_id WHERE c.author_id = :u2
             UNION
             SELECT DISTINCT i.board_id FROM ideas i WHERE i.author_id = :u3
             UNION
             SELECT DISTINCT f.board_id FROM idea_follows f WHERE f.user_id = :u4',
            ['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }
}
