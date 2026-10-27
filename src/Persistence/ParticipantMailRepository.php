<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Votepit\Mail\MailLanguage;
use Votepit\Participant\ParticipantMailCategory;

/**
 * Persistence for participant mails (close-the-loop slice 03, re-engagement
 * B/C/F/G): per-category cadence, the batched queue, idempotency claims,
 * guest suppressions, and the opt-in new-ideas state.
 *
 * Every query is parameterised. Upserts are dialect-aware (MySQL in
 * production, SQLite in the test harness), the same split as
 * CronJobRepository. The queue carries no account or board scoping of its
 * own beyond the board_id column: rows are written only by the status and
 * comment fan-outs, which have already scoped the idea to its board.
 */
final readonly class ParticipantMailRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * The stored cadence for a category, or the category default when the
     * user has no row (migration 0105).
     *
     * @throws DbalException
     */
    public function cadenceFor(int $userId, string $category): string
    {
        $value = $this->conn->fetchOne(
            'SELECT cadence FROM participant_mail_preferences WHERE user_id = :user_id AND category = :category',
            ['user_id' => $userId, 'category' => $category],
        );

        return is_string($value) && ParticipantMailCategory::isValidCadence($category, $value)
            ? $value
            : ParticipantMailCategory::defaultCadence($category);
    }

    /**
     * Every category with its effective cadence, for the preferences screen.
     *
     * @return array<string, string>
     * @throws DbalException
     */
    public function cadencesFor(int $userId): array
    {
        $result = [];
        foreach (ParticipantMailCategory::ALL as $category) {
            $result[$category] = $this->cadenceFor($userId, $category);
        }

        return $result;
    }

    /** @throws DbalException */
    public function setCadence(int $userId, string $category, string $cadence): void
    {
        $params = ['user_id' => $userId, 'category' => $category, 'cadence' => $cadence];

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT INTO participant_mail_preferences (user_id, category, cadence)
                 VALUES (:user_id, :category, :cadence)
                 ON DUPLICATE KEY UPDATE cadence = :cadence2',
                $params + ['cadence2' => $cadence],
            );

            return;
        }

        $this->conn->executeStatement(
            'INSERT INTO participant_mail_preferences (user_id, category, cadence)
             VALUES (:user_id, :category, :cadence)
             ON CONFLICT(user_id, category) DO UPDATE SET cadence = excluded.cadence',
            $params,
        );
    }

    /**
     * Last app visit, falling back to the account creation date (see
     * ParticipantMailPolicy). Null only when the user does not exist.
     *
     * @throws DbalException
     */
    public function activityBaseline(int $userId): ?\DateTimeImmutable
    {
        $value = $this->conn->fetchOne(
            'SELECT COALESCE(last_seen_at, created_at) FROM users WHERE id = :id',
            ['id' => $userId],
        );

        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /**
     * Queues one participant mail item (rows are never deduplicated: a
     * recipient with two changes gets both in the same digest).
     *
     * @param array{recipient_user_id: ?int, recipient_key: ?string, recipient_email_enc: ?string, category: string, board_id: int, idea_id: int, event_kind: string, payload: string, link_path: string} $row
     * @throws DbalException
     */
    public function enqueue(array $row, \DateTimeImmutable $now): void
    {
        $this->conn->executeStatement(
            'INSERT INTO participant_mail_queue
                (recipient_user_id, recipient_key, recipient_email_enc, category, board_id, idea_id, event_kind, payload, link_path, created_at)
             VALUES
                (:recipient_user_id, :recipient_key, :recipient_email_enc, :category, :board_id, :idea_id, :event_kind, :payload, :link_path, :created_at)',
            [
                'recipient_user_id'   => $row['recipient_user_id'],
                'recipient_key'       => $row['recipient_key'],
                'recipient_email_enc' => $row['recipient_email_enc'],
                'category'            => $row['category'],
                'board_id'            => $row['board_id'],
                'idea_id'             => $row['idea_id'],
                'event_kind'          => $row['event_kind'],
                'payload'             => $row['payload'],
                'link_path'           => $row['link_path'],
                'created_at'          => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Registered users with at least one pending queue row, oldest first.
     *
     * @return list<int>
     * @throws DbalException
     */
    public function pendingUserIds(int $limit): array
    {
        $ids = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT recipient_user_id FROM participant_mail_queue
             WHERE handled_at IS NULL AND recipient_user_id IS NOT NULL
             ORDER BY recipient_user_id ASC
             LIMIT :limit',
            ['limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /**
     * Guests (anonymous-vote notification e-mail) with pending rows.
     *
     * @return list<string> keyed HMAC hashes
     * @throws DbalException
     */
    public function pendingGuestKeys(int $limit): array
    {
        $keys = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT recipient_key FROM participant_mail_queue
             WHERE handled_at IS NULL AND recipient_key IS NOT NULL
             ORDER BY recipient_key ASC
             LIMIT :limit',
            ['limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (mixed $key): string => (string) $key, $keys);
    }

    /**
     * @return list<array{id: int, category: string, board_id: int, idea_id: int, event_kind: string, payload: string, link_path: string, created_at: string}>
     * @throws DbalException
     */
    public function pendingForUser(int $userId, int $limit): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, category, board_id, idea_id, event_kind, payload, link_path, created_at
             FROM participant_mail_queue
             WHERE handled_at IS NULL AND recipient_user_id = :user_id
             ORDER BY id ASC
             LIMIT :limit',
            ['user_id' => $userId, 'limit' => $limit],
            ['user_id' => \Doctrine\DBAL\ParameterType::INTEGER, 'limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(self::hydrateRow(...), $rows);
    }

    /**
     * @return list<array{id: int, category: string, board_id: int, idea_id: int, event_kind: string, payload: string, link_path: string, created_at: string, recipient_email_enc: string}>
     * @throws DbalException
     */
    public function pendingForGuest(string $guestKey, int $limit): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, category, board_id, idea_id, event_kind, payload, link_path, created_at, recipient_email_enc
             FROM participant_mail_queue
             WHERE handled_at IS NULL AND recipient_key = :guest_key
             ORDER BY id ASC
             LIMIT :limit',
            ['guest_key' => $guestKey, 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $r): array => self::hydrateRow($r) + ['recipient_email_enc' => (string) $r['recipient_email_enc']],
            $rows,
        );
    }

    /**
     * Marks queue rows as handled. Only rows still pending are touched, so a
     * concurrent run cannot mark (or re-send) the same row twice.
     *
     * @param list<int> $ids
     * @throws DbalException
     */
    public function markHandled(array $ids, string $outcome, \DateTimeImmutable $at): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = [];
        $params       = ['outcome' => $outcome, 'at' => $at->format('Y-m-d H:i:s')];
        foreach ($ids as $i => $id) {
            $placeholders[]    = ':id' . $i;
            $params['id' . $i] = $id;
        }

        $this->conn->executeStatement(
            'UPDATE participant_mail_queue SET handled_at = :at, outcome = :outcome
             WHERE handled_at IS NULL AND id IN (' . implode(', ', $placeholders) . ')',
            $params,
        );
    }

    /**
     * Claims one (recipient, period) slot before a send. False when the slot
     * was already claimed — the caller must not send and must leave its rows
     * pending.
     *
     * @throws DbalException
     */
    public function claim(string $recipientKey, string $periodKey, \DateTimeImmutable $at): bool
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO participant_digest_sends (recipient_key, period_key, sent_at) VALUES (:recipient_key, :period_key, :sent_at)',
                ['recipient_key' => $recipientKey, 'period_key' => $periodKey, 'sent_at' => $at->format('Y-m-d H:i:s')],
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Gives a claim back after a failed send, so the next run can retry.
     *
     * @throws DbalException
     */
    public function releaseClaim(string $recipientKey, string $periodKey): void
    {
        $this->conn->executeStatement(
            'DELETE FROM participant_digest_sends WHERE recipient_key = :recipient_key AND period_key = :period_key',
            ['recipient_key' => $recipientKey, 'period_key' => $periodKey],
        );
    }

    /**
     * Guest unsubscribe: stores the suppression and drops the guest's pending
     * rows in the same call (a suppressed guest keeps no queued data).
     *
     * @throws DbalException
     */
    public function suppressGuest(string $guestKey, \DateTimeImmutable $at): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO participant_guest_suppressions (recipient_key, created_at) VALUES (:key, :at)',
                ['key' => $guestKey, 'at' => $at->format('Y-m-d H:i:s')],
            );
        } catch (UniqueConstraintViolationException) {
            // Already suppressed — idempotent.
        }

        $this->conn->executeStatement(
            'DELETE FROM participant_mail_queue WHERE recipient_key = :key AND handled_at IS NULL',
            ['key' => $guestKey],
        );
    }

    /** @throws DbalException */
    public function isGuestSuppressed(string $guestKey): bool
    {
        return $this->conn->fetchOne(
            'SELECT 1 FROM participant_guest_suppressions WHERE recipient_key = :key',
            ['key' => $guestKey],
        ) !== false;
    }

    /**
     * Retention: handled queue rows and old claims are removed. Pending rows
     * are never touched here.
     *
     * @throws DbalException
     */
    public function purgeOld(\DateTimeImmutable $handledBefore, \DateTimeImmutable $sendsBefore): void
    {
        $this->conn->executeStatement(
            'DELETE FROM participant_mail_queue WHERE handled_at IS NOT NULL AND handled_at < :handled_before',
            ['handled_before' => $handledBefore->format('Y-m-d H:i:s')],
        );
        $this->conn->executeStatement(
            'DELETE FROM participant_digest_sends WHERE sent_at < :sends_before',
            ['sends_before' => $sendsBefore->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Opt-in new-ideas subscribers: explicit weekly cadence, a confirmed
     * notification address, not blocked, not a placeholder.
     *
     * @return list<int>
     * @throws DbalException
     */
    public function newIdeasSubscriberIds(int $limit): array
    {
        $ids = $this->conn->fetchFirstColumn(
            'SELECT pmp.user_id
             FROM participant_mail_preferences pmp
             INNER JOIN users u ON u.id = pmp.user_id
             WHERE pmp.category = :category
               AND pmp.cadence = :cadence
               AND u.notification_email IS NOT NULL
               AND u.is_blocked = 0
               AND u.is_deleted_placeholder = 0
               AND u.is_guest_placeholder = 0
             ORDER BY pmp.user_id ASC
             LIMIT :limit',
            ['category' => ParticipantMailCategory::NEW_IDEAS, 'cadence' => ParticipantMailCategory::CADENCE_WEEKLY, 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /**
     * When the opt-in new-ideas digest last covered this board for the user,
     * or null when it never did.
     *
     * @throws DbalException
     */
    public function newIdeasCheckedAt(int $userId, int $boardId): ?\DateTimeImmutable
    {
        $value = $this->conn->fetchOne(
            'SELECT checked_at FROM participant_new_ideas_state WHERE user_id = :user_id AND board_id = :board_id',
            ['user_id' => $userId, 'board_id' => $boardId],
        );

        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /** @throws DbalException */
    public function setNewIdeasCheckedAt(int $userId, int $boardId, \DateTimeImmutable $at): void
    {
        $params = ['user_id' => $userId, 'board_id' => $boardId, 'checked_at' => $at->format('Y-m-d H:i:s')];

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT INTO participant_new_ideas_state (user_id, board_id, checked_at)
                 VALUES (:user_id, :board_id, :checked_at)
                 ON DUPLICATE KEY UPDATE checked_at = :checked_at2',
                $params + ['checked_at2' => $at->format('Y-m-d H:i:s')],
            );

            return;
        }

        $this->conn->executeStatement(
            'INSERT INTO participant_new_ideas_state (user_id, board_id, checked_at)
             VALUES (:user_id, :board_id, :checked_at)
             ON CONFLICT(user_id, board_id) DO UPDATE SET checked_at = excluded.checked_at',
            $params,
        );
    }

    /**
     * Delivery address and digest language of a registered recipient. `usable`
     * is false for blocked users, placeholders and users without a confirmed
     * notification address: their pending rows are dropped, not sent.
     *
     * @return array{email: ?string, language: string, usable: bool}|null
     * @throws DbalException
     */
    public function userRecipient(int $userId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT notification_email, digest_language, is_blocked, is_deleted_placeholder, is_guest_placeholder
             FROM users WHERE id = :id',
            ['id' => $userId],
        );
        if (!is_array($row)) {
            return null;
        }

        $email = $row['notification_email'] === null ? null : (string) $row['notification_email'];

        return [
            'email'    => $email,
            'language' => MailLanguage::resolve($row['digest_language']),
            'usable'   => $email !== null
                && (int) $row['is_blocked'] === 0
                && (int) $row['is_deleted_placeholder'] === 0
                && (int) $row['is_guest_placeholder'] === 0,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, category: string, board_id: int, idea_id: int, event_kind: string, payload: string, link_path: string, created_at: string}
     */
    private static function hydrateRow(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'category'   => (string) $r['category'],
            'board_id'   => (int) $r['board_id'],
            'idea_id'    => (int) $r['idea_id'],
            'event_kind' => (string) $r['event_kind'],
            'payload'    => (string) $r['payload'],
            'link_path'  => (string) $r['link_path'],
            'created_at' => (string) $r['created_at'],
        ];
    }
}
