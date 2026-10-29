<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistence for board_chat_integrations (Slack/Discord chat
 * notifications, migrations/0079_add_board_chat_integrations.sql).
 *
 * Prepared statements only. Stores/returns the webhook URL only as the
 * opaque encrypted blob — encrypt/decrypt happens in the callers
 * (Http\Action\BoardChatNotificationAction, Webhook\Chat\
 * ChatNotificationDispatcher), same split as BoardWebhookRepository.
 *
 * Account scoping: every admin-facing method binds account_id through the
 * boards table, so a foreign account's board can structurally never be
 * read, written or deleted from here — save() included (defense in depth on
 * top of the caller's own account-scoped board lookup).
 */
final readonly class BoardChatIntegrationRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Everything the dispatcher needs for one board event in ONE query:
     * the configured integrations plus board name/slug, account slug and
     * the account's plan (re-checked on every dispatch so a downgrade stops
     * delivery). Board-scoped, not account-scoped: it runs with a trusted,
     * server-resolved board_id.
     *
     * @return list<array{id: int, provider: string, url_encrypted: string, board_name: string, board_slug: string, account_slug: string, account_id: int}>
     * @throws DbalException
     */
    public function findForDispatch(int $boardId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT c.id, c.provider, c.url_encrypted, b.name AS board_name, b.slug AS board_slug,
                    a.id AS account_id, a.slug AS account_slug
               FROM board_chat_integrations c
               INNER JOIN boards b ON b.id = c.board_id
               INNER JOIN accounts a ON a.id = b.account_id
              WHERE c.board_id = :board_id
              ORDER BY c.id',
            ['board_id' => $boardId],
        );

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'provider'      => (string) $r['provider'],
            'url_encrypted' => (string) $r['url_encrypted'],
            'board_name'    => (string) $r['board_name'],
            'board_slug'    => (string) $r['board_slug'],
            'account_slug'  => (string) $r['account_slug'],
            'account_id'    => (int) $r['account_id'],
        ], $rows);
    }

    /**
     * Admin view — never returns url_encrypted.
     *
     * @return list<array{provider: string, url_hint: string, last_delivery_at: string|null, last_delivery_ok: bool|null, last_delivery_error: string|null, updated_at: string}>
     * @throws DbalException
     */
    public function listForAccountBoard(int $accountId, int $boardId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT c.provider, c.url_hint, c.last_delivery_at, c.last_delivery_ok, c.last_delivery_error, c.updated_at
               FROM board_chat_integrations c
               INNER JOIN boards b ON b.id = c.board_id
              WHERE c.board_id = :board_id AND b.account_id = :account_id
              ORDER BY c.provider',
            ['board_id' => $boardId, 'account_id' => $accountId],
        );

        return array_map(static fn (array $r): array => [
            'provider'            => (string) $r['provider'],
            'url_hint'            => (string) $r['url_hint'],
            'last_delivery_at'    => $r['last_delivery_at'] !== null ? (string) $r['last_delivery_at'] : null,
            'last_delivery_ok'    => $r['last_delivery_ok'] !== null ? (bool) $r['last_delivery_ok'] : null,
            'last_delivery_error' => $r['last_delivery_error'] !== null ? (string) $r['last_delivery_error'] : null,
            'updated_at'          => (string) $r['updated_at'],
        ], $rows);
    }

    /**
     * Creates or replaces the board's integration for $provider (resets the
     * delivery status). Returns false — and writes nothing — when $boardId
     * does not belong to $accountId.
     *
     * @throws DbalException
     */
    public function save(int $accountId, int $boardId, string $provider, string $urlEncrypted, string $urlHint): bool
    {
        $owned = $this->conn->fetchOne(
            'SELECT 1 FROM boards WHERE id = :board_id AND account_id = :account_id',
            ['board_id' => $boardId, 'account_id' => $accountId],
        );
        if ($owned === false) {
            return false;
        }

        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $match = ['board_id' => $boardId, 'provider' => $provider];

        $existingId = $this->conn->fetchOne(
            'SELECT id FROM board_chat_integrations WHERE board_id = :board_id AND provider = :provider',
            $match,
        );

        $fields = [
            'url_encrypted'       => $urlEncrypted,
            'url_hint'            => $urlHint,
            'last_delivery_at'    => null,
            'last_delivery_ok'    => null,
            'last_delivery_error' => null,
            'updated_at'          => $now,
        ];

        if ($existingId === false) {
            $this->conn->insert('board_chat_integrations', [...$match, ...$fields, 'created_at' => $now]);
        } else {
            $this->conn->update('board_chat_integrations', $fields, ['id' => (int) $existingId]);
        }

        return true;
    }

    /** @throws DbalException */
    public function delete(int $accountId, int $boardId, string $provider): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM board_chat_integrations
              WHERE board_id = :board_id AND provider = :provider
                AND board_id IN (SELECT id FROM boards WHERE account_id = :account_id)',
            ['board_id' => $boardId, 'provider' => $provider, 'account_id' => $accountId],
        );

        return $affected === 1;
    }

    /**
     * Best-effort status write after a delivery — callers swallow failures.
     *
     * @throws DbalException
     */
    public function recordDelivery(int $id, bool $success, ?string $error): void
    {
        $this->conn->update('board_chat_integrations', [
            'last_delivery_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_delivery_ok'    => $success ? 1 : 0,
            'last_delivery_error' => $error !== null ? substr($error, 0, 64) : null,
        ], ['id' => $id]);
    }
}
