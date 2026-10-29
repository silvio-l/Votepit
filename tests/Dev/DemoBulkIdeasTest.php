<?php

declare(strict_types=1);

namespace Votepit\Tests\Dev;

use PHPUnit\Framework\TestCase;
use Votepit\Dev\DemoBulkIdeas;

/**
 * The board-at-scale demo data (G-09): 128 ideas with the reference status
 * distribution, unique titles, valid tags and valid votes.
 */
final class DemoBulkIdeasTest extends TestCase
{
    public function test_plan_has_128_ideas_with_the_reference_status_distribution(): void
    {
        $plan   = DemoBulkIdeas::plan();
        $counts = array_count_values(array_column($plan, 'status'));

        self::assertCount(128, $plan);
        self::assertSame(
            ['open' => 64, 'planned' => 24, 'in_progress' => 12, 'done' => 18, 'declined' => 8, 'archived' => 2],
            $counts,
        );
    }

    public function test_titles_are_unique(): void
    {
        $titles = array_column(DemoBulkIdeas::plan(), 'title');

        self::assertCount(count(array_unique($titles)), $titles);
    }

    public function test_every_idea_has_a_known_author_and_no_owner_vote(): void
    {
        foreach (DemoBulkIdeas::plan() as $idea) {
            self::assertNotSame('owner', $idea['author']);
            self::assertArrayNotHasKey($idea['author'], $idea['votes'], 'author must not vote on own idea');
            self::assertArrayNotHasKey('owner', $idea['votes']);
        }
    }

    public function test_votes_use_only_plus_or_minus_one_and_stay_within_the_voter_pool(): void
    {
        foreach (DemoBulkIdeas::plan() as $idea) {
            foreach ($idea['votes'] as $voter => $value) {
                self::assertContains($value, [1, -1]);
                self::assertNotSame('owner', $voter);
            }
        }
    }

    public function test_tags_are_non_empty_product_tags(): void
    {
        $allowed = ['UI', 'Integrations', 'Performance', 'Security', 'Docs', 'Mobile'];
        foreach (DemoBulkIdeas::plan() as $idea) {
            self::assertNotEmpty($idea['tags']);
            foreach ($idea['tags'] as $tag) {
                self::assertContains($tag, $allowed);
            }
        }
    }

    public function test_plan_is_deterministic(): void
    {
        self::assertSame(DemoBulkIdeas::plan(), DemoBulkIdeas::plan());
    }
}
