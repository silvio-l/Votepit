<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Access and refresh tokens of an MCP OAuth grant (migration 0088). The
 * grant (api_tokens row) is the refresh-token family: every token row points
 * at it, revoking the grant kills all of them at once (the access-token
 * lookup in ApiTokenRepository joins on `revoked_at IS NULL`). Only SHA-256
 * hashes are stored.
 *
 * Refresh tokens rotate: exchanging one sets rotated_at (atomically, so a
 * race has exactly one winner); presenting a rotated one again is reuse and
 * the caller revokes the family.
 */
final readonly class OAuthTokenRepository
{
    public const KIND_ACCESS  = 'access';
    public const KIND_REFRESH = 'refresh';

    public function __construct(private Connection $conn) {}

    /** @throws DbalException */
    public function store(int $apiTokenId, string $kind, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $this->conn->executeStatement(
            'INSERT INTO oauth_tokens (api_token_id, kind, token_hash, expires_at, rotated_at, created_at)
             VALUES (:grant, :kind, :token_hash, :expires_at, NULL, :created_at)',
            [
                'grant'      => $apiTokenId,
                'kind'       => $kind,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return array{id: int, api_token_id: int, kind: string, expires_at: string, rotated_at: string|null}|null
     * @throws DbalException
     */
    public function findByHash(string $tokenHash): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, api_token_id, kind, expires_at, rotated_at FROM oauth_tokens WHERE token_hash = :token_hash',
            ['token_hash' => $tokenHash],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id'           => (int) $row['id'],
            'api_token_id' => (int) $row['api_token_id'],
            'kind'         => (string) $row['kind'],
            'expires_at'   => (string) $row['expires_at'],
            'rotated_at'   => $row['rotated_at'] !== null ? (string) $row['rotated_at'] : null,
        ];
    }

    /**
     * Marks a refresh token as exchanged. True for exactly one caller.
     *
     * @throws DbalException
     */
    public function markRotated(int $id, \DateTimeImmutable $now): bool
    {
        return $this->conn->executeStatement(
            'UPDATE oauth_tokens SET rotated_at = :now WHERE id = :id AND kind = :kind AND rotated_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $id, 'kind' => self::KIND_REFRESH],
        ) === 1;
    }

    /**
     * Deletes one token (access-token revocation, RFC 7009).
     *
     * @throws DbalException
     */
    public function delete(int $id): void
    {
        $this->conn->executeStatement('DELETE FROM oauth_tokens WHERE id = :id', ['id' => $id]);
    }

    /**
     * Deletes every token that expired before $cutoff — rotated refresh
     * tokens are kept until their own expiry so reuse stays detectable.
     *
     * @throws DbalException
     */
    public function purgeExpired(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->conn->executeStatement(
            'DELETE FROM oauth_tokens WHERE expires_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
