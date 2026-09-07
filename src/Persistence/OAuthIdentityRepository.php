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
}
