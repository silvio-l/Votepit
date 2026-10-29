<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Support-ticket persistence (in-dashboard customer contact ↔ operator
 * inbox, entirely in-app — see migrations/0024_add_notifications_remove_support_email.sql).
 * Reachable only from an authenticated account context (see
 * SupportRequestAction — AuthZ::accountAdmin()), so account_id/user_id are
 * always known and NOT NULL, unlike AbuseReportRepository's anonymous-intake
 * shape.
 *
 * support_requests is the ticket header (category, subject, status);
 * support_messages (migrations/0026_add_support_messages.sql) is the
 * ordered thread — both the account side and the operator side can post
 * messages to the same ticket over time, unlike the single-reply shape this
 * replaced.
 */
final readonly class SupportRequestRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Creates a new ticket with its opening message. Returns the new ticket ID.
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
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->insert('support_requests', [
            'account_id' => $accountId,
            'user_id'    => $userId,
            'category'   => $category,
            'subject'    => $subject,
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $requestId = (int) $this->conn->lastInsertId();

        $this->conn->insert('support_messages', [
            'request_id'     => $requestId,
            'author_type'    => 'customer',
            'author_user_id' => $userId,
            'body'           => $message,
            'created_at'     => $now,
        ]);

        return $requestId;
    }

    /**
     * Appends a message to an existing ticket's thread and updates the
     * ticket's status: a customer message (re)opens the ticket — it needs
     * operator attention, whether it's the first follow-up or a reply that
     * reopens a closed ticket; an operator message marks it 'answered'.
     * Explicit status changes without a message (e.g. closing without a
     * final reply) go through setStatus() instead.
     *
     * @throws DbalException
     */
    public function addMessage(int $requestId, string $authorType, int $authorUserId, string $body): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->insert('support_messages', [
            'request_id'     => $requestId,
            'author_type'    => $authorType,
            'author_user_id' => $authorUserId,
            'body'           => $body,
            'created_at'     => $now,
        ]);

        $this->conn->executeStatement(
            'UPDATE support_requests SET status = :status, updated_at = :now WHERE id = :id',
            [
                'status' => $authorType === 'operator' ? 'answered' : 'open',
                'now'    => $now,
                'id'     => $requestId,
            ],
        );

        return (int) $this->conn->lastInsertId();
    }

    /**
     * The full thread for one ticket, oldest first (reading order).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listMessages(int $requestId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, request_id, author_type, author_user_id, body, created_at
             FROM support_messages WHERE request_id = :request_id ORDER BY created_at ASC',
            ['request_id' => $requestId],
        );

        return $rows;
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
            'SELECT id, account_id, user_id, category, subject, status, created_at, updated_at
             FROM support_requests WHERE account_id = :account_id ORDER BY updated_at DESC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    private const SORTS = [
        'updated_at_desc' => 'r.updated_at DESC',
        'updated_at_asc'  => 'r.updated_at ASC',
        'created_at_desc' => 'r.created_at DESC',
        'created_at_asc'  => 'r.created_at ASC',
    ];

    /** @return list<string> the allowed values for $sort in listAllForOperator() */
    public static function allowedSorts(): array
    {
        return array_keys(self::SORTS);
    }

    /**
     * Lists tickets for the operator inbox, filterable/searchable/sortable
     * for triage at volume (an installation can receive hundreds of tickets
     * a day). Deliberately WITHOUT account scoping — an operator needs to
     * see tickets across all accounts.
     *
     * $q searches both the ticket subject and every message body in the
     * thread (a customer's actual problem is often only in the body, not
     * the subject line). $sort must be one of self::SORTS' keys — validated
     * by the caller (SupportRequestAction) before reaching here; defaults
     * to the most-recently-active ticket first when omitted, same as
     * before this method grew filters.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForOperator(
        ?string $status = null,
        ?string $category = null,
        ?string $q = null,
        string $sort = 'updated_at_desc',
    ): array {
        $conditions = [];
        $params     = [];

        if ($status !== null) {
            $conditions[] = 'r.status = :status';
            $params['status'] = $status;
        }

        if ($category !== null) {
            $conditions[] = 'r.category = :category';
            $params['category'] = $category;
        }

        if ($q !== null && $q !== '') {
            $conditions[] = '(r.subject LIKE :q OR EXISTS (
                SELECT 1 FROM support_messages m WHERE m.request_id = r.id AND m.body LIKE :q
            ))';
            $params['q'] = '%' . $q . '%';
        }

        $where   = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));
        $orderBy = self::SORTS[$sort] ?? self::SORTS['updated_at_desc'];

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            "SELECT r.id, r.account_id, r.user_id, r.category, r.subject, r.status, r.created_at, r.updated_at, a.slug AS account_slug
             FROM support_requests r JOIN accounts a ON a.id = r.account_id {$where} ORDER BY {$orderBy}",
            $params,
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
            'SELECT id, account_id, user_id, category, subject, status, created_at, updated_at
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
            'SELECT id, account_id, user_id, category, subject, status, created_at, updated_at
             FROM support_requests WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Sets the ticket status directly (e.g. close without a final reply, or
     * manually reopen). Does not append a message — see addMessage() for
     * the status transitions that happen alongside a reply.
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
