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
 * Account-scoped (not board-scoped, see migration 0044): a token grants
 * access to a set of boards (api_token_boards), each with its OWN coarse
 * 'read'|'write' scope (migration 0047) — one token can write on board A
 * and only read board B. Every mutating method binds account_id as a
 * parameter, so a token can never be read/revoked across an account
 * boundary it doesn't belong to (cross-tenant leak structurally excluded,
 * same discipline as IdeaRepository).
 */
final readonly class ApiTokenRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Creates a new token, account-scoped, granting access to the boards
     * keyed in $boardScopes, each at its own scope. Only the hash is
     * stored — the plaintext exists exclusively in the return value of
     * ApiTokenAuthenticator::generate() and is shown to the admin exactly
     * once.
     *
     * @param non-empty-array<int, string> $boardScopes board_id => 'read'|'write'
     * @throws DbalException
     * @return int The new token ID (last insert id).
     */
    public function create(
        int $accountId,
        array $boardScopes,
        int $createdByUserId,
        string $label,
        string $tokenHash,
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO api_tokens (account_id, created_by_user_id, label, token_hash, last_used_at, revoked_at, created_at)
             VALUES (:account_id, :created_by_user_id, :label, :token_hash, NULL, NULL, CURRENT_TIMESTAMP)',
            [
                'account_id'         => $accountId,
                'created_by_user_id' => $createdByUserId,
                'label'              => $label,
                'token_hash'         => $tokenHash,
            ],
        );

        $tokenId = (int) $this->conn->lastInsertId();

        foreach ($boardScopes as $boardId => $scope) {
            $this->conn->executeStatement(
                'INSERT INTO api_token_boards (token_id, board_id, scope) VALUES (:token_id, :board_id, :scope)',
                ['token_id' => $tokenId, 'board_id' => $boardId, 'scope' => $scope],
            );
        }

        return $tokenId;
    }

    /**
     * Finds an active (non-revoked) token by its hash, including its
     * granted board IDs. On a hit, updates last_used_at (best-effort
     * telemetry — an error here must never block the authenticated request,
     * so the caller (ApiTokenAuthenticator) catches DbalException).
     *
     * @return array{id: int, account_id: int, created_by_user_id: int, label: string, last_used_at: string|null, revoked_at: string|null, created_at: string, board_scopes: array<int, string>}|null
     * @throws DbalException
     */
    public function findByHash(string $tokenHash): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, created_by_user_id, label, last_used_at, revoked_at, created_at
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

        $boardScopes = $this->boardScopesFor((int) $row['id']);

        return [
            'id'                 => (int) $row['id'],
            'account_id'         => (int) $row['account_id'],
            'created_by_user_id' => (int) $row['created_by_user_id'],
            'label'              => (string) $row['label'],
            'last_used_at'       => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            'revoked_at'         => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            'created_at'         => (string) $row['created_at'],
            'board_scopes'       => $boardScopes,
        ];
    }

    /**
     * @return array<int, string> board_id => 'read'|'write'
     * @throws DbalException
     */
    private function boardScopesFor(int $tokenId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT board_id, scope FROM api_token_boards WHERE token_id = :token_id',
            ['token_id' => $tokenId],
        );

        $boardScopes = [];
        foreach ($rows as $row) {
            $boardScopes[(int) $row['board_id']] = (string) $row['scope'];
        }

        return $boardScopes;
    }

    /**
     * Lists all tokens of an account (active AND revoked, newest first) —
     * for the admin UI. Account-scoped: a token from a
     * foreign account can structurally never show up here. NEVER returns
     * token_hash (no plaintext, no hash — both stay internal). Each row
     * carries its granted board_ids.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, label, created_by_user_id, last_used_at, revoked_at, created_at
             FROM api_tokens
             WHERE account_id = :account_id
             ORDER BY created_at DESC',
            ['account_id' => $accountId],
        );

        return array_map(
            function (array $row): array {
                $row['board_scopes'] = $this->boardScopesFor((int) $row['id']);
                return $row;
            },
            $rows,
        );
    }

    /**
     * Finds a single token (account-scoped, including revoked ones) — for
     * the admin UI (404 distinction "unknown" vs. "already revoked").
     * NEVER returns token_hash.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findForAccount(int $accountId, int $tokenId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, label, created_by_user_id, last_used_at, revoked_at, created_at
             FROM api_tokens
             WHERE id = :id AND account_id = :account_id',
            ['id' => $tokenId, 'account_id' => $accountId],
        );

        if ($row === false) {
            return null;
        }

        $row['board_scopes'] = $this->boardScopesFor((int) $row['id']);
        return $row;
    }

    /**
     * Revokes a token (account-scoped, prepared statement). Sets ONLY
     * revoked_at (no hard delete — the audit trail is preserved). Binds
     * account_id as a parameter — a token that doesn't belong to this
     * account is left unchanged (returns false, no cross-tenant leak).
     *
     * @throws DbalException
     */
    public function revoke(int $accountId, int $tokenId): bool
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $affected = $this->conn->executeStatement(
            'UPDATE api_tokens SET revoked_at = :now
             WHERE id = :id AND account_id = :account_id AND revoked_at IS NULL',
            ['now' => $now, 'id' => $tokenId, 'account_id' => $accountId],
        );

        return $affected === 1;
    }

    /**
     * Revokes ALL still-active tokens of an account — downgrade enforcement
     * (mirrors BoardRepository::enforcePlanLimit()'s self-healing pattern):
     * when an account is downgraded to a plan that PlanPolicy::
     * agentApiAllowed() no longer allows, a token previously issued on Pro
     * must stop working immediately, instead of staying valid until a
     * manual revocation (see ApiTokenAction::create()'s class doc — the
     * gate there only applies on creation, not on usage; this call closes
     * the gap from the downgrade side). Account-scoped, prepared statement,
     * idempotent (already-revoked tokens are excluded by `revoked_at IS
     * NULL` — a repeated call changes nothing further).
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
