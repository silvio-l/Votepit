<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistence for account_issue_trackers (Linear/GitHub connections,
 * migrations/0080_add_issue_tracker_links.sql).
 *
 * Prepared statements only. Token and webhook secret are stored and
 * returned only as opaque encrypted blobs — encryption happens in the
 * callers. Every admin-facing method is bound to account_id; the only
 * lookup without an account is findByWebhookId(), used by the inbound
 * webhook to resolve WHICH connection (and therefore which account) a
 * delivery belongs to before its signature is verified.
 *
 * @phpstan-type ConnectionRow array{id: int, account_id: int, provider: string, token_encrypted: string, token_hint: string, target: string, target_id: string, webhook_id: string, webhook_secret_encrypted: string|null, updated_at: string}
 */
final readonly class IssueTrackerConnectionRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @return list<ConnectionRow>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM account_issue_trackers WHERE account_id = :account_id ORDER BY provider',
            ['account_id' => $accountId],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @return ConnectionRow|null
     * @throws DbalException
     */
    public function findForAccount(int $accountId, string $provider): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT * FROM account_issue_trackers WHERE account_id = :account_id AND provider = :provider',
            ['account_id' => $accountId, 'provider' => $provider],
        );

        return is_array($row) ? $this->map($row) : null;
    }

    /**
     * Webhook routing: the connection a delivery is addressed to. The plan is
     * resolved per delivery by IssueTrackerWebhookAction (AccountPlanResolver),
     * so a downgrade stops sync.
     *
     * @return array{connection: ConnectionRow}|null
     * @throws DbalException
     */
    public function findByWebhookId(string $provider, string $webhookId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT t.*
               FROM account_issue_trackers t
               INNER JOIN accounts a ON a.id = t.account_id
              WHERE t.webhook_id = :webhook_id AND t.provider = :provider',
            ['webhook_id' => $webhookId, 'provider' => $provider],
        );

        return is_array($row) ? ['connection' => $this->map($row)] : null;
    }

    /**
     * Creates or replaces the account's connection for $provider. On
     * replace the webhook id is kept (the URL configured at the provider
     * stays valid); the webhook secret is only replaced when a new one is
     * given. Returns the stored row.
     *
     * @return ConnectionRow
     * @throws DbalException
     */
    public function save(
        int $accountId,
        string $provider,
        string $tokenEncrypted,
        string $tokenHint,
        string $target,
        string $targetId,
        ?string $webhookSecretEncrypted,
    ): array {
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $existing = $this->findForAccount($accountId, $provider);

        $fields = [
            'token_encrypted' => $tokenEncrypted,
            'token_hint'      => $tokenHint,
            'target'          => $target,
            'target_id'       => $targetId,
            'updated_at'      => $now,
        ];
        if ($webhookSecretEncrypted !== null) {
            $fields['webhook_secret_encrypted'] = $webhookSecretEncrypted;
        }

        if ($existing === null) {
            $this->conn->insert('account_issue_trackers', [
                'account_id' => $accountId,
                'provider'   => $provider,
                'webhook_id' => bin2hex(random_bytes(16)),
                'created_at' => $now,
                ...$fields,
            ]);
        } else {
            $this->conn->update('account_issue_trackers', $fields, ['id' => $existing['id'], 'account_id' => $accountId]);
        }

        return $this->findForAccount($accountId, $provider) ?? throw new \LogicException('issue tracker connection vanished after save');
    }

    /** @throws DbalException */
    public function delete(int $accountId, string $provider): bool
    {
        return $this->conn->executeStatement(
            'DELETE FROM account_issue_trackers WHERE account_id = :account_id AND provider = :provider',
            ['account_id' => $accountId, 'provider' => $provider],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $r
     * @return ConnectionRow
     */
    private function map(array $r): array
    {
        return [
            'id'                       => (int) $r['id'],
            'account_id'               => (int) $r['account_id'],
            'provider'                 => (string) $r['provider'],
            'token_encrypted'          => (string) $r['token_encrypted'],
            'token_hint'               => (string) $r['token_hint'],
            'target'                   => (string) $r['target'],
            'target_id'                => (string) $r['target_id'],
            'webhook_id'               => (string) $r['webhook_id'],
            'webhook_secret_encrypted' => $r['webhook_secret_encrypted'] !== null ? (string) $r['webhook_secret_encrypted'] : null,
            'updated_at'               => (string) $r['updated_at'],
        ];
    }
}
