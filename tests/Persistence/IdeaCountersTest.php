<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Denormalized idea counters (migrations/0072_add_idea_counters.sql):
 * ideas.up_count / down_count / comment_count are maintained app-side in the
 * same transaction as the vote/comment mutation — same pattern as
 * score_cache (ADR-3 amendment). Every mutation path must leave them equal
 * to the live aggregates they replace, and every read path must use the
 * columns instead of correlated COUNT subqueries.
 */
final class IdeaCountersTest extends IntegrationTestCase
{
    private VoteRepository $votes;
    private CommentRepository $comments;
    private IdeaRepository $ideas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->votes    = new VoteRepository($this->conn);
        $this->comments = new CommentRepository($this->conn);
        $this->ideas    = new IdeaRepository($this->conn);
    }

    /** @return array{up: int, down: int, comments: int} */
    private function counters(int $ideaId): array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT up_count, down_count, comment_count FROM ideas WHERE id = :id',
            ['id' => $ideaId],
        );
        self::assertIsArray($row);

        return ['up' => (int) $row['up_count'], 'down' => (int) $row['down_count'], 'comments' => (int) $row['comment_count']];
    }

    /** @return array{up: int, down: int, comments: int} */
    private function liveCounts(int $ideaId): array
    {
        return [
            'up'       => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id AND value > 0', ['id' => $ideaId]),
            'down'     => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id AND value < 0', ['id' => $ideaId]),
            'comments' => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments WHERE idea_id = :id AND deleted_at IS NULL', ['id' => $ideaId]),
        ];
    }

    /** @param array{up: int, down: int, comments: int} $expected */
    private function assertCounters(int $ideaId, array $expected): void
    {
        self::assertSame($expected, $this->counters($ideaId));
        self::assertSame($this->liveCounts($ideaId), $this->counters($ideaId), 'Denormalized counters drifted from the live aggregates.');
    }

    /** @return array{0: int, 1: int, 2: int} [boardId, authorId, ideaId] */
    private function fixture(string $slug): array
    {
        $boardId  = $this->insertBoard($slug);
        $authorId = $this->insertUser($slug . '-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);

        return [$boardId, $authorId, $ideaId];
    }

    // --- Votes (authenticated) -------------------------------------------

    public function test_authenticated_vote_insert_switch_and_retract_keep_counters_in_sync(): void
    {
        [$boardId, , $ideaId] = $this->fixture('ctr-auth');
        $voter                = $this->insertUser('ctr-auth-voter@example.com');

        $result = $this->votes->cast($boardId, $ideaId, $voter, 1);
        $this->assertCounters($ideaId, ['up' => 1, 'down' => 0, 'comments' => 0]);
        self::assertSame([1, 0], [$result['up_count'], $result['down_count']]);

        $result = $this->votes->cast($boardId, $ideaId, $voter, -1);
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 1, 'comments' => 0]);
        self::assertSame([0, 1], [$result['up_count'], $result['down_count']]);

        $result = $this->votes->cast($boardId, $ideaId, $voter, -1);
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 0, 'comments' => 0]);
        self::assertSame([0, 0], [$result['up_count'], $result['down_count']]);
    }

    // --- Votes (anonymous, ADR 0004) ---------------------------------------

    public function test_anonymous_vote_insert_switch_and_retract_keep_counters_in_sync(): void
    {
        [$boardId, , $ideaId] = $this->fixture('ctr-anon');

        $this->votes->castAnonymous($boardId, $ideaId, 'tok-a', -1);
        $this->votes->castAnonymous($boardId, $ideaId, 'tok-b', 1);
        $this->assertCounters($ideaId, ['up' => 1, 'down' => 1, 'comments' => 0]);

        $result = $this->votes->castAnonymous($boardId, $ideaId, 'tok-a', 1);
        $this->assertCounters($ideaId, ['up' => 2, 'down' => 0, 'comments' => 0]);
        self::assertSame([2, 0], [$result['up_count'], $result['down_count']]);

        $this->votes->castAnonymous($boardId, $ideaId, 'tok-b', 1);
        $this->assertCounters($ideaId, ['up' => 1, 'down' => 0, 'comments' => 0]);
    }

    public function test_merging_a_stale_anonymous_vote_into_an_authenticated_cast_keeps_counters_in_sync(): void
    {
        [$boardId, , $ideaId] = $this->fixture('ctr-merge');
        $voter                = $this->insertUser('ctr-merge-voter@example.com');

        // Anonymous up, then the same browser logs in and votes down:
        // the anonymous row is folded away (migration 0070 follow-up).
        $this->votes->castAnonymous($boardId, $ideaId, 'tok-merge', 1);
        $this->assertCounters($ideaId, ['up' => 1, 'down' => 0, 'comments' => 0]);

        $result = $this->votes->cast($boardId, $ideaId, $voter, -1, 'tok-merge');
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 1, 'comments' => 0]);
        self::assertSame([0, 1], [$result['up_count'], $result['down_count']]);
    }

    public function test_merge_with_unknown_anonymous_token_is_a_plain_cast(): void
    {
        [$boardId, , $ideaId] = $this->fixture('ctr-merge-none');
        $voter                = $this->insertUser('ctr-merge-none-voter@example.com');

        $this->votes->cast($boardId, $ideaId, $voter, 1, 'tok-never-voted');
        $this->assertCounters($ideaId, ['up' => 1, 'down' => 0, 'comments' => 0]);
    }

    public function test_vote_on_idea_of_a_foreign_board_changes_no_counter(): void
    {
        [, , $ideaId] = $this->fixture('ctr-scope-a');
        $otherBoardId = $this->insertBoard('ctr-scope-b');
        $voter        = $this->insertUser('ctr-scope-voter@example.com');

        // board_id mismatch: the board-scoped UPDATE must not touch the idea.
        $this->votes->cast($otherBoardId, $ideaId, $voter, 1);

        self::assertSame(['up' => 0, 'down' => 0, 'comments' => 0], $this->counters($ideaId));
    }

    // --- Comments ----------------------------------------------------------

    public function test_comment_create_soft_delete_and_purge_keep_comment_count_in_sync(): void
    {
        [, $authorId, $ideaId] = $this->fixture('ctr-comments');

        $first  = $this->comments->create($ideaId, $authorId, 'First');
        $this->comments->create($ideaId, $authorId, 'Second');
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 0, 'comments' => 2]);

        // Moderation remove (soft delete) hides it → counter drops.
        $actionId = $this->seedModerationAction('comment', $first, $this->insertBoard('ctr-comments-mod'), $this->defaultAccountId(), $authorId);
        self::assertTrue($this->comments->softDelete($ideaId, $first, $actionId));
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 0, 'comments' => 1]);

        // A repeated soft delete is a no-op — must not decrement twice.
        self::assertFalse($this->comments->softDelete($ideaId, $first, $actionId));
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 0, 'comments' => 1]);

        // Purge of the already-hidden row leaves the visible count unchanged.
        self::assertTrue($this->comments->hardDelete($first));
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 0, 'comments' => 1]);
    }

    public function test_soft_delete_with_mismatched_idea_changes_no_counter(): void
    {
        [, $authorId, $ideaId] = $this->fixture('ctr-comments-scope');
        $otherIdeaId           = $this->seedIdea($this->insertBoard('ctr-comments-scope-b'), $authorId);

        $commentId = $this->comments->create($ideaId, $authorId, 'Hello');
        $actionId  = $this->seedModerationAction('comment', $commentId, $this->insertBoard('ctr-cs-mod'), $this->defaultAccountId(), $authorId);

        self::assertFalse($this->comments->softDelete($otherIdeaId, $commentId, $actionId));
        $this->assertCounters($ideaId, ['up' => 0, 'down' => 0, 'comments' => 1]);
        $this->assertCounters($otherIdeaId, ['up' => 0, 'down' => 0, 'comments' => 0]);
    }

    // --- Deletion cascades ---------------------------------------------------

    public function test_deleting_an_account_leaves_counters_of_other_accounts_consistent(): void
    {
        $accountA = $this->insertAccount(['slug' => 'ctr-acc-a']);
        $accountB = $this->insertAccount(['slug' => 'ctr-acc-b']);
        $boardA   = $this->insertBoard('ctr-board-a', ['account_id' => $accountA]);
        $boardB   = $this->insertBoard('ctr-board-b', ['account_id' => $accountB]);
        $member   = $this->insertUser('ctr-acc-member@example.com');
        $this->insertAccountMember($accountA, $member, 'owner');

        $ideaA = $this->seedIdea($boardA, $member);
        $ideaB = $this->seedIdea($boardB, $this->insertUser('ctr-acc-b-author@example.com'));

        // A's owner is active on B's board too (identity is global).
        $this->votes->cast($boardB, $ideaB, $member, 1);
        $this->comments->create($ideaB, $member, 'From A');
        $this->votes->cast($boardA, $ideaA, $member, 1);

        self::assertTrue((new AccountRepository($this->conn))->deleteAccount($accountA));

        // A's ideas (and their counters) are gone with the cascade; users stay
        // (ADR 0001 §2c), so B's votes/comments — and counters — are untouched.
        self::assertFalse($this->conn->fetchOne('SELECT id FROM ideas WHERE id = :id', ['id' => $ideaA]));
        $this->assertCounters($ideaB, ['up' => 1, 'down' => 0, 'comments' => 1]);
    }

    // --- Read paths use the columns, not COUNT subqueries ---------------------

    public function test_read_paths_return_the_denormalized_columns(): void
    {
        [$boardId, , $ideaId] = $this->fixture('ctr-read');
        $this->conn->update('ideas', ['status' => 'planned', 'up_count' => 7, 'down_count' => 3, 'comment_count' => 5], ['id' => $ideaId]);

        // Sentinel values with no matching votes/comments rows: any read path
        // still computing COUNT subqueries would return 0 here.
        $expected = ['up_count' => 7, 'down_count' => 3, 'comment_count' => 5];

        $detail = $this->ideas->findInBoard($boardId, $ideaId);
        self::assertIsArray($detail);
        self::assertSame($expected, $this->pick($detail));

        $list = $this->ideas->listByBoard($boardId);
        self::assertSame($expected, $this->pick($list[0]));

        $roadmap = $this->ideas->roadmapByBoard($boardId);
        self::assertSame($expected, $this->pick($roadmap['planned'][0]));

        $candidates = $this->ideas->findDuplicateCandidates($boardId, 'Test idea');
        self::assertSame(7, (int) $candidates[0]['up_count']);
        self::assertSame(3, (int) $candidates[0]['down_count']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{up_count: int, down_count: int, comment_count: int}
     */
    private function pick(array $row): array
    {
        return [
            'up_count'      => (int) $row['up_count'],
            'down_count'    => (int) $row['down_count'],
            'comment_count' => (int) $row['comment_count'],
        ];
    }
}
