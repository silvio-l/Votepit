<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\ModerationAppealRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * board-operator-compliance issue 09: ModerationAppealRepository —
 * content_moderation_appeals persistence.
 */
final class ModerationAppealRepositoryTest extends IntegrationTestCase
{
    private function repo(): ModerationAppealRepository
    {
        return new ModerationAppealRepository($this->conn);
    }

    /** @return array{accountId: int, userId: int, boardId: int, ideaId: int, moderationActionId: int} */
    private function fixture(): array
    {
        $accountId = $this->insertAccount();
        $userId    = $this->insertUser();
        $boardId   = $this->insertBoard('appeal-repo-board-' . uniqid(), ['account_id' => $accountId]);
        $ideaId    = $this->seedIdea($boardId, $userId);
        $moderationActionId = $this->seedModerationAction('idea', $ideaId, $boardId, $accountId, $userId);

        return ['accountId' => $accountId, 'userId' => $userId, 'boardId' => $boardId, 'ideaId' => $ideaId, 'moderationActionId' => $moderationActionId];
    }

    public function test_submit_inserts_a_pending_row_and_returns_its_id(): void
    {
        $f  = $this->fixture();
        $id = $this->repo()->submit($f['moderationActionId'], $f['userId'], 'Please reconsider.');

        $row = $this->repo()->findById($id);
        self::assertIsArray($row);
        self::assertSame($f['moderationActionId'], (int) $row['moderation_action_id']);
        self::assertSame($f['userId'], (int) $row['submitted_by_user_id']);
        self::assertSame('Please reconsider.', $row['message']);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['resolved_by_user_id']);
        self::assertNull($row['resolved_at']);
    }

    public function test_find_by_id_returns_null_for_unknown_id(): void
    {
        self::assertNull($this->repo()->findById(999999));
    }

    public function test_has_pending_appeal_is_false_when_none_exists(): void
    {
        $f = $this->fixture();
        self::assertFalse($this->repo()->hasPendingAppeal($f['moderationActionId']));
    }

    public function test_has_pending_appeal_is_true_for_a_pending_row(): void
    {
        $f = $this->fixture();
        $this->seedAppeal($f['moderationActionId'], $f['userId']);

        self::assertTrue($this->repo()->hasPendingAppeal($f['moderationActionId']));
    }

    public function test_has_pending_appeal_is_false_once_resolved(): void
    {
        $f = $this->fixture();
        $id = $this->seedAppeal($f['moderationActionId'], $f['userId']);

        $this->repo()->resolve($id, 'upheld', $f['userId']);

        self::assertFalse($this->repo()->hasPendingAppeal($f['moderationActionId']));
    }

    public function test_resolve_sets_status_resolved_by_and_resolved_at(): void
    {
        $f  = $this->fixture();
        $modId = $this->insertUser('appeal-repo-resolver-' . uniqid() . '@example.com');
        $id = $this->seedAppeal($f['moderationActionId'], $f['userId']);

        $this->repo()->resolve($id, 'overturned', $modId);

        $row = $this->repo()->findById($id);
        self::assertIsArray($row);
        self::assertSame('overturned', $row['status']);
        self::assertSame($modId, (int) $row['resolved_by_user_id']);
        self::assertNotNull($row['resolved_at']);
    }

    public function test_find_for_account_returns_only_that_accounts_appeals(): void
    {
        $f = $this->fixture();
        $this->seedAppeal($f['moderationActionId'], $f['userId'], 'In scope.');

        $otherAccountId = $this->insertAccount();
        $otherUserId    = $this->insertUser('appeal-repo-other-' . uniqid() . '@example.com');
        $otherBoardId   = $this->insertBoard('appeal-repo-other-board-' . uniqid(), ['account_id' => $otherAccountId]);
        $otherIdeaId    = $this->seedIdea($otherBoardId, $otherUserId);
        $otherActionId  = $this->seedModerationAction('idea', $otherIdeaId, $otherBoardId, $otherAccountId, $otherUserId);
        $this->seedAppeal($otherActionId, $otherUserId, 'Out of scope.');

        $rows = $this->repo()->findForAccount($f['accountId']);

        self::assertCount(1, $rows);
        self::assertSame('In scope.', $rows[0]['message']);
        self::assertSame('idea', $rows[0]['entity_type']);
        self::assertSame($f['ideaId'], (int) $rows[0]['entity_id']);
    }
}
