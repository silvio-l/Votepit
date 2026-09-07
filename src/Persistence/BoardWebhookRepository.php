<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistence for board_webhooks / board_webhook_deliveries
 * (board-webhooks feature, see migrations/0053_add_board_webhooks.sql).
 *
 * Prepared-statements-only via DBAL. One webhook per board (board_id
 * UNIQUE). The signing secret is stored ENCRYPTED (EncryptionService,
 * context 'board_webhook') — not hashed like ApiTokenRepository, because
 * WebhookDispatcher needs the plaintext again at delivery time to compute
 * the outgoing HMAC signature; encrypt/decrypt happens in the caller
 * (Http\Action\BoardWebhookAction / Webhook\WebhookDispatcher), this
 * repository only ever stores/returns the opaque encrypted blob.
 *
 * Every account-facing method binds account_id via a JOIN on boards — a
 * webhook belonging to a foreign account can structurally never be read,
 * rotated or deleted from here (same discipline as ApiTokenRepository).
 */
final readonly class BoardWebhookRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Active webhook for a board — the ONLY lookup WebhookDispatcher uses
     * (event fan-out is board-scoped, not account-scoped: it already runs
     * with a trusted, server-resolved board_id, no tenant input to check).
     *
     * @return array{id: int, board_id: int, url: string, secret_encrypted: string}|null
     * @throws DbalException
     */
    public function findActiveForBoard(int $boardId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, board_id, url, secret_encrypted
               FROM board_webhooks
              WHERE board_id = :board_id AND active = 1',
            ['board_id' => $boardId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id'               => (int) $row['id'],
            'board_id'         => (int) $row['board_id'],
            'url'              => (string) $row['url'],
            'secret_encrypted' => (string) $row['secret_encrypted'],
        ];
    }

    /**
     * Admin-facing lookup — account-scoped, returns active AND inactive
     * rows (the admin UI shows the current config either way). NEVER
     * returns secret_encrypted — the secret is shown to the admin exactly
     * once, at creation/rotation time, from the caller's return value, not
     * from a subsequent read.
     *
     * @return array{id: int, board_id: int, url: string, active: bool, created_at: string, updated_at: string}|null
     * @throws DbalException
     */
    public function findForAccountBoard(int $accountId, int $boardId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT w.id, w.board_id, w.url, w.active, w.created_at, w.updated_at
               FROM board_webhooks w
               INNER JOIN boards b ON b.id = w.board_id
              WHERE w.board_id = :board_id AND b.account_id = :account_id',
            ['board_id' => $boardId, 'account_id' => $accountId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'board_id'   => (int) $row['board_id'],
            'url'        => (string) $row['url'],
            'active'     => (bool) $row['active'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Creates or replaces (UPSERT on the board_id UNIQUE key) the board's
     * webhook, always re-activating it and issuing a fresh secret — this is
     * both "create" and "rotate secret" in one call, matching the "at most
     * one webhook per board" model. Account-scoped: the caller must have
     * already verified $boardId belongs to $accountId (BoardRepository::
     * findBySlugForAccount(), same pattern as every other board-admin
     * action) — this method itself does not re-check accountId (no
     * account_id column on board_webhooks to bind it against; it is
     * enforced by the caller resolving boardId account-scoped first).
     *
     * @return int the webhook's id
     * @throws DbalException
     */
    public function save(int $boardId, string $url, string $secretEncrypted): int
    {
        $existingId = $this->conn->fetchOne(
            'SELECT id FROM board_webhooks WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($existingId === false) {
            $this->conn->insert('board_webhooks', [
                'board_id'         => $boardId,
                'url'              => $url,
                'secret_encrypted' => $secretEncrypted,
                'active'           => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            return (int) $this->conn->lastInsertId();
        }

        $id = (int) $existingId;
        $this->conn->update('board_webhooks', [
            'url'              => $url,
            'secret_encrypted' => $secretEncrypted,
            'active'           => 1,
            'updated_at'       => $now,
        ], ['id' => $id]);

        return $id;
    }

    /**
     * Sets active/inactive (pause without losing the URL/secret) —
     * account-scoped via a subquery on boards.
     *
     * @throws DbalException
     */
    public function setActive(int $accountId, int $boardId, bool $active): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE board_webhooks
                SET active = :active, updated_at = :now
              WHERE board_id = :board_id
                AND board_id IN (SELECT id FROM boards WHERE account_id = :account_id)',
            [
                'active'     => $active ? 1 : 0,
                'now'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'board_id'   => $boardId,
                'account_id' => $accountId,
            ],
        );

        return $affected === 1;
    }

    /**
     * Deletes the board's webhook configuration entirely — account-scoped.
     *
     * @throws DbalException
     */
    public function delete(int $accountId, int $boardId): bool
    {
        $affected = $this->conn->executeStatement(
            'DELETE FROM board_webhooks
              WHERE board_id = :board_id
                AND board_id IN (SELECT id FROM boards WHERE account_id = :account_id)',
            ['board_id' => $boardId, 'account_id' => $accountId],
        );

        return $affected === 1;
    }

    /**
     * Appends one delivery-attempt log row — best-effort telemetry, the
     * caller (WebhookDispatcher) must swallow any DbalException here (a
     * broken log write must never turn into a second failure mode on top
     * of an already-failed delivery).
     *
     * @throws DbalException
     */
    public function recordDelivery(int $boardWebhookId, string $event, bool $success, ?int $statusCode, ?string $error): void
    {
        $this->conn->insert('board_webhook_deliveries', [
            'board_webhook_id' => $boardWebhookId,
            'event'            => $event,
            'success'          => $success ? 1 : 0,
            'status_code'      => $statusCode,
            'error'            => $error !== null ? substr($error, 0, 255) : null,
            'attempted_at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Recent delivery log for the admin UI — account-scoped, newest first,
     * capped.
     *
     * @return list<array{event: string, success: bool, status_code: int|null, error: string|null, attempted_at: string}>
     * @throws DbalException
     */
    public function recentDeliveries(int $accountId, int $boardId, int $limit = 20): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT d.event, d.success, d.status_code, d.error, d.attempted_at
               FROM board_webhook_deliveries d
               INNER JOIN board_webhooks w ON w.id = d.board_webhook_id
               INNER JOIN boards b ON b.id = w.board_id
              WHERE w.board_id = :board_id AND b.account_id = :account_id
              ORDER BY d.attempted_at DESC, d.id DESC
              LIMIT ' . max(1, min(100, $limit)),
            ['board_id' => $boardId, 'account_id' => $accountId],
        );

        return array_map(static fn (array $row): array => [
            'event'        => (string) $row['event'],
            'success'      => (bool) $row['success'],
            'status_code'  => $row['status_code'] !== null ? (int) $row['status_code'] : null,
            'error'        => $row['error'] !== null ? (string) $row['error'] : null,
            'attempted_at' => (string) $row['attempted_at'],
        ], $rows);
    }
}
