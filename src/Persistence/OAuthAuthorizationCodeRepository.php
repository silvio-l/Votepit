<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Single-use, short-lived authorization codes of the MCP OAuth server
 * (migration 0088). Only the SHA-256 of a code is stored. A code carries the
 * consented grant (account, consenting user, board => level) until the
 * client exchanges it; consumption is an atomic `consumed_at IS NULL`
 * update, so two concurrent exchanges can never both win.
 */
final readonly class OAuthAuthorizationCodeRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @param non-empty-array<int, string> $boardScopes board_id => level
     * @throws DbalException
     */
    public function create(
        string $codeHash,
        int $oauthClientId,
        int $accountId,
        int $userId,
        array $boardScopes,
        string $redirectUri,
        string $codeChallenge,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO oauth_authorization_codes
                (code_hash, oauth_client_id, account_id, user_id, board_scopes, redirect_uri, code_challenge, expires_at, created_at)
             VALUES (:code_hash, :client, :account_id, :user_id, :board_scopes, :redirect_uri, :challenge, :expires_at, :created_at)',
            [
                'code_hash'    => $codeHash,
                'client'       => $oauthClientId,
                'account_id'   => $accountId,
                'user_id'      => $userId,
                'board_scopes' => (string) json_encode($boardScopes),
                'redirect_uri' => $redirectUri,
                'challenge'    => $codeChallenge,
                'expires_at'   => $expiresAt->format('Y-m-d H:i:s'),
                'created_at'   => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return array{id: int, oauth_client_id: int, account_id: int, user_id: int, board_scopes: array<int, string>, redirect_uri: string, code_challenge: string, expires_at: string, consumed_at: string|null, api_token_id: int|null}|null
     * @throws DbalException
     */
    public function findByHash(string $codeHash): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, oauth_client_id, account_id, user_id, board_scopes, redirect_uri, code_challenge, expires_at, consumed_at, api_token_id
             FROM oauth_authorization_codes WHERE code_hash = :code_hash',
            ['code_hash' => $codeHash],
        );

        if ($row === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded     = json_decode((string) $row['board_scopes'], true);
        $boardScopes = [];
        foreach (is_array($decoded) ? $decoded : [] as $boardId => $scope) {
            if (is_numeric($boardId) && is_string($scope)) {
                $boardScopes[(int) $boardId] = $scope;
            }
        }

        return [
            'id'              => (int) $row['id'],
            'oauth_client_id' => (int) $row['oauth_client_id'],
            'account_id'      => (int) $row['account_id'],
            'user_id'         => (int) $row['user_id'],
            'board_scopes'    => $boardScopes,
            'redirect_uri'    => (string) $row['redirect_uri'],
            'code_challenge'  => (string) $row['code_challenge'],
            'expires_at'      => (string) $row['expires_at'],
            'consumed_at'     => $row['consumed_at'] !== null ? (string) $row['consumed_at'] : null,
            'api_token_id'    => $row['api_token_id'] !== null ? (int) $row['api_token_id'] : null,
        ];
    }

    /**
     * Atomically claims a code. True for exactly one caller; false if it was
     * already consumed (replay or a concurrent exchange).
     *
     * @throws DbalException
     */
    public function consume(int $id, \DateTimeImmutable $now): bool
    {
        return $this->conn->executeStatement(
            'UPDATE oauth_authorization_codes SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $id],
        ) === 1;
    }

    /**
     * Records the grant a consumed code produced, so a replay of the code
     * can revoke it.
     *
     * @throws DbalException
     */
    public function attachGrant(int $id, int $apiTokenId): void
    {
        $this->conn->executeStatement(
            'UPDATE oauth_authorization_codes SET api_token_id = :token WHERE id = :id',
            ['token' => $apiTokenId, 'id' => $id],
        );
    }

    /**
     * Deletes codes that expired before $cutoff (consumed or not).
     *
     * @throws DbalException
     */
    public function purgeExpired(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->conn->executeStatement(
            'DELETE FROM oauth_authorization_codes WHERE expires_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
