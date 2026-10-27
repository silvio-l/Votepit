<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\CommentReactionRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence seam for CommentReactionRepository::toggle — the pure
 * transaction logic (insert/switch/retract) isolated from the HTTP stack.
 * Mirrors VoteRepositoryTest, since toggle() is deliberately modeled on
 * VoteRepository::cast().
 */
final class CommentReactionRepositoryTest extends IntegrationTestCase
{
    private CommentReactionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CommentReactionRepository($this->conn);
    }

    private function rowCount(int $commentId, int $userId): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM comment_reactions WHERE comment_id = :comment AND user_id = :user',
            ['comment' => $commentId, 'user' => $userId],
        );
    }

    public function test_allowed_reactions_are_recognized(): void
    {
        foreach (CommentReactionRepository::ALLOWED_REACTIONS as $reaction) {
            self::assertTrue($this->repo->isAllowed($reaction));
        }
        self::assertFalse($this->repo->isAllowed('🍕'));
        self::assertFalse($this->repo->isAllowed('<script>'));
        self::assertFalse($this->repo->isAllowed(''));
    }

    public function test_first_reaction_inserts_row(): void
    {
        $boardId   = $this->insertBoard('cr-insert');
        $author    = $this->insertUser('cr-insert-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('cr-insert-reactor@example.com');

        $result = $this->repo->toggle($commentId, $userId, '👍');

        self::assertSame(1, $this->rowCount($commentId, $userId));
        self::assertSame('👍', $result['my_reaction']);
        self::assertSame(['👍' => 1], $result['counts']);
    }

    public function test_same_reaction_again_retracts_and_deletes_row(): void
    {
        $boardId   = $this->insertBoard('cr-retract');
        $author    = $this->insertUser('cr-retract-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('cr-retract-reactor@example.com');

        $this->repo->toggle($commentId, $userId, '👍');
        $result = $this->repo->toggle($commentId, $userId, '👍');

        self::assertSame(0, $this->rowCount($commentId, $userId), 'Repeating the same reaction deletes the row.');
        self::assertNull($result['my_reaction']);
        self::assertSame([], $result['counts']);
    }

    public function test_different_reaction_switches_in_place_no_second_row(): void
    {
        $boardId   = $this->insertBoard('cr-switch');
        $author    = $this->insertUser('cr-switch-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('cr-switch-reactor@example.com');

        $this->repo->toggle($commentId, $userId, '👍');
        $result = $this->repo->toggle($commentId, $userId, '❤️');

        self::assertSame(1, $this->rowCount($commentId, $userId), 'Switching must not create a second row.');
        self::assertSame('❤️', $result['my_reaction']);
        self::assertSame(['❤️' => 1], $result['counts']);
    }

    public function test_counts_aggregate_across_multiple_users(): void
    {
        $boardId   = $this->insertBoard('cr-agg');
        $author    = $this->insertUser('cr-agg-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);

        $u1 = $this->insertUser('cr-agg-u1@example.com');
        $u2 = $this->insertUser('cr-agg-u2@example.com');
        $u3 = $this->insertUser('cr-agg-u3@example.com');

        $this->repo->toggle($commentId, $u1, '👍');
        $this->repo->toggle($commentId, $u2, '👍');
        $result = $this->repo->toggle($commentId, $u3, '🎉');

        self::assertCount(2, $result['counts']);
        self::assertSame(2, $result['counts']['👍'] ?? null);
        self::assertSame(1, $result['counts']['🎉'] ?? null);
    }

    public function test_summary_for_comments_batches_counts_and_my_reaction(): void
    {
        $boardId    = $this->insertBoard('cr-summary');
        $author     = $this->insertUser('cr-summary-author@example.com');
        $ideaId     = $this->seedIdea($boardId, $author);
        $commentOne = $this->seedComment($ideaId, $author);
        $commentTwo = $this->seedComment($ideaId, $author);

        $viewer = $this->insertUser('cr-summary-viewer@example.com');
        $other  = $this->insertUser('cr-summary-other@example.com');

        $this->repo->toggle($commentOne, $viewer, '👍');
        $this->repo->toggle($commentOne, $other, '👍');
        $this->repo->toggle($commentTwo, $other, '😄');

        $summary = $this->repo->summaryForComments([$commentOne, $commentTwo], $viewer);

        self::assertSame(['👍' => 2], $summary[$commentOne]['counts']);
        self::assertSame('👍', $summary[$commentOne]['my_reaction']);
        self::assertSame(['😄' => 1], $summary[$commentTwo]['counts']);
        self::assertNull($summary[$commentTwo]['my_reaction']);
    }

    public function test_summary_for_comments_with_null_user_omits_my_reaction(): void
    {
        $boardId   = $this->insertBoard('cr-summary-anon');
        $author    = $this->insertUser('cr-summary-anon-author@example.com');
        $ideaId    = $this->seedIdea($boardId, $author);
        $commentId = $this->seedComment($ideaId, $author);
        $userId    = $this->insertUser('cr-summary-anon-reactor@example.com');

        $this->repo->toggle($commentId, $userId, '👎');

        $summary = $this->repo->summaryForComments([$commentId], null);

        self::assertSame(['👎' => 1], $summary[$commentId]['counts']);
        self::assertNull($summary[$commentId]['my_reaction']);
    }

    public function test_summary_for_empty_comment_list_returns_empty_array(): void
    {
        self::assertSame([], $this->repo->summaryForComments([], null));
    }
}
