<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Domain\BoardStatuses;
use Votepit\Domain\Premoderation;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\UserErasureRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Pre-moderation (broaden-use-cases issue 05) against features that read or
 * rewrite ideas outside IdeaRepository: board statuses (issue 03) and the
 * GDPR user erasure (competitor-parity issue 12).
 */
final class PremoderationCrossFeatureTest extends IntegrationTestCase
{
    private function seedPending(int $boardId, int $authorId, string $status = 'open'): int
    {
        $ideaId = $this->seedIdea($boardId, $authorId, 'Pending idea');
        $this->conn->update('ideas', ['moderation_state' => Premoderation::STATE_PENDING, 'status' => $status], ['id' => $ideaId]);

        return $ideaId;
    }

    private function customizedBoard(string $slug): int
    {
        $accountId = $this->insertAccount(['slug' => $slug . '-acc']);
        $boardId   = $this->insertBoard($slug, ['account_id' => $accountId]);
        $board     = $this->conn->fetchAssociative('SELECT * FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($board);
        (new BoardStatusRepository($this->conn))->materialize($board, $accountId);

        return $boardId;
    }

    /** @return array<string, mixed> */
    private function board(int $boardId): array
    {
        $board = $this->conn->fetchAssociative('SELECT * FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($board);

        return $board;
    }

    private function countFor(BoardStatuses $statuses, string $key): ?int
    {
        return $statuses->find($key)?->ideaCount;
    }

    public function test_public_status_registry_counts_exclude_pending_ideas(): void
    {
        $boardId = $this->customizedBoard('status-public');
        $author  = $this->insertUser('status-author@example.com');
        $this->seedIdea($boardId, $author, 'Published idea');
        $this->seedPending($boardId, $author);

        $public = (new BoardStatusRepository($this->conn))->registryFor($this->board($boardId));

        self::assertSame(1, $this->countFor($public, 'open'));
    }

    public function test_admin_counts_and_delete_guards_include_pending_ideas_so_none_is_orphaned(): void
    {
        $boardId = $this->customizedBoard('status-admin');
        $author  = $this->insertUser('status-admin-author@example.com');
        $this->seedIdea($boardId, $author, 'Published idea');
        $pending = $this->seedPending($boardId, $author);
        $repo    = new BoardStatusRepository($this->conn);

        self::assertSame(2, $this->countFor($repo->registryWithCounts($this->board($boardId)), 'open'));
        self::assertSame(2, $repo->countIdeas($boardId, 'open'));

        self::assertSame(2, $repo->moveIdeas($boardId, 'open', 'planned'));
        self::assertSame('planned', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = :id', ['id' => $pending]));
        self::assertSame(Premoderation::STATE_PENDING, $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE id = :id', ['id' => $pending]));
    }

    public function test_default_board_admin_counts_include_pending_ideas(): void
    {
        $boardId = $this->insertBoard('status-default');
        $author  = $this->insertUser('status-default-author@example.com');
        $this->seedPending($boardId, $author);

        $admin = (new BoardStatusRepository($this->conn))->registryWithCounts($this->board($boardId));

        self::assertSame(1, $this->countFor($admin, 'open'));
    }

    public function test_user_erasure_reattributes_the_approver_and_keeps_pending_ideas_pending(): void
    {
        $boardId   = $this->insertBoard('erase-approver');
        $author    = $this->insertUser('erase-author@example.com');
        $approver  = $this->insertUser('erase-approver@example.com');
        $leaver    = $this->insertUser('erase-leaver@example.com');
        $approved  = $this->seedPending($boardId, $author);
        $stillOpen = $this->seedPending($boardId, $leaver);
        self::assertTrue((new IdeaRepository($this->conn))->approve($boardId, $approved, $approver));

        $placeholder = (new \Votepit\Persistence\UserRepository($this->conn))->findOrCreateDeletedPlaceholder();
        $erasure     = new UserErasureRepository($this->conn);
        $erasure->erase($approver, $placeholder);
        $erasure->erase($leaver, $placeholder);

        $row = $this->conn->fetchAssociative('SELECT moderation_state, approved_at, approved_by FROM ideas WHERE id = :id', ['id' => $approved]);
        self::assertIsArray($row);
        self::assertSame(Premoderation::STATE_PUBLISHED, $row['moderation_state']);
        self::assertNotNull($row['approved_at']);
        self::assertSame($placeholder, (int) $row['approved_by']);

        $pending = $this->conn->fetchAssociative('SELECT author_id, moderation_state FROM ideas WHERE id = :id', ['id' => $stillOpen]);
        self::assertIsArray($pending);
        self::assertSame($placeholder, (int) $pending['author_id']);
        self::assertSame(Premoderation::STATE_PENDING, $pending['moderation_state']);
    }
}
