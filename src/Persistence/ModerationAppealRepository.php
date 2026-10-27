<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * content_moderation_appeals persistence (board-operator-compliance issue
 * 09, migrations/0061_create_content_moderation_appeals.sql) — the appeal
 * path against a content_moderation_actions row (issue 06/07).
 *
 * hasPendingAppeal() is the real implementation behind
 * Cleanup\ModerationPurgeService::hasPendingAppeal() (previously a
 * hardcoded-false stub left by issue 08, see that class's doc comment) —
 * wired in via constructor injection there.
 */
final readonly class ModerationAppealRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Records a new appeal and returns its ID. Callers MUST have already
     * checked hasPendingAppeal() (and account/author ownership) — this
     * method does not re-check either; it is a plain insert.
     *
     * @throws DbalException
     */
    public function submit(int $moderationActionId, int $userId, string $message): int
    {
        $this->conn->insert('content_moderation_appeals', [
            'moderation_action_id' => $moderationActionId,
            'submitted_by_user_id' => $userId,
            'message'               => $message,
            'status'                => 'pending',
            'created_at'            => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /**
     * True if the given moderation action currently has an unresolved
     * ('pending') appeal — "one open appeal per moderation action" (PRD),
     * checked by ModerationAppealAction::submit() before insert() above,
     * and the real gate behind ModerationPurgeService::hasPendingAppeal().
     *
     * @throws DbalException
     */
    public function hasPendingAppeal(int $moderationActionId): bool
    {
        $row = $this->conn->fetchOne(
            "SELECT 1 FROM content_moderation_appeals
             WHERE moderation_action_id = :moderation_action_id AND status = 'pending'
             LIMIT 1",
            ['moderation_action_id' => $moderationActionId],
        );

        return $row !== false;
    }

    /**
     * A single appeal row, or null if unknown.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, moderation_action_id, submitted_by_user_id, message, status,
                    resolved_by_user_id, resolved_at, created_at
             FROM content_moderation_appeals
             WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * All appeals for a given account's moderation actions — the moderator/
     * admin review inbox (ModerationAppealAction::list()), joined against
     * content_moderation_actions for the account scope AND the context a
     * reviewer needs (entity type/reason/content snapshot) without a second
     * round trip per row. Newest first, same convention as
     * AbuseReportRepository::listAll().
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function findForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.id, a.moderation_action_id, a.submitted_by_user_id, a.message, a.status,
                    a.resolved_by_user_id, a.resolved_at, a.created_at,
                    cma.entity_type, cma.entity_id, cma.board_id, cma.reason_code, cma.content_snapshot
             FROM content_moderation_appeals a
             INNER JOIN content_moderation_actions cma ON cma.id = a.moderation_action_id
             WHERE cma.account_id = :account_id
             ORDER BY a.id DESC',
            ['account_id' => $accountId],
        );

        return $rows;
    }

    /**
     * Resolves an appeal — sets status ('upheld'|'overturned'), stamps
     * resolved_by_user_id/resolved_at together. Caller (ModerationAppealAction::resolve())
     * validates the status value and the account scope before calling this.
     *
     * @throws DbalException
     */
    public function resolve(int $id, string $status, int $resolvedByUserId): void
    {
        $this->conn->update('content_moderation_appeals', [
            'status'              => $status,
            'resolved_by_user_id' => $resolvedByUserId,
            'resolved_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }
}
