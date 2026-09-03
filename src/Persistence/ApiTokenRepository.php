<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * API token persistence (Agent API / Votepit MCP).
 *
 * Prepared-Statements-only. Mirrors LoginTokenRepository's hashed-token-at-rest
 * pattern (hash on write, compare-by-hash on lookup, NEVER store/log the raw
 * token) — same crypto (Votepit\Security\TokenVault), not a second scheme.
 *
 * Strict board-scoping: every mutating method binds board_id as a parameter,
 * so a token can never be revoked/read across a board boundary it doesn't
 * belong to (cross-board leak structurally excluded, same discipline as
 * IdeaRepository).
 */
final readonly class ApiTokenRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Creates a new token, board-scoped (prepared statement).
     * Only the hash is stored — the plaintext exists exclusively
     * in the return value of ApiTokenAuthenticator::generate() and is shown
     * to the admin exactly once.
     *
     * @throws DbalException
     * @return int The new token ID (last insert id).
     */
    public function create(
        int $accountId,
        int $boardId,
        int $createdByUserId,
        string $label,
        string $tokenHash,
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO api_tokens (account_id, board_id, created_by_user_id, label, token_hash, last_used_at, revoked_at, created_at)
             VALUES (:account_id, :board_id, :created_by_user_id, :label, :token_hash, NULL, NULL, CURRENT_TIMESTAMP)',
            [
                'account_id'         => $accountId,
                'board_id'           => $boardId,
                'created_by_user_id' => $createdByUserId,
                'label'              => $label,
                'token_hash'         => $tokenHash,
            ],
        );

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Finds an active (non-revoked) token by its hash.
     * On a hit, updates last_used_at (best-effort telemetry —
     * an error here must never block the authenticated request,
     * so the caller (ApiTokenAuthenticator) catches DbalException).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByHash(string $tokenHash): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, board_id, created_by_user_id, label, last_used_at, revoked_at, created_at
             FROM api_tokens
             WHERE token_hash = :token_hash AND revoked_at IS NULL',
            ['token_hash' => $tokenHash],
        );

        if ($row === false) {
            return null;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE api_tokens SET last_used_at = :now WHERE id = :id',
            ['now' => $now, 'id' => (int) $row['id']],
        );

        return $row;
    }

    /**
     * Lists all tokens of a board (active AND revoked, newest first) —
     * for the admin UI. Board-scoped: a token from a
     * foreign board can structurally never show up here. NEVER returns
     * token_hash (no plaintext, no hash — both stay internal).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForBoard(int $boardId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT id, label, created_by_user_id, last_used_at, revoked_at, created_at
             FROM api_tokens
             WHERE board_id = :board_id
             ORDER BY created_at DESC',
            ['board_id' => $boardId],
        );
    }

    /**
     * Finds a single token (board-scoped, including revoked ones) — for the
     * admin UI (404 distinction "unknown" vs. "already revoked").
     * NEVER returns token_hash.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findForBoard(int $boardId, int $tokenId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, label, created_by_user_id, last_used_at, revoked_at, created_at
             FROM api_tokens
             WHERE id = :id AND board_id = :board_id',
            ['id' => $tokenId, 'board_id' => $boardId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Revokes a token (board-scoped, prepared statement). Sets ONLY
     * revoked_at (no hard delete — the audit trail is preserved). Binds
     * board_id as a parameter — a token that doesn't belong to this board
     * is left unchanged (returns false, no cross-board leak).
     *
     * @throws DbalException
     */
    public function revoke(int $boardId, int $tokenId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->conn->executeStatement(
            'UPDATE api_tokens SET revoked_at = :now
             WHERE id = :id AND board_id = :board_id AND revoked_at IS NULL',
            ['now' => $now, 'id' => $tokenId, 'board_id' => $boardId],
        );

        return $affected === 1;
    }

    /**
     * Lists ALL tokens of an account, across all boards (customer
     * self-export). Account-scoped via WHERE account_id (every token
     * already denormalizes account_id alongside board_id, see class doc) — a
     * token of a foreign account can structurally never show up here. Metadata
     * only: NEVER returns token_hash (no plaintext, no hash — both stay
     * internal, analogous to listForBoard()).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT id, board_id, label, created_by_user_id, last_used_at, revoked_at, created_at
             FROM api_tokens
             WHERE account_id = :account_id
             ORDER BY created_at DESC',
            ['account_id' => $accountId],
        );
    }

    /**
     * Revokes ALL still-active tokens of an account, across all
     * boards — downgrade enforcement (mirrors BoardRepository::
     * enforcePlanLimit()'s self-healing pattern): when an account is downgraded to a
     * plan that PlanPolicy::agentApiAllowed() no longer allows, a token
     * previously issued on Pro must stop working immediately, instead of
     * staying valid until a manual revocation (see ApiTokenAction::create()'s
     * class doc — the gate there only applies on creation, not on usage;
     * this call closes the gap from the downgrade side). Account-scoped, prepared
     * statement, idempotent (already-revoked tokens are excluded by
     * `revoked_at IS NULL` — a repeated call changes
     * nothing further).
     *
     * @return list<int> The IDs of the tokens just revoked (for the audit log).
     * @throws DbalException
     */
    public function revokeAllForAccount(int $accountId): array
    {
        /** @var list<int> $activeIds */
        $activeIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $this->conn->fetchFirstColumn(
                'SELECT id FROM api_tokens WHERE account_id = :account_id AND revoked_at IS NULL',
                ['account_id' => $accountId],
            ),
        );

        if ($activeIds === []) {
            return [];
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->executeStatement(
            'UPDATE api_tokens SET revoked_at = :now WHERE account_id = :account_id AND revoked_at IS NULL',
            ['now' => $now, 'account_id' => $accountId],
        );

        return $activeIds;
    }
}
