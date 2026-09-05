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
}
