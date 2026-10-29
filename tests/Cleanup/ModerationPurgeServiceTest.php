<?php

declare(strict_types=1);

namespace Votepit\Tests\Cleanup;

use Votepit\Cleanup\ModerationPurgeService;
use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationAppealRepository;
use Votepit\Persistence\ModerationRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * board-operator-compliance issue 08/09: ModerationPurgeService, the per-row
 * loop behind bin/purge-moderated-content.php. Now covers the real
 * content_moderation_appeals interaction (issue 09): a 'pending' appeal
 * skips the purge outright (hasPendingAppeal()); a resolved appeal whose
 * resolved_at + 14 days is later than purge_after pushes the effective
 * deadline out that far (ModerationRepository::findPurgeable()).
 */
final class ModerationPurgeServiceTest extends IntegrationTestCase
{
    private function service(?ErrorReporter $reporter = null): ModerationPurgeService
    {
        return new ModerationPurgeService(
            new ModerationRepository($this->conn),
            new ModerationAppealRepository($this->conn),
            new CommentRepository($this->conn),
            new IdeaRepository($this->conn),
            new AuditLogger($this->logFile),
            $reporter ?? new class () implements ErrorReporter {
                public function report(\Throwable $exception): void {}

                public function reportSecurityEvent(string $message, string $level, array $context): void {}
            },
        );
    }

    /** @return array{accountId: int, userId: int, boardId: int} */
    private function fixtureBoard(): array
    {
        $accountId = $this->insertAccount(['slug' => 'purge-acct-' . uniqid()]);
        $userId    = $this->insertUser('mod-' . uniqid() . '@example.com');
        $boardId   = $this->insertBoard('purge-board-' . uniqid(), ['account_id' => $accountId]);

        return ['accountId' => $accountId, 'userId' => $userId, 'boardId' => $boardId];
    }

    public function test_a_past_due_idea_with_no_appeal_is_purged(): void
    {
        $f      = $this->fixtureBoard();
        $ideaId = $this->seedIdea($f['boardId'], $f['userId']);

        $actionId = $this->seedModerationAction('idea', $ideaId, $f['boardId'], $f['accountId'], $f['userId']);
        $this->conn->update('ideas', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId], ['id' => $ideaId]);

        $result = $this->service()->run(new \DateTimeImmutable());

        self::assertSame(['purged' => 1, 'skipped' => 0, 'failed' => 0], $result);

        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaId]));

        $row = $this->conn->fetchAssociative('SELECT content_snapshot FROM content_moderation_actions WHERE id = :id', ['id' => $actionId]);
        self::assertIsArray($row);
        self::assertSame('', $row['content_snapshot']);

        $log = $this->readAuditLog();
        self::assertStringContainsString('moderation.content_purged', $log);
    }

    public function test_a_past_due_comment_with_no_appeal_is_purged(): void
    {
        $f      = $this->fixtureBoard();
        $ideaId = $this->seedIdea($f['boardId'], $f['userId']);
        $commentId = $this->seedComment($ideaId, $f['userId']);

        $actionId = $this->seedModerationAction('comment', $commentId, $f['boardId'], $f['accountId'], $f['userId']);
        $this->conn->update('comments', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId], ['id' => $commentId]);

        $result = $this->service()->run(new \DateTimeImmutable());

        self::assertSame(['purged' => 1, 'skipped' => 0, 'failed' => 0], $result);
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM comments WHERE id = :id', ['id' => $commentId]));
    }

    public function test_a_row_whose_purge_after_is_in_the_future_is_left_untouched(): void
    {
        $f      = $this->fixtureBoard();
        $ideaId = $this->seedIdea($f['boardId'], $f['userId']);

        $actionId = $this->seedModerationAction('idea', $ideaId, $f['boardId'], $f['accountId'], $f['userId'], [
            'purge_after' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ]);
        $this->conn->update('ideas', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId], ['id' => $ideaId]);

        $result = $this->service()->run(new \DateTimeImmutable());

        self::assertSame(['purged' => 0, 'skipped' => 0, 'failed' => 0], $result);
        self::assertIsArray($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaId]));

        $row = $this->conn->fetchAssociative('SELECT content_snapshot FROM content_moderation_actions WHERE id = :id', ['id' => $actionId]);
        self::assertIsArray($row);
        self::assertNotSame('', $row['content_snapshot']);
    }

    public function test_a_pending_appeal_blocks_the_purge_even_though_purge_after_has_passed(): void
    {
        $f      = $this->fixtureBoard();
        $ideaId = $this->seedIdea($f['boardId'], $f['userId']);
        $actionId = $this->seedModerationAction('idea', $ideaId, $f['boardId'], $f['accountId'], $f['userId']);
        $this->conn->update('ideas', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId], ['id' => $ideaId]);
        $this->seedAppeal($actionId, $f['userId']);

        $result = $this->service()->run(new \DateTimeImmutable());

        self::assertSame(['purged' => 0, 'skipped' => 1, 'failed' => 0], $result);
        self::assertIsArray($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_a_resolved_appeal_within_its_14_day_grace_period_blocks_the_purge(): void
    {
        $f      = $this->fixtureBoard();
        $ideaId = $this->seedIdea($f['boardId'], $f['userId']);
        $actionId = $this->seedModerationAction('idea', $ideaId, $f['boardId'], $f['accountId'], $f['userId']);
        $this->conn->update('ideas', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId], ['id' => $ideaId]);
        // Resolved just now — the effective deadline (resolved_at + 14 days)
        // is far in the future, even though purge_after (fixture default:
        // 1 day in the past) has already elapsed.
        $this->seedAppeal($actionId, $f['userId'], overrides: [
            'status'      => 'upheld',
            'resolved_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $result = $this->service()->run(new \DateTimeImmutable());

        self::assertSame(['purged' => 0, 'skipped' => 0, 'failed' => 0], $result);
        self::assertIsArray($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_a_resolved_appeal_past_its_14_day_grace_period_is_purged(): void
    {
        $f      = $this->fixtureBoard();
        $ideaId = $this->seedIdea($f['boardId'], $f['userId']);
        $actionId = $this->seedModerationAction('idea', $ideaId, $f['boardId'], $f['accountId'], $f['userId']);
        $this->conn->update('ideas', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId], ['id' => $ideaId]);
        $this->seedAppeal($actionId, $f['userId'], overrides: [
            'status'      => 'overturned',
            'resolved_at' => (new \DateTimeImmutable('-15 days'))->format('Y-m-d H:i:s'),
        ]);

        $result = $this->service()->run(new \DateTimeImmutable());

        self::assertSame(['purged' => 1, 'skipped' => 0, 'failed' => 0], $result);
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_a_failure_on_one_row_does_not_prevent_the_others_from_being_purged(): void
    {
        $f       = $this->fixtureBoard();
        $ideaIdA = $this->seedIdea($f['boardId'], $f['userId'], 'Idea A');
        $ideaIdB = $this->seedIdea($f['boardId'], $f['userId'], 'Idea B');

        $actionIdA = $this->seedModerationAction('idea', $ideaIdA, $f['boardId'], $f['accountId'], $f['userId']);
        $actionIdB = $this->seedModerationAction('idea', $ideaIdB, $f['boardId'], $f['accountId'], $f['userId']);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->update('ideas', ['deleted_at' => $now, 'moderation_action_id' => $actionIdA], ['id' => $ideaIdA]);
        $this->conn->update('ideas', ['deleted_at' => $now, 'moderation_action_id' => $actionIdB], ['id' => $ideaIdB]);

        // Force a hard-delete failure on idea A only (closest in-process
        // equivalent to an unexpected DB error, same technique as
        // ExpiredAccountCleanerTest::forcePurgeFailureFor()).
        $this->conn->executeStatement(
            "CREATE TRIGGER fail_idea_purge_{$ideaIdA}
             BEFORE DELETE ON ideas
             WHEN OLD.id = {$ideaIdA}
             BEGIN
                 SELECT RAISE(ABORT, 'simulated purge failure');
             END",
        );

        $reporter = new class () implements ErrorReporter {
            /** @var list<\Throwable> */
            public array $reported = [];
            public function report(\Throwable $exception): void
            {
                $this->reported[] = $exception;
            }

            public function reportSecurityEvent(string $message, string $level, array $context): void {}
        };

        $result = $this->service($reporter)->run(new \DateTimeImmutable());

        self::assertSame(['purged' => 1, 'skipped' => 0, 'failed' => 1], $result);
        self::assertCount(1, $reporter->reported);

        // Idea A's row survives (trigger aborted the DELETE) AND its
        // content_snapshot stays intact — a failed row is retried on the
        // next run rather than losing its snapshot with the entity still
        // there.
        self::assertIsArray($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaIdA]));
        $rowA = $this->conn->fetchAssociative('SELECT content_snapshot FROM content_moderation_actions WHERE id = :id', ['id' => $actionIdA]);
        self::assertIsArray($rowA);
        self::assertNotSame('', $rowA['content_snapshot']);

        // Idea B purged normally.
        self::assertFalse($this->conn->fetchAssociative('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaIdB]));

        $log = $this->readAuditLog();
        self::assertStringContainsString('moderation.purge_failed', $log);
        self::assertStringContainsString('moderation.content_purged', $log);
    }
}
