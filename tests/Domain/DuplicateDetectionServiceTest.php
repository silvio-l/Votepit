<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\DuplicateDetectionService;
use Votepit\Domain\JaroWinklerSimilarity;
use Votepit\Domain\TitleNormalizer;

/**
 * Unit tests for DuplicateDetectionService::rank.
 *
 * Pure domain test — no DB access. Combines the real TitleNormalizer/
 * JaroWinklerSimilarity collaborators, tests exclusively through rank().
 */
final class DuplicateDetectionServiceTest extends TestCase
{
    private DuplicateDetectionService $sut;

    protected function setUp(): void
    {
        $this->sut = new DuplicateDetectionService(new TitleNormalizer(), new JaroWinklerSimilarity());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function candidate(int $id, string $title, array $overrides = []): array
    {
        return array_merge([
            'id'               => $id,
            'title'            => $title,
            'title_normalized' => (new TitleNormalizer())->normalize($title),
            'status'           => 'open',
        ], $overrides);
    }

    public function test_near_duplicate_is_surfaced_with_similarity(): void
    {
        $candidates = [$this->candidate(1, 'Dark Mode for Dashboard')];

        $ranked = $this->sut->rank('Dark Mode for Dashbord', $candidates);

        self::assertCount(1, $ranked);
        self::assertSame(1, $ranked[0]['id']);
        self::assertIsFloat($ranked[0]['similarity']);
        self::assertGreaterThan(0.82, $ranked[0]['similarity']);
    }

    public function test_unrelated_title_is_dropped(): void
    {
        $candidates = [$this->candidate(1, 'Export CSV Button')];

        $ranked = $this->sut->rank('Dark Mode for Dashboard', $candidates);

        self::assertSame([], $ranked);
    }

    public function test_ranked_by_similarity_descending(): void
    {
        $candidates = [
            $this->candidate(1, 'Dark Mode for Dashbord Widget'), // slightly further off
            $this->candidate(2, 'Dark Mode for Dashboard'),        // exact
        ];

        $ranked = $this->sut->rank('Dark Mode for Dashboard', $candidates);

        self::assertSame(2, $ranked[0]['id']);
        self::assertSame(1.0, $ranked[0]['similarity']);
    }

    public function test_limit_caps_result_count(): void
    {
        $candidates = [
            $this->candidate(1, 'Dark Mode for Dashboard'),
            $this->candidate(2, 'Dark Mode for Dashboad'),
            $this->candidate(3, 'Dark Mode 4 Dashboard'),
        ];

        $ranked = $this->sut->rank('Dark Mode for Dashboard', $candidates, 2);

        self::assertCount(2, $ranked);
    }

    public function test_candidate_without_normalized_title_is_skipped(): void
    {
        $candidates = [$this->candidate(1, 'Dark Mode for Dashboard', ['title_normalized' => ''])];

        $ranked = $this->sut->rank('Dark Mode for Dashboard', $candidates);

        self::assertSame([], $ranked);
    }

    public function test_blank_query_returns_no_candidates(): void
    {
        $candidates = [$this->candidate(1, 'Dark Mode for Dashboard')];

        self::assertSame([], $this->sut->rank('   ', $candidates));
    }

    // --- Word overlap (rephrased titles) -------------------------------------

    public function test_rephrased_title_with_the_same_content_words_is_surfaced(): void
    {
        $candidates = [$this->candidate(1, 'Wir brauchen eine mobile App')]; // export-ok: comment-language

        $ranked = $this->sut->rank('Ich möchte eine mobile App haben.', $candidates); // export-ok: comment-language

        self::assertSame([1], array_column($ranked, 'id'));
        self::assertGreaterThanOrEqual(0.82, $ranked[0]['similarity']);
    }

    public function test_word_overlap_ignores_filler_words_and_plural_endings(): void
    {
        $candidates = [$this->candidate(1, 'Please add dark themes')];

        self::assertSame([1], array_column($this->sut->rank('We need a dark theme', $candidates), 'id'));
    }

    public function test_a_single_shared_word_is_not_a_duplicate(): void
    {
        $candidates = [$this->candidate(1, 'Mobile app login')];

        self::assertSame([], $this->sut->rank('Desktop app for teams', $candidates));
    }

    // --- Near misses (input for the optional AI step) ------------------------

    public function test_near_misses_are_related_titles_below_the_threshold_best_first(): void
    {
        $candidates = [
            $this->candidate(1, 'Smartphone app for voting'),
            $this->candidate(2, 'Export to CSV'),
            $this->candidate(3, 'App for smartphones and tablets'),
        ];

        $nearMisses = $this->sut->nearMisses('Offline mode for the app', $candidates, 5);

        self::assertSame([1, 3], array_column($nearMisses, 'id'));
        self::assertSame([], $this->sut->rank('Offline mode for the app', $candidates), 'Near misses are no duplicates on their own.');
    }

    public function test_near_misses_exclude_surfaced_duplicates_and_respect_the_limit(): void
    {
        $candidates = [
            $this->candidate(1, 'Dark mode for the dashboard'),
            $this->candidate(2, 'Dark colours'),
            $this->candidate(3, 'Dark sidebar'),
            $this->candidate(4, 'Dark login page'),
        ];

        $nearMisses = $this->sut->nearMisses('Dark mode for the dashboard', $candidates, 2);

        self::assertCount(2, $nearMisses);
        self::assertNotContains(1, array_column($nearMisses, 'id'));
    }
}
