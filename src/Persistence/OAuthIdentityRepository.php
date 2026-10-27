<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Links a provider's stable subject id (`provider_user_id` — Google's `sub`,
 * GitHub's numeric `id`) to a Votepit users.id — migrations/0052_add_oauth_login.sql.
 *
 * NEVER stores an email in any form (ADR 0002) — account linking runs
 * exclusively over UserRepository::findByEmailHmac()/create(), this table
 * only ever sees the provider's opaque subject id.
 *
 * UNIQUE (provider, provider_user_id) is the DB-level backstop against
 * double-linking the same provider identity to two different users.
 */
final readonly class OAuthIdentityRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByProviderUserId(string $provider, string $providerUserId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, user_id, provider, provider_user_id, created_at
             FROM oauth_identities WHERE provider = :provider AND provider_user_id = :provider_user_id',
            ['provider' => $provider, 'provider_user_id' => $providerUserId],
        );

        return $row === false ? null : $row;
    }

    /** @throws DbalException */
    public function link(int $userId, string $provider, string $providerUserId): void
    {
        $this->conn->insert('oauth_identities', [
            'user_id'          => $userId,
            'provider'         => $provider,
            'provider_user_id' => $providerUserId,
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Lists the providers linked to a user — for the account-settings "linked
     * accounts" view. Never returns provider_user_id (opaque subject id,
     * no reason to leak it to the client).
     *
     * @return list<array{provider: string, created_at: string}>
     * @throws DbalException
     */
    public function findAllByUserId(int $userId): array
    {
        /** @var list<array{provider: string, created_at: string}> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT provider, created_at FROM oauth_identities WHERE user_id = :user_id ORDER BY created_at ASC',
            ['user_id' => $userId],
        );

        return $rows;
    }

    /**
     * Removes one linked provider from a user's account. Returns whether a
     * row was actually deleted (false = wasn't linked in the first place).
     *
     * @throws DbalException
     */
    public function unlink(int $userId, string $provider): bool
    {
        $affected = $this->conn->delete('oauth_identities', [
            'user_id'  => $userId,
            'provider' => $provider,
        ]);

        return $affected > 0;
    }
}
