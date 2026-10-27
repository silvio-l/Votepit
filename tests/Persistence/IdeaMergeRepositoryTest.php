<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Domain\IdeaSearchQuery;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Merging duplicate ideas (competitor-parity issue 10, migration 0076).
 *
 * A merged idea (ideas.merged_into_id set) disappears from every list,
 * search, count, roadmap and duplicate-suggestion path and from
 * findInBoard() (so every write action 404s on it); its votes stay on its
 * own row (unmerge is lossless) while the canonical idea's score/up/down
 * become the group aggregate with ONE vote per voter — the vote on the
 * canonical idea wins.
 */
final class IdeaMergeRepositoryTest extends IntegrationTestCase
{
    private IdeaRepository $ideas;
    private VoteRepository $votes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ideas = new IdeaRepository($this->conn);
        $this->votes = new VoteRepository($this->conn);
    }

    /** @return array{score: int, up: int, down: int} */
    private function aggregates(int $ideaId): array
    {
        $row = $this->conn->fetchAssociative('SELECT score_cache, up_count, down_count FROM ideas WHERE id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);

        return ['score' => (int) $row['score_cache'], 'up' => (int) $row['up_count'], 'down' => (int) $row['down_count']];
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} [boardId, authorId, targetId, sourceId] */
    private function pair(string $slug = 'merge'): array
    {
        $boardId  = $this->insertBoard($slug);
        $authorId = $this->insertUser($slug . '-author@example.com');
        $target   = $this->seedIdea($boardId, $authorId, 'Dark mode');
        $source   = $this->seedIdea($boardId, $authorId, 'Dark theme please');

        return [$boardId, $authorId, $target, $source];
    }

    // --- Pre-existing bug: duplicate suggestions -------------------------

    public function test_duplicate_candidates_exclude_moderated_ideas(): void
    {
        $boardId = $this->insertBoard('dup-deleted');
        $author  = $this->insertUser();
        $visible = $this->seedIdea($boardId, $author, 'Dark Mode for Dashboard');
        $this->seedIdea($boardId, $author, 'Dark Mode for Dashboard', ['deleted_at' => '2026-01-01 00:00:00']);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->findDuplicateCandidates($boardId, 'Dark Mode for Dashboard'));

        self::assertSame([$visible], $ids);
    }

    public function test_duplicate_candidates_exclude_merged_ideas(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('dup-merged');
        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->findDuplicateCandidates($boardId, 'Dark'));

        self::assertSame([$target], $ids);
    }

    // --- Visibility of the merged idea -----------------------------------

    public function test_merged_idea_disappears_from_list_search_counts_and_roadmap(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('vis');
        $this->conn->update('ideas', ['status' => 'planned'], ['id' => $source]);

        self::assertSame(2, $this->ideas->countByBoard($boardId));
        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));

        $listed = array_map(static fn (array $r): int => (int) $r['id'], $this->ideas->listByBoard($boardId));
        self::assertSame([$target], $listed);
        self::assertSame(1, $this->ideas->countListed($boardId));
        self::assertSame(1, $this->ideas->countByBoard($boardId));
        self::assertSame(0, $this->ideas->countByBoard($boardId, 'planned'));
        self::assertSame(0, $this->ideas->countListed($boardId, search: IdeaSearchQuery::fromRaw('theme')));
        self::assertSame([], $this->ideas->roadmapByBoard($boardId)['planned']);
    }

    public function test_merged_idea_is_left_out_of_the_weekly_digest_highlights(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('digest');
        $voter = $this->insertUser('digest-voter@example.com');
        $this->votes->cast($boardId, $source, $voter, 1);
        $this->votes->cast($boardId, $target, $voter, 1);
        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));

        $highlights = $this->ideas->digestHighlights($boardId, new \DateTimeImmutable('-7 days'), 5);

        foreach (['new_ideas', 'top'] as $section) {
            $ids = array_column($highlights[$section], 'id');
            self::assertContains($target, $ids, $section);
            self::assertNotContains($source, $ids, $section);
        }
    }

    public function test_find_in_board_hides_a_merged_idea_unless_explicitly_included(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('find');
        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));

        self::assertNull($this->ideas->findInBoard($boardId, $source));

        $row = $this->ideas->findInBoard($boardId, $source, null, includeMerged: true);
        self::assertIsArray($row);
        self::assertSame($target, (int) $row['merged_into_id']);
        self::assertNull($this->ideas->findInBoard($boardId, $target)['merged_into_id'] ?? null);
    }

    public function test_merge_records_who_and_when_and_unmerge_restores_the_idea(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('undo');
        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));

        $row = $this->conn->fetchAssociative('SELECT merged_into_id, merged_at, merged_by FROM ideas WHERE id = :id', ['id' => $source]);
        self::assertIsArray($row);
        self::assertSame($target, (int) $row['merged_into_id']);
        self::assertNotNull($row['merged_at']);
        self::assertSame($moderator, (int) $row['merged_by']);
        self::assertTrue($this->ideas->hasMergedChildren($boardId, $target));
        self::assertSame([['id' => $source, 'title' => 'Dark theme please']], $this->ideas->listMergedInto($boardId, $target));

        self::assertSame($target, $this->ideas->unmerge($boardId, $source));

        $row = $this->conn->fetchAssociative('SELECT merged_into_id, merged_at, merged_by FROM ideas WHERE id = :id', ['id' => $source]);
        self::assertSame(['merged_into_id' => null, 'merged_at' => null, 'merged_by' => null], $row);
        self::assertIsArray($this->ideas->findInBoard($boardId, $source));
        self::assertFalse($this->ideas->hasMergedChildren($boardId, $target));
        self::assertNull($this->ideas->unmerge($boardId, $source), 'Unmerging an idea that is not merged is a no-op.');
    }

    public function test_merge_is_board_scoped(): void
    {
        [$boardId, $moderator, $target] = $this->pair('scope-a');
        $otherBoard = $this->insertBoard('scope-b');
        $foreign    = $this->seedIdea($otherBoard, $moderator, 'Foreign');

        self::assertFalse($this->ideas->merge($boardId, $foreign, $target, $moderator), 'Source on another board.');
        self::assertFalse($this->ideas->merge($otherBoard, $foreign, $target, $moderator), 'Target on another board.');
        self::assertNull($this->conn->fetchOne('SELECT merged_into_id FROM ideas WHERE id = :id', ['id' => $foreign]));
    }

    // --- Group vote aggregates -------------------------------------------

    public function test_canonical_aggregates_count_one_vote_per_voter_with_the_canonical_vote_winning(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('agg');
        $both      = $this->insertUser('both@example.com');
        $onlySrc   = $this->insertUser('only-src@example.com');
        $onlyTgt   = $this->insertUser('only-tgt@example.com');

        $this->votes->cast($boardId, $target, $both, -1);
        $this->votes->cast($boardId, $source, $both, 1);
        $this->votes->cast($boardId, $source, $onlySrc, 1);
        $this->votes->cast($boardId, $target, $onlyTgt, 1);
        $this->votes->castAnonymous($boardId, $source, str_repeat('a', 64), 1);
        $this->votes->castAnonymous($boardId, $target, str_repeat('a', 64), -1);
        // A self-deleted user's anonymised vote (fresh random token, decision 2b):
        // no identity to deduplicate on, so it counts once on its own.
        $this->votes->castAnonymous($boardId, $source, str_repeat('b', 64), -1);

        $sourceBefore = $this->aggregates($source);
        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));
        $this->votes->recomputeMergeGroup($boardId, $target);

        // both: -1 (canonical wins over +1), onlySrc: +1, onlyTgt: +1,
        // anon a: -1 (canonical wins), anon b: -1.
        self::assertSame(['score' => -1, 'up' => 2, 'down' => 3], $this->aggregates($target));
        self::assertSame($sourceBefore, $this->aggregates($source), 'Votes are never copied or moved.');
        self::assertSame(7, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    public function test_voting_on_the_canonical_idea_keeps_the_group_aggregate_consistent(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('cast');
        $voter = $this->insertUser('voter@example.com');
        $this->votes->cast($boardId, $source, $voter, 1);
        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));
        $this->votes->recomputeMergeGroup($boardId, $target);
        self::assertSame(['score' => 1, 'up' => 1, 'down' => 0], $this->aggregates($target));

        // The voter now votes against on the canonical idea: that vote wins.
        $result = $this->votes->cast($boardId, $target, $voter, -1);
        self::assertSame(['my_vote' => 'down', 'score' => -1, 'up_count' => 0, 'down_count' => 1], $result);

        // Retracting it falls back to the vote on the duplicate.
        $result = $this->votes->cast($boardId, $target, $voter, -1);
        self::assertSame(['my_vote' => 'none', 'score' => 1, 'up_count' => 1, 'down_count' => 0], $result);
    }

    public function test_two_duplicates_voted_by_the_same_voter_count_once(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('two');
        $second = $this->seedIdea($boardId, $moderator, 'Dark UI');
        $voter  = $this->insertUser('twice@example.com');
        $this->votes->cast($boardId, $source, $voter, 1);
        $this->votes->cast($boardId, $second, $voter, 1);

        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));
        self::assertTrue($this->ideas->merge($boardId, $second, $target, $moderator));
        $this->votes->recomputeMergeGroup($boardId, $target);

        self::assertSame(['score' => 1, 'up' => 1, 'down' => 0], $this->aggregates($target));
    }

    public function test_imported_vote_baselines_of_the_group_add_up_on_the_canonical_score(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('imported');
        // Imported ideas (migration 0077): score_cache starts at the baseline,
        // score_cache == imported_vote_count + SUM(votes.value).
        $this->conn->executeStatement('UPDATE ideas SET imported_vote_count = 10, score_cache = 10 WHERE id = :id', ['id' => $target]);
        $this->conn->executeStatement('UPDATE ideas SET imported_vote_count = 5, score_cache = 5 WHERE id = :id', ['id' => $source]);
        $both = $this->insertUser('imported-both@example.com');
        $this->votes->cast($boardId, $target, $both, 1);
        $this->votes->cast($boardId, $source, $both, 1);
        $this->votes->cast($boardId, $source, $this->insertUser('imported-src@example.com'), -1);
        $sourceBefore = $this->aggregates($source);

        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));
        $this->votes->recomputeMergeGroup($boardId, $target);

        // 10 + 5 imported, +1 (both, once), -1 (src voter); counters stay real votes only.
        self::assertSame(['score' => 15, 'up' => 1, 'down' => 1], $this->aggregates($target));

        // A new vote on the canonical idea keeps the baseline in the group aggregate.
        $this->votes->cast($boardId, $target, $this->insertUser('imported-late@example.com'), 1);
        self::assertSame(['score' => 16, 'up' => 2, 'down' => 1], $this->aggregates($target));

        self::assertNotNull($this->ideas->unmerge($boardId, $source));
        $this->votes->recomputeMergeGroup($boardId, $target);

        self::assertSame(['score' => 12, 'up' => 2, 'down' => 0], $this->aggregates($target), 'Own baseline + own votes again.');
        self::assertSame($sourceBefore, $this->aggregates($source));
    }

    public function test_unmerge_restores_the_canonical_aggregates_to_its_own_votes(): void
    {
        [$boardId, $moderator, $target, $source] = $this->pair('restore');
        $voter = $this->insertUser('r@example.com');
        $this->votes->cast($boardId, $source, $voter, 1);
        $this->votes->cast($boardId, $target, $this->insertUser('t@example.com'), -1);
        $targetBefore = $this->aggregates($target);
        $sourceBefore = $this->aggregates($source);

        self::assertTrue($this->ideas->merge($boardId, $source, $target, $moderator));
        $this->votes->recomputeMergeGroup($boardId, $target);
        self::assertSame(['score' => 0, 'up' => 1, 'down' => 1], $this->aggregates($target));

        self::assertSame($target, $this->ideas->unmerge($boardId, $source));
        $this->votes->recomputeMergeGroup($boardId, $target);

        self::assertSame($targetBefore, $this->aggregates($target));
        self::assertSame($sourceBefore, $this->aggregates($source));
    }
}
