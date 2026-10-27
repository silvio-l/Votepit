<?php

declare(strict_types=1);

namespace Votepit\Cleanup;

use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationAppealRepository;
use Votepit\Persistence\ModerationRepository;

/**
 * Per-row purge loop for bin/purge-moderated-content.php (board-operator-
 * compliance issue 08), same shape/split as ExpiredAccountCleaner next to
 * bin/cleanup-expired-accounts.php: the bin script stays a thin CLI
 * wrapper, this class is the testable unit.
 *
 * PRD "Further Notes" retention rule: content_moderation_actions.purge_after
 * is a fixed 30 days from creation (ModerationRepository::recordAction()).
 * A row with a still-'pending' appeal is skipped here regardless of
 * purge_after (hasPendingAppeal() below, board-operator-compliance issue
 * 09: ModerationAppealRepository::hasPendingAppeal()); a row whose latest
 * appeal has already RESOLVED gets its effective deadline pushed out to
 * (resolved_at + 14 days) by ModerationRepository::findPurgeable() itself
 * when that is later than purge_after — so a resolved-but-recent appeal
 * still can't be purged out from under the review window even once
 * purge_after has technically elapsed.
 *
 * For each row ModerationRepository::findPurgeable() returns (already
 * filtered to the effective deadline <= now and not-yet-purged):
 *   1. skip it if hasPendingAppeal() says an appeal is still open;
 *   2. otherwise hard-delete the underlying soft-deleted comments/ideas row
 *      (entity_type/entity_id), then clear content_snapshot.
 * The content_moderation_actions row itself is never deleted — it stays as
 * the audit trail / repeat-offender counter (see ModerationRepository's
 * class doc).
 *
 * Runs entity-delete-then-clear-snapshot in that order (not the reverse):
 * if the underlying hard-delete throws, content_snapshot is left intact —
 * so a failed row is naturally retried on the next run (still matches
 * findPurgeable()'s `content_snapshot != ''` filter) instead of silently
 * losing its snapshot while the entity row survives.
 *
 * Per-row resilience, same pattern as ExpiredAccountCleaner::run(): one
 * row's failure is reported (ErrorReporter) and audited
 * (`moderation.purge_failed`) but does not abort the run.
 */
final readonly class ModerationPurgeService
{
    public function __construct(
        private ModerationRepository $moderationActions,
        private ModerationAppealRepository $appeals,
        private CommentRepository $comments,
        private IdeaRepository $ideas,
        private AuditLogger $audit,
        private ErrorReporter $reporter,
    ) {}

    /** @return array{purged: int, skipped: int, failed: int} */
    public function run(\DateTimeImmutable $now): array
    {
        $purged  = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($this->moderationActions->findPurgeable($now) as $row) {
            $id = (int) $row['id'];

            if ($this->hasPendingAppeal($id)) {
                $skipped++;
                continue;
            }

            try {
                $this->hardDeleteEntity((string) $row['entity_type'], (int) $row['entity_id']);
                $this->moderationActions->clearSnapshot($id);
            } catch (\Throwable $e) {
                $failed++;
                $this->reporter->report($e);
                $this->audit->log('moderation.purge_failed', [
                    'moderation_action_id' => $id,
                    'entity_type'          => $row['entity_type'],
                    'entity_id'            => $row['entity_id'],
                    'error'                => $e->getMessage(),
                ]);
                continue;
            }

            $purged++;
            $this->audit->log('moderation.content_purged', [
                'moderation_action_id' => $id,
                'entity_type'          => $row['entity_type'],
                'entity_id'            => $row['entity_id'],
                'account_id'           => $row['account_id'],
                'board_id'             => $row['board_id'],
            ]);
        }

        return ['purged' => $purged, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function hardDeleteEntity(string $entityType, int $entityId): void
    {
        match ($entityType) {
            'comment' => $this->comments->hardDelete($entityId),
            'idea'    => $this->ideas->hardDelete($entityId),
            default   => throw new \RuntimeException("Unknown moderation entity_type: {$entityType}"),
        };
    }

    /**
     * True when the moderation action currently being evaluated in run()
     * above has a still-'pending' appeal — real lookup (board-operator-
     * compliance issue 09), replacing the previous hardcoded-false stub.
     * The 14-day post-resolution grace period is NOT this method's concern
     * (a resolved appeal is never 'pending') — that widening lives in
     * ModerationRepository::findPurgeable() itself, see this class's doc
     * comment.
     */
    private function hasPendingAppeal(int $moderationActionId): bool
    {
        return $this->appeals->hasPendingAppeal($moderationActionId);
    }
}
