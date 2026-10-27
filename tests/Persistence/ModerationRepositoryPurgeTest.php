<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\ModerationRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * ModerationRepository::findPurgeable()/clearSnapshot() — the query-and-
 * clear pair driving bin/purge-moderated-content.php (board-operator-
 * compliance issue 08), same split as AccountRepositoryCleanupTest for
 * findExpiredForDeletion()/purgeExpired().
 */
final class ModerationRepositoryPurgeTest extends IntegrationTestCase
{
    private function repo(): ModerationRepository
    {
        return new ModerationRepository($this->conn);
    }

    /** @return array{accountId: int, userId: int, boardId: int, ideaId: int} */
    private function fixtureEntities(): array
    {
        $accountId = $this->insertAccount();
        $userId    = $this->insertUser();
        $boardId   = $this->insertBoard('demo', ['account_id' => $accountId]);
        $ideaId    = $this->seedIdea($boardId, $userId);

        return ['accountId' => $accountId, 'userId' => $userId, 'boardId' => $boardId, 'ideaId' => $ideaId];
    }

    public function test_a_row_past_purge_after_is_returned(): void
    {
        $f  = $this->fixtureEntities();
        $id = $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId']);

        $rows = $this->repo()->findPurgeable(new \DateTimeImmutable());

        self::assertCount(1, $rows);
        self::assertSame($id, (int) $rows[0]['id']);
    }

    public function test_a_row_whose_purge_after_is_still_in_the_future_is_not_returned(): void
    {
        $f = $this->fixtureEntities();
        $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId'], [
            'purge_after' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ]);

        self::assertSame([], $this->repo()->findPurgeable(new \DateTimeImmutable()));
    }

    public function test_a_row_already_purged_content_snapshot_cleared_is_not_returned_again(): void
    {
        $f = $this->fixtureEntities();
        $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId'], [
            'content_snapshot' => '',
        ]);

        self::assertSame([], $this->repo()->findPurgeable(new \DateTimeImmutable()));
    }

    public function test_clear_snapshot_empties_the_column_but_keeps_the_row(): void
    {
        $f  = $this->fixtureEntities();
        $id = $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId']);

        $this->repo()->clearSnapshot($id);

        $row = $this->repo()->findById($id);
        self::assertNotNull($row);
        self::assertSame('', $row['content_snapshot']);
    }

    public function test_a_row_with_no_appeal_uses_purge_after_unchanged(): void
    {
        $f  = $this->fixtureEntities();
        $id = $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId']);

        $rows = $this->repo()->findPurgeable(new \DateTimeImmutable());

        self::assertCount(1, $rows);
        self::assertSame($id, (int) $rows[0]['id']);
    }

    public function test_a_row_whose_appeal_is_still_pending_keeps_purge_after_unchanged(): void
    {
        // findPurgeable() itself does not gate on 'pending' (that's
        // ModerationPurgeService::hasPendingAppeal()'s job) — a pending
        // appeal has resolved_at = NULL, so the effective deadline stays
        // purge_after and the row is still returned here.
        $f  = $this->fixtureEntities();
        $id = $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId']);
        $this->seedAppeal($id, $f['userId']);

        $rows = $this->repo()->findPurgeable(new \DateTimeImmutable());

        self::assertCount(1, $rows);
        self::assertSame($id, (int) $rows[0]['id']);
    }

    public function test_a_resolved_appeal_extends_the_effective_deadline_past_purge_after(): void
    {
        $f  = $this->fixtureEntities();
        $id = $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId']);
        $this->seedAppeal($id, $f['userId'], overrides: [
            'status'      => 'upheld',
            'resolved_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        self::assertSame([], $this->repo()->findPurgeable(new \DateTimeImmutable()));

        $rows = $this->repo()->findPurgeable(new \DateTimeImmutable('+15 days'));
        self::assertCount(1, $rows);
        self::assertSame($id, (int) $rows[0]['id']);
    }

    public function test_only_the_latest_appeal_by_id_counts_toward_the_effective_deadline(): void
    {
        // Two resolved appeals against the same moderation action (a second
        // appeal submitted after the first was resolved — allowed, only one
        // 'pending' at a time is enforced). The FIRST (lower id) resolved
        // recently (would extend the deadline far into the future); the
        // SECOND (higher id — the actually-latest one) resolved long ago,
        // past its own 14-day grace. Only the latest-by-id appeal must
        // count: the row is purgeable now via its (already-elapsed)
        // deadline, NOT held back by the earlier appeal's recent resolution.
        $f  = $this->fixtureEntities();
        $id = $this->seedModerationAction('idea', $f['ideaId'], $f['boardId'], $f['accountId'], $f['userId']);
        $this->seedAppeal($id, $f['userId'], overrides: [
            'status'      => 'upheld',
            'resolved_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->seedAppeal($id, $f['userId'], overrides: [
            'status'      => 'overturned',
            'resolved_at' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'),
        ]);

        $rows = $this->repo()->findPurgeable(new \DateTimeImmutable());
        self::assertCount(1, $rows);
        self::assertSame($id, (int) $rows[0]['id']);
    }
}
