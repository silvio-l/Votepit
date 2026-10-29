<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * content_moderation_actions persistence (board-operator-compliance issue
 * 06, migrations/0059_create_content_moderation_actions.sql).
 *
 * Single source of truth for both "what was removed and why" (DSA Art. 17
 * justification — content_snapshot is kept for a future appeal reviewer,
 * issue 07) and "how many times has this member been moderated"
 * (repeat-offender counting, issue 08). This ticket (06) only ever writes
 * entity_type='comment' rows with actor_tier='board_moderator' — 'idea'
 * (issue 07) and 'operator' (a possible later platform-wide Trust & Safety
 * action, see the migration's doc comment) are already part of the schema
 * so those tickets don't need another migration.
 */
final readonly class ModerationRepository
{
    /** Fixed retention window (PRD "Further Notes"): 30 days from creation.
     *  The +14-day appeal extension (once a linked appeal resolves later
     *  than this) is applied by findPurgeable() below, issue 09. */
    private const RETENTION_DAYS = 30;

    public function __construct(private Connection $conn) {}

    /**
     * Records a moderation action and returns its new ID. purge_after is
     * computed here (created_at + self::RETENTION_DAYS), not left to the
     * caller — every writer of this table gets the same retention rule for
     * free.
     *
     * @throws DbalException
     */
    public function recordAction(
        string $entityType,
        int $entityId,
        int $boardId,
        int $accountId,
        int $actorUserId,
        string $actorTier,
        string $reasonCode,
        ?string $reasonNote,
        string $contentSnapshot,
    ): int {
        $now        = new \DateTimeImmutable();
        $purgeAfter = $now->modify('+' . self::RETENTION_DAYS . ' days');

        $this->conn->insert('content_moderation_actions', [
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'board_id'         => $boardId,
            'account_id'       => $accountId,
            'actor_user_id'    => $actorUserId,
            'actor_tier'       => $actorTier,
            'reason_code'      => $reasonCode,
            'reason_note'      => $reasonNote,
            'content_snapshot' => $contentSnapshot,
            'created_at'       => $now->format('Y-m-d H:i:s'),
            'purge_after'      => $purgeAfter->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Returns a single moderation action row, or null if unknown.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, entity_type, entity_id, board_id, account_id, actor_user_id, actor_tier,
                    reason_code, reason_note, content_snapshot, created_at, purge_after
             FROM content_moderation_actions
             WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Rows whose EFFECTIVE retention window has passed, board-operator-
     * compliance issue 08's purge job (bin/purge-moderated-content.php,
     * Cleanup\ModerationPurgeService). The "still has a pending appeal"
     * check is deliberately NOT part of this query — that stays a per-row
     * gate applied by the caller via ModerationPurgeService::hasPendingAppeal()
     * (issue 09: ModerationAppealRepository::hasPendingAppeal()), same as
     * before. What IS this query's job (issue 09 widening, see
     * ModerationPurgeService's class doc): a row whose latest appeal was
     * RESOLVED has its effective deadline pushed out to
     * (appeal.resolved_at + 14 days) whenever that is later than the fixed
     * purge_after — a running appeal review must not have its 14-day grace
     * period eaten by a purge_after that already elapsed while the appeal
     * was pending. A row with no appeal at all, or whose latest appeal is
     * still 'pending' (resolved_at NULL), is unaffected — its effective
     * deadline stays purge_after, and hasPendingAppeal() is what keeps a
     * still-pending one out of the actual purge.
     *
     * Date arithmetic happens in PHP, not SQL (DATE_ADD/INTERVAL is
     * MySQL-only and this codebase's integration tests run on SQLite —
     * every other date computation here, e.g. recordAction() above, follows
     * the same convention) — hence fetching all not-yet-purged rows plus
     * each one's latest appeal's resolved_at, then filtering by comparing
     * formatted DATETIME strings (lexicographically comparable, fixed
     * 'Y-m-d H:i:s' format) against $now.
     *
     * `content_snapshot != ''` excludes rows this job already purged on an
     * earlier run (clearSnapshot() below sets it to '') — content_snapshot
     * is NOT NULL so an empty string, not NULL, is the "already purged"
     * marker. Without this filter, a re-run would re-attempt (harmlessly,
     * but pointlessly) hard-deleting a comments/ideas row that is already
     * gone, and would keep the row in every eligible listing forever
     * (purge_after never changes) instead of only until it is actually
     * purged.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function findPurgeable(\DateTimeImmutable $now): array
    {
        /** @var list<array<string, mixed>> $candidates */
        $candidates = $this->conn->fetchAllAssociative(
            "SELECT cma.id, cma.entity_type, cma.entity_id, cma.board_id, cma.account_id,
                    cma.actor_user_id, cma.actor_tier, cma.reason_code, cma.reason_note,
                    cma.content_snapshot, cma.created_at, cma.purge_after,
                    appeal.resolved_at AS latest_appeal_resolved_at
             FROM content_moderation_actions cma
             LEFT JOIN (
                 SELECT a.moderation_action_id, a.resolved_at
                 FROM content_moderation_appeals a
                 INNER JOIN (
                     SELECT moderation_action_id, MAX(id) AS max_id
                     FROM content_moderation_appeals
                     GROUP BY moderation_action_id
                 ) latest ON latest.moderation_action_id = a.moderation_action_id AND latest.max_id = a.id
             ) appeal ON appeal.moderation_action_id = cma.id
             WHERE cma.content_snapshot != ''",
        );

        $nowStr = $now->format('Y-m-d H:i:s');

        $rows = [];
        foreach ($candidates as $candidate) {
            $deadline   = (string) $candidate['purge_after'];
            $resolvedAt = $candidate['latest_appeal_resolved_at'] ?? null;

            if (is_string($resolvedAt) && $resolvedAt !== '') {
                $extended = (new \DateTimeImmutable($resolvedAt))->modify('+14 days')->format('Y-m-d H:i:s');
                if ($extended > $deadline) {
                    $deadline = $extended;
                }
            }

            if ($deadline > $nowStr) {
                continue;
            }

            unset($candidate['latest_appeal_resolved_at']);
            $rows[] = $candidate;
        }

        return $rows;
    }

    /**
     * Clears content_snapshot (set to '', not NULL — the column is
     * NOT NULL) once the purge job has hard-deleted the underlying
     * comments/ideas row. The content_moderation_actions row itself is
     * deliberately left in place — it remains the audit trail (DSA Art. 17
     * justification) and the repeat-offender counter (issue 08's PRD
     * note), only the retained snapshot text goes away.
     *
     * @throws DbalException
     */
    public function clearSnapshot(int $id): void
    {
        $this->conn->update('content_moderation_actions', ['content_snapshot' => ''], ['id' => $id]);
    }
}
