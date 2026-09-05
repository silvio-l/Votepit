<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistence for targeted user blocks.
 *
 * blocked_users(account_id, user_id, board_id NULL, created_by) carries both
 * account-wide (board_id NULL) and board-scoped blocks (board_id set,
 * enforcement follows in a separate step). Prepared-statements
 * only. Separate from the global `users.is_blocked` kill switch (UserRepository).
 */
final readonly class BlockRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Blocks a user for the given scope. No built-in
     * idempotency check — the caller (action) determines the target state upfront
     * via isBlocked() and calls block() only on an actual state change.
     *
     * @throws DbalException
     */
    public function block(int $accountId, int $userId, ?int $boardId, int $actorId): void
    {
        $this->conn->insert('blocked_users', [
            'account_id' => $accountId,
            'user_id'    => $userId,
            'board_id'   => $boardId,
            'created_by' => $actorId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Lifts a block for the given scope. Returns true if a
     * row was removed.
     *
     * @throws DbalException
     */
    public function unblock(int $accountId, int $userId, ?int $boardId): bool
    {
        $sql = $boardId === null
            ? 'DELETE FROM blocked_users WHERE account_id = :account_id AND user_id = :user_id AND board_id IS NULL'
            : 'DELETE FROM blocked_users WHERE account_id = :account_id AND user_id = :user_id AND board_id = :board_id';

        $params = ['account_id' => $accountId, 'user_id' => $userId];
        if ($boardId !== null) {
            $params['board_id'] = $boardId;
        }

        return $this->conn->executeStatement($sql, $params) > 0;
    }

    /**
     * Checks whether a user is blocked in the given scope.
     *
     * With `$boardId = null` this matches exclusively account-wide blocks
     * (board_id IS NULL). With `$boardId` set, both
     * account-wide and board-specific blocks match (one check covers
     * both levels) — used by the inline guards of the board-mutating
     * actions (Idea Create/Edit/Withdraw, Vote).
     *
     * @throws DbalException
     */
    public function isBlocked(int $accountId, int $userId, ?int $boardId): bool
    {
        $sql = $boardId === null
            ? 'SELECT 1 FROM blocked_users WHERE account_id = :account_id AND user_id = :user_id AND board_id IS NULL'
            : 'SELECT 1 FROM blocked_users WHERE account_id = :account_id AND user_id = :user_id AND (board_id IS NULL OR board_id = :board_id)';

        $params = ['account_id' => $accountId, 'user_id' => $userId];
        if ($boardId !== null) {
            $params['board_id'] = $boardId;
        }

        return $this->conn->fetchOne($sql, $params) !== false;
    }

    /**
     * Checks whether a block row exists in exactly the given scope
     * (exact match on board_id, no OR fallback to the account-wide
     * level like isBlocked()). Needed so UserBlockAction can determine the
     * target state of the currently selected scope, regardless of
     * whether a block already exists in the other scope.
     *
     * @throws DbalException
     */
    public function isBlockedInScope(int $accountId, int $userId, ?int $boardId): bool
    {
        $sql = $boardId === null
            ? 'SELECT 1 FROM blocked_users WHERE account_id = :account_id AND user_id = :user_id AND board_id IS NULL'
            : 'SELECT 1 FROM blocked_users WHERE account_id = :account_id AND user_id = :user_id AND board_id = :board_id';

        $params = ['account_id' => $accountId, 'user_id' => $userId];
        if ($boardId !== null) {
            $params['board_id'] = $boardId;
        }

        return $this->conn->fetchOne($sql, $params) !== false;
    }

    /**
     * Lists ALL block entries of an account (account-wide + board-scoped)
     * for the account self-export. Account-scoped via WHERE
     * account_id — a block entry of a foreign account can structurally
     * never show up here. No PII: only internal user/board IDs (ADR 0002).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT id, user_id, board_id, created_by, created_at
             FROM blocked_users
             WHERE account_id = :account_id
             ORDER BY created_at ASC',
            ['account_id' => $accountId],
        );
    }
}
