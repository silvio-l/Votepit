<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Abuse report persistence.
 *
 * DSA Art. 16 reporting mechanism: the functional intake→storage→operator-review
 * pipeline (the legal framing itself follows separately). Reachable
 * unauthenticated (anonymous report) — account_id/board_id/idea_id are therefore
 * nullable and resolved best-effort by the caller; a report is
 * ALWAYS stored, even if the reported slug/idea no longer exists
 * (target_url is always kept raw in that case).
 *
 * reporter_email is already encrypted/decrypted here (EncryptionService,
 * context 'abuse_report') — this class only ever sees the ciphertext blob, never
 * the plaintext (see migrations/0013_add_operator_panel.sql for the
 * rationale behind using encryption here instead of the ADR-0002 email_hmac
 * scheme: the operator may need to reply to the reporter).
 */
final readonly class AbuseReportRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Creates a new report. $reporterEmailEnc is already encrypted
     * (or null, the field is optional). Returns the new report ID.
     *
     * @throws DbalException
     */
    public function create(
        string $targetUrl,
        string $reason,
        ?int $accountId,
        ?int $boardId,
        ?int $ideaId,
        ?string $reporterEmailEnc,
    ): int {
        $this->conn->insert('abuse_reports', [
            'account_id'         => $accountId,
            'board_id'           => $boardId,
            'idea_id'            => $ideaId,
            'target_url'         => $targetUrl,
            'reason'             => $reason,
            'reporter_email_enc' => $reporterEmailEnc,
            'status'             => 'open',
            'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Lists all reports for the operator inbox, newest first. Deliberately
     * WITHOUT account scoping — an operator needs to see reports across all
     * accounts. reporter_email_enc stays ciphertext; the caller
     * decrypts it selectively, only where it needs to be displayed.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAll(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, account_id, board_id, idea_id, target_url, reason, reporter_email_enc,
                    status, reviewed_by, reviewed_at, created_at
             FROM abuse_reports ORDER BY created_at DESC',
        );

        return $rows;
    }

    /**
     * Finds a single report by its ID.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, account_id, board_id, idea_id, target_url, reason, reporter_email_enc,
                    status, reviewed_by, reviewed_at, created_at
             FROM abuse_reports WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Marks a report as reviewed (status='reviewed'|'dismissed') by
     * the given operator. Returns false if the ID did not exist.
     *
     * @throws DbalException
     */
    public function markReviewed(int $id, string $status, int $reviewedByUserId): bool
    {
        $affected = $this->conn->executeStatement(
            'UPDATE abuse_reports SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :now WHERE id = :id',
            [
                'status'      => $status,
                'reviewed_by' => $reviewedByUserId,
                'now'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'          => $id,
            ],
        );

        return $affected > 0;
    }

    /**
     * Counts open (unhandled) reports.
     *
     * @throws DbalException
     */
    public function countOpen(): int
    {
        return (int) $this->conn->fetchOne("SELECT COUNT(*) FROM abuse_reports WHERE status = 'open'");
    }

    /**
     * Storage-limitation cleanup (deep-review-2026-09 finding g):
     * reporter_email_enc is PII (encrypted at rest, but still personal data)
     * with no prior retention limit — deletes reports that have been
     * resolved (status 'reviewed'/'dismissed', never 'open' — an unhandled
     * report is never auto-purged) for longer than the given cutoff.
     *
     * @throws DbalException
     */
    public function purgeReviewedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->conn->executeStatement(
            "DELETE FROM abuse_reports
             WHERE status IN ('reviewed', 'dismissed') AND reviewed_at < :cutoff",
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        );
    }
}
