<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Domain\Premoderation;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * P-06 slice 01: the one "waiting for an answer" predicate (open category,
 * published, not merged, older than the waiting threshold, no answer from a
 * member of the account) and the worklist header counts that share it with
 * the weekly digest.
 */
final class IdeaRepositoryWaitingTest extends IntegrationTestCase
{
    private function repo(): IdeaRepository
    {
        return new IdeaRepository($this->conn);
    }

    /** Fixed "now" so the age boundaries are exact. */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-26 12:00:00');
    }

    private function daysAgo(int $days): string
    {
        return $this->now()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    /** @return array{account: int, board: int, member: int} */
    private function accountWithBoard(string $tag = 'wait'): array
    {
        $account = $this->insertAccount(['slug' => "acct-{$tag}"]);
        $member  = $this->insertUser("{$tag}-member@example.com");
        $this->insertAccountMember($account, $member, 'owner');
        $board = $this->insertBoard("board-{$tag}", ['account_id' => $account]);

        return ['account' => $account, 'board' => $board, 'member' => $member];
    }

    /** @param array<string, mixed> $overrides */
    private function idea(int $boardId, int $authorId, string $title, int $daysOld = 4, array $overrides = []): int
    {
        return $this->seedIdea($boardId, $authorId, $title, array_merge(['created_at' => $this->daysAgo($daysOld)], $overrides));
    }

    private function unanswered(int $boardId): int
    {
        return $this->repo()->countAwaitingAnswer($boardId, $this->now());
    }

    public function test_idea_older_than_three_days_without_an_answer_waits(): void
    {
        $seed = $this->accountWithBoard();
        $this->idea($seed['board'], $seed['member'], 'Old and unanswered', 4);

        self::assertSame(1, $this->unanswered($seed['board']));
    }

    public function test_threshold_is_three_days_inclusive(): void
    {
        $seed = $this->accountWithBoard();
        $this->idea($seed['board'], $seed['member'], 'Exactly three days', 3);
        $this->idea($seed['board'], $seed['member'], 'Two days old', 2);

        self::assertSame(1, $this->unanswered($seed['board']));
    }

    public function test_member_comment_answers_the_idea(): void
    {
        $seed   = $this->accountWithBoard();
        $ideaId = $this->idea($seed['board'], $seed['member'], 'Answered by staff');
        $this->seedComment($ideaId, $seed['member'], 'Thanks, we are looking at it.');

        self::assertSame(0, $this->unanswered($seed['board']));
    }

    public function test_comment_of_a_user_outside_the_account_does_not_answer(): void
    {
        $seed     = $this->accountWithBoard();
        $ideaId   = $this->idea($seed['board'], $seed['member'], 'Only a stranger commented');
        $stranger = $this->insertUser('wait-stranger@example.com');
        $this->seedComment($ideaId, $stranger, 'Me too.');

        self::assertSame(1, $this->unanswered($seed['board']));
    }

    public function test_only_the_open_category_waits(): void
    {
        $seed = $this->accountWithBoard();
        $this->idea($seed['board'], $seed['member'], 'Planned', 4, ['status' => 'planned']);
        $this->idea($seed['board'], $seed['member'], 'Declined', 4, ['status' => 'declined']);
        $this->idea($seed['board'], $seed['member'], 'Open', 4);

        self::assertSame(1, $this->unanswered($seed['board']));
    }

    public function test_custom_status_in_the_open_category_waits(): void
    {
        $seed = $this->accountWithBoard();
        $board = $this->conn->fetchAssociative('SELECT * FROM boards WHERE id = :id', ['id' => $seed['board']]);
        self::assertIsArray($board);
        $statuses = new BoardStatusRepository($this->conn);
        $statuses->materialize($board, $seed['account']);
        $customKey = $statuses->createCustom($seed['board'], 'open', 'Eingegangen', 'Received', true, true); // export-ok: comment-language

        $this->idea($seed['board'], $seed['member'], 'Custom open', 4, ['status' => $customKey]);

        self::assertSame(1, $this->unanswered($seed['board']));
    }

    public function test_pending_merged_and_deleted_ideas_do_not_wait(): void
    {
        $seed = $this->accountWithBoard();
        $this->idea($seed['board'], $seed['member'], 'Pending', 4, ['moderation_state' => Premoderation::STATE_PENDING]);
        $this->idea($seed['board'], $seed['member'], 'Merged', 4, ['merged_into_id' => $this->idea($seed['board'], $seed['member'], 'Target', 0)]);
        $this->idea($seed['board'], $seed['member'], 'Deleted', 4, ['deleted_at' => $this->daysAgo(1)]);

        self::assertSame(0, $this->unanswered($seed['board']));
    }

    public function test_worklist_unanswered_count_equals_the_digest_count_for_the_same_fixture(): void
    {
        $seed = $this->accountWithBoard('eq');
        $this->idea($seed['board'], $seed['member'], 'One');
        $this->idea($seed['board'], $seed['member'], 'Two', 9);
        $answered = $this->idea($seed['board'], $seed['member'], 'Three');
        $this->seedComment($answered, $seed['member'], 'Answer.');

        $digest   = $this->unanswered($seed['board']);
        $worklist = $this->repo()->worklistCounts($seed['account'], $seed['board'], $this->now());

        self::assertSame(2, $digest);
        self::assertSame($digest, $worklist['unanswered']);
    }

    public function test_worklist_counts_are_scoped_to_the_account(): void
    {
        $seed    = $this->accountWithBoard('scope');
        $foreign = $this->accountWithBoard('foreign');
        $this->idea($seed['board'], $seed['member'], 'Mine');
        $this->idea($foreign['board'], $foreign['member'], 'Theirs');
        $this->seedIdea($foreign['board'], $foreign['member'], 'Theirs pending', ['moderation_state' => Premoderation::STATE_PENDING]);

        $counts = $this->repo()->worklistCounts($seed['account'], null, $this->now());

        self::assertSame(1, $counts['unanswered']);
        self::assertSame(0, $counts['pending_moderation']);
    }

    public function test_worklist_counts_pending_decision_due_and_long_waits(): void
    {
        $seed = $this->accountWithBoard('cats');
        $this->idea($seed['board'], $seed['member'], 'Pending one', 1, ['moderation_state' => Premoderation::STATE_PENDING]);
        $this->idea($seed['board'], $seed['member'], 'Pending two', 1, ['moderation_state' => Premoderation::STATE_PENDING]);

        // Ten votes: 6 up, 4 down => enough votes and voters (P-02 rule).
        $enough = $this->idea($seed['board'], $seed['member'], 'Enough votes', 1);
        $this->conn->update('ideas', ['up_count' => 6, 'down_count' => 4], ['id' => $enough]);
        // Nine votes: not enough.
        $few = $this->idea($seed['board'], $seed['member'], 'Nine votes', 1);
        $this->conn->update('ideas', ['up_count' => 9, 'down_count' => 0], ['id' => $few]);
        // Enough votes but planned: no decision is due.
        $planned = $this->idea($seed['board'], $seed['member'], 'Planned with votes', 1, ['status' => 'planned']);
        $this->conn->update('ideas', ['up_count' => 10, 'down_count' => 0], ['id' => $planned]);

        $this->idea($seed['board'], $seed['member'], 'Eight days old', 8);
        $this->idea($seed['board'], $seed['member'], 'Four days old', 4);

        $counts = $this->repo()->worklistCounts($seed['account'], null, $this->now());

        self::assertSame(2, $counts['pending_moderation']);
        self::assertSame(1, $counts['decision_due']);
        self::assertSame(2, $counts['unanswered']);
        self::assertSame(1, $counts['unanswered_over_7_days']);
    }

    public function test_board_filter_limits_the_counts_to_one_board(): void
    {
        $seed  = $this->accountWithBoard('one');
        $other = $this->insertBoard('board-one-other', ['account_id' => $seed['account']]);
        $this->idea($seed['board'], $seed['member'], 'On first board');
        $this->idea($other, $seed['member'], 'On second board');

        self::assertSame(2, $this->repo()->worklistCounts($seed['account'], null, $this->now())['unanswered']);
        self::assertSame(1, $this->repo()->worklistCounts($seed['account'], $other, $this->now())['unanswered']);
    }
}
