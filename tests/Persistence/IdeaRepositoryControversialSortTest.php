<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * The 'controversial' sort axis of IdeaRepository::listByBoard (G-11).
 *
 * Definition (CONTEXT.md, Controversial):
 *  - Only ideas with at least one up AND one down vote are controversial.
 *  - Controversial ideas rank by LEAST(up, down) DESC, then (up + down) DESC,
 *    then id DESC.
 *  - Ideas with zero up or zero down votes follow all controversial ones,
 *    ordered by (up + down) DESC, then id DESC.
 *
 * The single key `min DESC, total DESC, id DESC` yields exactly this, because
 * min is 0 for every one-sided idea and > 0 for every controversial one.
 *
 * Covered: ordering with ties, one-sided and vote-less ideas; stable paging
 * across page boundaries; status and tag filters; pinned-first; cross-tenant
 * isolation (another account's ideas and votes never appear or shift the order).
 */
final class IdeaRepositoryControversialSortTest extends IntegrationTestCase
{
    private IdeaRepository $repo;

    private int $voterSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new IdeaRepository($this->conn);
    }

    /** A new voter per vote: votes are unique per (idea, user). */
    private function voter(): int
    {
        return $this->insertUser('controv-voter-' . (++$this->voterSeq) . '@example.com');
    }

    /**
     * Seeds an idea with the given up/down votes (through seedVote, so the
     * denormalised up_count/down_count are maintained exactly as in production).
     *
     * @param array<string, mixed> $overrides
     */
    private function idea(int $boardId, int $authorId, string $title, int $up, int $down, array $overrides = []): int
    {
        $ideaId = $this->seedIdea($boardId, $authorId, $title, $overrides);
        for ($i = 0; $i < $up; ++$i) {
            $this->seedVote($ideaId, $this->voter(), 1);
        }
        for ($i = 0; $i < $down; ++$i) {
            $this->seedVote($ideaId, $this->voter(), -1);
        }

        return $ideaId;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['title'], $rows);
    }

    /**
     * Fixture covering every rule: distinct min values, equal min with
     * different totals, a full tie on min and total (id DESC decides), and
     * one-sided / vote-less ideas.
     *
     * @return array{board: int, author: int, expected: list<string>}
     */
    private function seedRankingFixture(): array
    {
        $boardId  = $this->insertBoard('controv-rank');
        $authorId = $this->insertUser('controv-author@example.com');

        // Created in this order, so the id tiebreak is observable below.
        $this->idea($boardId, $authorId, 'C high min', 4, 4);          // min 4, total 8
        $this->idea($boardId, $authorId, 'C mid min', 5, 3);           // min 3, total 8
        $this->idea($boardId, $authorId, 'C min2 big total', 6, 2);    // min 2, total 8
        $this->idea($boardId, $authorId, 'C min2 small total', 2, 2);  // min 2, total 4
        $this->idea($boardId, $authorId, 'C tie a', 1, 3);             // min 1, total 4
        $this->idea($boardId, $authorId, 'C tie b', 3, 1);             // min 1, total 4, higher id
        $this->idea($boardId, $authorId, 'Only up big', 10, 0);        // one-sided, total 10
        $this->idea($boardId, $authorId, 'Only down', 0, 7);           // one-sided, total 7
        $this->idea($boardId, $authorId, 'Only up small', 1, 0);       // one-sided, total 1
        $this->idea($boardId, $authorId, 'No votes', 0, 0);            // total 0

        return [
            'board'    => $boardId,
            'author'   => $authorId,
            'expected' => [
                'C high min',
                'C mid min',
                'C min2 big total',
                'C min2 small total',
                'C tie b',
                'C tie a',
                'Only up big',
                'Only down',
                'Only up small',
                'No votes',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Ordering
    // -------------------------------------------------------------------------

    public function test_controversial_orders_by_least_then_total_then_id(): void
    {
        $fixture = $this->seedRankingFixture();

        $rows = $this->repo->listByBoard($fixture['board'], null, 50, 0, 'controversial');

        self::assertSame($fixture['expected'], $this->titles($rows));
    }

    public function test_pinned_idea_still_leads_the_controversial_order(): void
    {
        $fixture = $this->seedRankingFixture();
        $this->idea($fixture['board'], $fixture['author'], 'Pinned, no votes', 0, 0, ['is_pinned' => 1]);

        $rows = $this->repo->listByBoard($fixture['board'], null, 50, 0, 'controversial');

        self::assertSame('Pinned, no votes', $rows[0]['title']);
    }

    public function test_unknown_sort_key_still_falls_back_to_newest_not_controversial(): void
    {
        $fixture = $this->seedRankingFixture();

        $fallback = $this->repo->listByBoard($fixture['board'], null, 50, 0, 'controversial; DROP TABLE ideas;--');
        $newest   = $this->repo->listByBoard($fixture['board'], null, 50, 0, 'newest');

        self::assertSame($this->titles($newest), $this->titles($fallback));
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    /**
     * Walking the list page by page yields the full order exactly once for
     * every page size, including sizes that split a tied pair across pages.
     */
    public function test_pagination_is_stable_across_pages(): void
    {
        $fixture = $this->seedRankingFixture();

        foreach ([1, 2, 3, 4, 5, 6] as $limit) {
            $collected = [];
            $counter = count($fixture['expected']);
            for ($offset = 0; $offset < $counter; $offset += $limit) {
                $page = $this->repo->listByBoard($fixture['board'], null, $limit, $offset, 'controversial');
                $collected = array_merge($collected, $this->titles($page));
            }

            self::assertSame($fixture['expected'], $collected, "Page size {$limit} must yield the full order once.");
        }
    }

    /**
     * The flat list of all active ideas (no status filter, archived hidden) pages
     * through the same order: an archived idea never shows up on any page.
     */
    public function test_flat_active_list_pages_through_the_order_without_archived_ideas(): void
    {
        $fixture = $this->seedRankingFixture();
        $this->idea($fixture['board'], $fixture['author'], 'Archived, min 9', 9, 9, ['status' => 'archived']);

        foreach ([2, 3] as $limit) {
            $collected = [];
            $counter = count($fixture['expected']);
            for ($offset = 0; $offset < $counter; $offset += $limit) {
                $page = $this->repo->listByBoard($fixture['board'], null, $limit, $offset, 'controversial');
                $collected = array_merge($collected, $this->titles($page));
            }

            self::assertSame($fixture['expected'], $collected, "Flat list, page size {$limit}.");
        }
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function test_status_filter_keeps_the_controversial_order_within_the_status(): void
    {
        $boardId  = $this->insertBoard('controv-status');
        $authorId = $this->insertUser('controv-status-author@example.com');

        $this->idea($boardId, $authorId, 'Planned, min 1', 1, 5, ['status' => 'planned']);
        $this->idea($boardId, $authorId, 'Planned, min 3', 3, 3, ['status' => 'planned']);
        $this->idea($boardId, $authorId, 'Open, min 9', 9, 9, ['status' => 'open']);

        $planned = $this->repo->listByBoard($boardId, 'planned', 50, 0, 'controversial');
        $open    = $this->repo->listByBoard($boardId, 'open', 50, 0, 'controversial');

        self::assertSame(['Planned, min 3', 'Planned, min 1'], $this->titles($planned));
        self::assertSame(['Open, min 9'], $this->titles($open));
    }

    public function test_tag_filter_keeps_the_controversial_order_within_the_tag(): void
    {
        $boardId  = $this->insertBoard('controv-tag');
        $authorId = $this->insertUser('controv-tag-author@example.com');
        $tagId    = $this->insertTag($boardId, 'Bug');

        // Inserted in the reverse of the expected order, so an unsorted (insertion/
        // tie) order cannot pass by accident.
        $tagged = [
            $this->idea($boardId, $authorId, 'Tagged, one-sided', 2, 0),
            $this->idea($boardId, $authorId, 'Tagged, min 1', 1, 4),
            $this->idea($boardId, $authorId, 'Tagged, min 3', 3, 3),
        ];
        $this->idea($boardId, $authorId, 'Untagged, min 5', 5, 5);
        foreach ($tagged as $ideaId) {
            $this->assignTag($ideaId, $tagId);
        }

        $rows = $this->repo->listByBoard($boardId, null, 50, 0, 'controversial', null, null, null, $tagId);

        self::assertSame(['Tagged, min 3', 'Tagged, min 1', 'Tagged, one-sided'], $this->titles($rows));
    }

    // -------------------------------------------------------------------------
    // Cross-tenant
    // -------------------------------------------------------------------------

    /**
     * Another account's board with heavily contested ideas: they never appear
     * on this board, their votes never move this board's order, and a tag of
     * the other board matches nothing here.
     */
    public function test_other_accounts_ideas_and_votes_never_affect_the_order(): void
    {
        $fixture  = $this->seedRankingFixture();
        $before   = $this->titles($this->repo->listByBoard($fixture['board'], null, 50, 0, 'controversial'));

        $otherAccount = $this->insertAccount(['slug' => 'controv-other-' . bin2hex(random_bytes(3))]);
        $otherBoard   = $this->insertBoard('controv-other-board', ['account_id' => $otherAccount]);
        $otherAuthor  = $this->insertUser('controv-other-author@example.com');
        $otherTag     = $this->insertTag($otherBoard, 'Foreign');

        // Perfectly contested and with many votes: would rank first if it leaked.
        $foreignIdea = $this->idea($otherBoard, $otherAuthor, 'Foreign contested idea', 50, 50);
        $this->assignTag($foreignIdea, $otherTag);

        $after = $this->titles($this->repo->listByBoard($fixture['board'], null, 50, 0, 'controversial'));

        self::assertSame($before, $after, 'A foreign board must not change this board\'s controversial order.');
        self::assertNotContains('Foreign contested idea', $after);
        self::assertSame(
            ['Foreign contested idea'],
            $this->titles($this->repo->listByBoard($otherBoard, null, 50, 0, 'controversial')),
        );
        self::assertSame(
            [],
            $this->repo->listByBoard($fixture['board'], null, 50, 0, 'controversial', null, null, null, $otherTag),
            'A tag of another board must match nothing on this board.',
        );
    }
}
