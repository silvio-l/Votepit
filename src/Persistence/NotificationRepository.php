<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * In-app notification/inbox persistence (see
 * migrations/0024_add_notifications_remove_support_email.sql for the shape
 * rationale). Two kinds share one table via `scope`:
 *   - 'account': targets every member of one account (e.g. a support reply).
 *   - 'broadcast': targets every user on the platform (operator announcement).
 *
 * Read state is per-user (notification_reads), since a single row can target
 * many users.
 */
final readonly class NotificationRepository
{
    public function __construct(private Connection $conn) {}

    /** @throws DbalException */
    public function createForAccount(int $accountId, string $type, string $title, string $body, ?string $linkPath): int
    {
        $this->conn->insert('notifications', [
            'scope'      => 'account',
            'account_id' => $accountId,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'link_path'  => $linkPath,
            'created_by' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /** @throws DbalException */
    public function createBroadcast(int $createdByUserId, string $title, string $body, ?string $linkPath): int
    {
        $this->conn->insert('notifications', [
            'scope'      => 'broadcast',
            'account_id' => null,
            'type'       => 'announcement',
            'title'      => $title,
            'body'       => $body,
            'link_path'  => $linkPath,
            'created_by' => $createdByUserId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Lists every notification visible to one user: broadcasts, plus
     * account-scoped notifications for every account the user is a member
     * of — minus any this user has dismissed from their own inbox. Newest
     * first, with the user's own read state joined in.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForUser(int $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT n.id, n.scope, n.account_id, n.type, n.title, n.body, n.link_path, n.created_at,
                    (r.user_id IS NOT NULL) AS is_read
             FROM notifications n
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :user_id
             LEFT JOIN notification_dismissals d ON d.notification_id = n.id AND d.user_id = :user_id
             WHERE d.user_id IS NULL
               AND (n.scope = \'broadcast\'
                    OR n.account_id IN (SELECT account_id FROM account_members WHERE user_id = :user_id))
             ORDER BY n.created_at DESC',
            ['user_id' => $userId],
        );

        return $rows;
    }

    /** @throws DbalException */
    public function countUnreadForUser(int $userId): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*)
             FROM notifications n
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :user_id
             LEFT JOIN notification_dismissals d ON d.notification_id = n.id AND d.user_id = :user_id
             WHERE r.user_id IS NULL
               AND d.user_id IS NULL
               AND (n.scope = \'broadcast\'
                    OR n.account_id IN (SELECT account_id FROM account_members WHERE user_id = :user_id))',
            ['user_id' => $userId],
        );
    }

    /**
     * Whether notification $notificationId is visible to $userId — a
     * broadcast, or account-scoped to an account the user is a member of.
     * Mirrors the scoping in listForUser()/countUnreadForUser(): every
     * caller that acts on a single notification id (e.g. markRead) must
     * gate on this first, or a logged-in user could probe/act on another
     * account's notification ids (cross-tenant leak, see CLAUDE.md
     * "Account scoping is structurally watertight").
     *
     * @throws DbalException
     */
    public function isVisibleToUser(int $notificationId, int $userId): bool
    {
        $scope = $this->conn->fetchOne(
            'SELECT 1
             FROM notifications n
             WHERE n.id = :id
               AND (n.scope = \'broadcast\'
                    OR n.account_id IN (SELECT account_id FROM account_members WHERE user_id = :user_id))',
            ['id' => $notificationId, 'user_id' => $userId],
        );

        return $scope !== false;
    }

    /**
     * Marks one notification read for one user. Idempotent — a second call
     * for an already-read notification is a no-op (portable duplicate
     * handling, same rationale as ModerationConfigRepository::addWord():
     * DBAL has no universal "INSERT IGNORE" across SQLite/MySQL).
     *
     * @throws DbalException
     */
    public function markRead(int $notificationId, int $userId): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO notification_reads (notification_id, user_id, read_at)
                 VALUES (:notification_id, :user_id, :now)',
                [
                    'notification_id' => $notificationId,
                    'user_id'         => $userId,
                    'now'             => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Already read — ignore silently (idempotent operation).
        }
    }

    /**
     * Removes one notification from one user's own inbox view, permanently.
     * Idempotent, same rationale/pattern as markRead(): a second dismiss of
     * an already-dismissed notification is a no-op. Never touches the
     * underlying notifications row or any other user's view — dismissing a
     * broadcast only hides it for the dismissing user.
     *
     * @throws DbalException
     */
    public function dismiss(int $notificationId, int $userId): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO notification_dismissals (notification_id, user_id, dismissed_at)
                 VALUES (:notification_id, :user_id, :now)',
                [
                    'notification_id' => $notificationId,
                    'user_id'         => $userId,
                    'now'             => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Already dismissed — ignore silently (idempotent operation).
        }
    }

    /**
     * Lists every broadcast announcement (operator management view), newest
     * first.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAnnouncements(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            "SELECT id, title, body, link_path, created_by, created_at
             FROM notifications WHERE scope = 'broadcast' ORDER BY created_at DESC",
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, scope, account_id, type, title, body, link_path, created_by, created_at
             FROM notifications WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /** @throws DbalException */
    public function deleteAnnouncement(int $id): bool
    {
        $affected = $this->conn->executeStatement(
            "DELETE FROM notifications WHERE id = :id AND scope = 'broadcast'",
            ['id' => $id],
        );

        return $affected > 0;
    }
}
