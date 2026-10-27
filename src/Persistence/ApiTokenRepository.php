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
 * 'read'|'write'|'moderate' grant (migrations 0047, 0081, ApiTokenScope) —
 * one token can write on board A and only read board B. Every mutating method binds account_id as a
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
     * $oauthClientId set = an MCP OAuth grant (migration 0088): the row is
     * then never usable as a static token (findByHash() excludes it, and
     * the caller passes the hash of a secret it discards) — only through
     * the OAuth access tokens issued for it.
     *
     * @param non-empty-array<int, string> $boardScopes board_id => 'read'|'write'|'moderate'
     * @throws DbalException
     * @return int The new token ID (last insert id).
     */
    public function create(
        int $accountId,
        array $boardScopes,
        int $createdByUserId,
        string $label,
        string $tokenHash,
        ?int $oauthClientId = null,
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO api_tokens (account_id, created_by_user_id, label, oauth_client_id, token_hash, last_used_at, revoked_at, created_at)
             VALUES (:account_id, :created_by_user_id, :label, :oauth_client_id, :token_hash, NULL, NULL, CURRENT_TIMESTAMP)',
            [
                'account_id'         => $accountId,
                'created_by_user_id' => $createdByUserId,
                'label'              => $label,
                'oauth_client_id'    => $oauthClientId,
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
     * Finds an active (non-revoked) STATIC token by its hash, including its
     * granted board IDs. MCP OAuth grants (oauth_client_id set) are never
     * returned here — they authenticate only through findByOAuthAccessTokenHash().
     * On a hit, updates last_used_at (best-effort
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
             WHERE token_hash = :token_hash AND revoked_at IS NULL AND oauth_client_id IS NULL',
            ['token_hash' => $tokenHash],
        );

        return $row === false ? null : $this->hydrateUsedGrant($row);
    }

    /**
     * Resolves an MCP OAuth access token (hash) to its grant — same shape
     * as findByHash(), so ApiTokenAuthMiddleware and every action downstream
     * treat it exactly like a static token (scoping, per-token rate limits
     * and write budgets keyed by the grant id, the moderate grant's issuer
     * check). Null unless the access token exists, has not expired at $now
     * and its grant is an un-revoked OAuth grant.
     *
     * @return array{id: int, account_id: int, created_by_user_id: int, label: string, last_used_at: string|null, revoked_at: string|null, created_at: string, board_scopes: array<int, string>}|null
     * @throws DbalException
     */
    public function findByOAuthAccessTokenHash(string $accessTokenHash, \DateTimeImmutable $now): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT t.id, t.account_id, t.created_by_user_id, t.label, t.last_used_at, t.revoked_at, t.created_at
             FROM oauth_tokens o
             INNER JOIN api_tokens t ON t.id = o.api_token_id
             WHERE o.token_hash = :token_hash AND o.kind = :kind AND o.expires_at > :now
               AND t.revoked_at IS NULL AND t.oauth_client_id IS NOT NULL',
            ['token_hash' => $accessTokenHash, 'kind' => 'access', 'now' => $now->format('Y-m-d H:i:s')],
        );

        return $row === false ? null : $this->hydrateUsedGrant($row);
    }

    /**
     * An MCP OAuth grant by id (refresh/exchange path) — including revoked
     * ones, so the caller can tell "revoked" apart. Null for static tokens.
     *
     * @return array{id: int, account_id: int, created_by_user_id: int, oauth_client_id: int, revoked_at: string|null, board_scopes: array<int, string>}|null
     * @throws DbalException
     */
    public function findOAuthGrant(int $tokenId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, created_by_user_id, oauth_client_id, revoked_at
             FROM api_tokens WHERE id = :id AND oauth_client_id IS NOT NULL',
            ['id' => $tokenId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id'                 => (int) $row['id'],
            'account_id'         => (int) $row['account_id'],
            'created_by_user_id' => (int) $row['created_by_user_id'],
            'oauth_client_id'    => (int) $row['oauth_client_id'],
            'revoked_at'         => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            'board_scopes'       => $this->boardScopesFor((int) $row['id']),
        ];
    }

    /**
     * Revokes an MCP OAuth grant by id — refresh-token reuse, a replayed
     * authorization code, RFC 7009 revocation or a consenting user who lost
     * the right to delegate. Static tokens are never touched here (they
     * are revoked through the account-scoped revoke()).
     *
     * @throws DbalException
     */
    public function revokeOAuthGrant(int $tokenId): void
    {
        $this->conn->executeStatement(
            'UPDATE api_tokens SET revoked_at = :now WHERE id = :id AND oauth_client_id IS NOT NULL AND revoked_at IS NULL',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $tokenId],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, account_id: int, created_by_user_id: int, label: string, last_used_at: string|null, revoked_at: string|null, created_at: string, board_scopes: array<int, string>}
     * @throws DbalException
     */
    private function hydrateUsedGrant(array $row): array
    {
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
     * @return array<int, string> board_id => 'read'|'write'|'moderate'
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
     * carries its granted board_ids, and — for an MCP OAuth grant — the
     * registered client's id and (plaintext) name.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.id, t.label, t.created_by_user_id, t.last_used_at, t.revoked_at, t.created_at,
                    t.oauth_client_id, c.client_name AS oauth_client_name
             FROM api_tokens t
             LEFT JOIN oauth_clients c ON c.id = t.oauth_client_id
             WHERE t.account_id = :account_id
             ORDER BY t.created_at DESC',
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
            'SELECT t.id, t.label, t.created_by_user_id, t.last_used_at, t.revoked_at, t.created_at,
                    t.oauth_client_id, c.client_name AS oauth_client_name
             FROM api_tokens t
             LEFT JOIN oauth_clients c ON c.id = t.oauth_client_id
             WHERE t.id = :id AND t.account_id = :account_id',
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
