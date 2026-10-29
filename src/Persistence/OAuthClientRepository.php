<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Dynamically registered MCP OAuth clients (RFC 7591, migration 0088).
 * Prepared statements only.
 *
 * A client is installation-wide, not tenant-owned: it registers before any
 * user has logged in and identifies only the software, never an account.
 * Everything tenant-scoped hangs off the GRANT (an api_tokens row carrying
 * account_id + oauth_client_id), so a client id alone authorizes nothing.
 * Public clients only — there is no client secret column.
 */
final readonly class OAuthClientRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @param non-empty-list<string> $redirectUris already validated (ClientRegistrationValidator)
     * @throws DbalException
     */
    public function create(string $clientId, string $clientName, array $redirectUris, \DateTimeImmutable $now): void
    {
        $this->conn->executeStatement(
            'INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at)
             VALUES (:client_id, :client_name, :redirect_uris, :created_at)',
            [
                'client_id'     => $clientId,
                'client_name'   => $clientName,
                'redirect_uris' => (string) json_encode($redirectUris, JSON_UNESCAPED_SLASHES),
                'created_at'    => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return array{id: int, client_id: string, client_name: string, redirect_uris: list<string>}|null
     * @throws DbalException
     */
    public function findByClientId(string $clientId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, client_id, client_name, redirect_uris FROM oauth_clients WHERE client_id = :client_id',
            ['client_id' => $clientId],
        );

        if ($row === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $row['redirect_uris'], true);
        $uris    = is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];

        return [
            'id'            => (int) $row['id'],
            'client_id'     => (string) $row['client_id'],
            'client_name'   => (string) $row['client_name'],
            'redirect_uris' => $uris,
        ];
    }

    /**
     * Housekeeping on every new registration: deletes clients registered
     * before $cutoff that never obtained a grant and hold no pending
     * authorization code — abandoned registrations must not accumulate.
     *
     * @throws DbalException
     */
    public function purgeUnused(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->conn->executeStatement(
            'DELETE FROM oauth_clients
             WHERE created_at < :cutoff
               AND NOT EXISTS (SELECT 1 FROM api_tokens t WHERE t.oauth_client_id = oauth_clients.id)
               AND NOT EXISTS (SELECT 1 FROM oauth_authorization_codes c WHERE c.oauth_client_id = oauth_clients.id)',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
