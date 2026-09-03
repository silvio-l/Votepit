<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Support-request persistence (in-dashboard customer contact → operator
 * inbox, entirely in-app — see migrations/0024_add_notifications_remove_support_email.sql).
 * Reachable only from an authenticated account context (see
 * SupportRequestAction — AuthZ::accountAdmin()), so account_id/user_id are
 * always known and NOT NULL, unlike AbuseReportRepository's anonymous-intake
 * shape.
 */
final readonly class SupportRequestRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Creates a new ticket. Returns the new ticket ID.
     *
     * @throws DbalException
     */
    public function create(
        int $accountId,
        int $userId,
        string $category,
        string $subject,
        string $message,
    ): int {
        $this->conn->insert('support_requests', [
            'account_id' => $accountId,
            'user_id'    => $userId,
            'category'   => $category,
            'subject'    => $subject,
            'message'    => $message,
            'status'     => 'open',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Lists every ticket for one account (dashboard "my requests" view),
     * newest first.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, account_id, user_id, category, subject, message,
                    status, operator_reply, replied_by, replied_at, created_at, updated_at
             FROM support_requests WHERE account_id = :account_id ORDER BY created_at DESC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * Lists every ticket for the operator inbox, newest first. Deliberately
     * WITHOUT account scoping — an operator needs to see tickets across all
     * accounts.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForOperator(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, account_id, user_id, category, subject, message,
                    status, operator_reply, replied_by, replied_at, created_at, updated_at
             FROM support_requests ORDER BY (status = \'open\') DESC, created_at DESC',
        );

        return $rows;
    }

    /**
     * Finds a single ticket by ID, scoped to one account (dashboard
     * detail/reply-visibility check — a member may only ever see their own
     * account's tickets).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByIdForAccount(int $id, int $accountId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, user_id, category, subject, message,
                    status, operator_reply, replied_by, replied_at, created_at, updated_at
             FROM support_requests WHERE id = :id AND account_id = :account_id',
            ['id' => $id, 'account_id' => $accountId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Finds a single ticket by ID, unscoped (operator use only).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByIdForOperator(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, user_id, category, subject, message,
                    status, operator_reply, replied_by, replied_at, created_at, updated_at
             FROM support_requests WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Records the operator's reply and marks the ticket 'answered'.
     *
     * @throws DbalException
     */
    public function reply(int $id, string $replyText, int $repliedByUserId): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE support_requests
             SET operator_reply = :reply, replied_by = :replied_by, replied_at = :now,
                 status = \'answered\', updated_at = :now
             WHERE id = :id',
            [
                'reply'      => $replyText,
                'replied_by' => $repliedByUserId,
                'now'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'         => $id,
            ],
        );

        return $affected > 0;
    }

    /**
     * Sets the ticket status directly (e.g. re-open, or close without a
     * reply). Does NOT touch operator_reply/replied_by/replied_at.
     *
     * @throws DbalException
     */
    public function setStatus(int $id, string $status): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE support_requests SET status = :status, updated_at = :now WHERE id = :id',
            [
                'status' => $status,
                'now'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'     => $id,
            ],
        );

        return $affected > 0;
    }

    /**
     * Counts open (unhandled) tickets — surfaced on the operator usage
     * overview, mirroring AbuseReportRepository::countOpen().
     *
     * @throws DbalException
     */
    public function countOpen(): int
    {
        return (int) $this->conn->fetchOne("SELECT COUNT(*) FROM support_requests WHERE status = 'open'");
    }
}
