<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\JaroWinklerSimilarity;

/**
 * Unit tests for JaroWinklerSimilarity::similarity.
 *
 * Tests exclusively through the public API similarity(). Reference values are
 * the customary literature examples (Winkler 1990 / common implementation comparison values).
 */
final class JaroWinklerSimilarityTest extends TestCase
{
    private JaroWinklerSimilarity $sut;

    protected function setUp(): void
    {
        $this->sut = new JaroWinklerSimilarity();
    }

    public function test_identical_strings_score_one(): void
    {
        self::assertSame(1.0, $this->sut->similarity('darkmode', 'darkmode'));
    }

    public function test_empty_strings_score_one(): void
    {
        self::assertSame(1.0, $this->sut->similarity('', ''));
    }

    public function test_completely_different_strings_score_zero(): void
    {
        self::assertSame(0.0, $this->sut->similarity('abc', 'xyz'));
    }

    public function test_one_empty_string_scores_zero(): void
    {
        self::assertSame(0.0, $this->sut->similarity('', 'darkmode'));
    }

    /**
     * @return array<string, array{string, string, float}>
     */
    public static function classicPairs(): array
    {
        return [
            'martha-marhta'  => ['martha', 'marhta', 0.961],
            'dixon-dicksonx' => ['dixon', 'dicksonx', 0.8133],
            'dwayne-duane'   => ['dwayne', 'duane', 0.840],
        ];
    }

    #[DataProvider('classicPairs')]
    public function test_classic_reference_pairs(string $a, string $b, float $expected): void
    {
        self::assertEqualsWithDelta($expected, $this->sut->similarity($a, $b), 0.001);
    }

    public function test_similarity_is_symmetric(): void
    {
        // Not literature-guaranteed for arbitrary strings (Winkler's prefix boost
        // is directional-ish only insofar as it depends on the shared prefix,
        // which is itself symmetric), but our implementation treats a/b uniformly.
        self::assertSame(
            $this->sut->similarity('dixon', 'dicksonx'),
            $this->sut->similarity('dicksonx', 'dixon'),
        );
    }

    public function test_near_duplicate_titles_score_high(): void
    {
        // Realistic scenario: a typo'd near-duplicate of an existing title.
        $score = $this->sut->similarity('darkmodefordashboard', 'darkmodefordashbord');
        self::assertGreaterThan(0.9, $score);
    }

    public function test_unrelated_titles_score_low(): void
    {
        $score = $this->sut->similarity('darkmodefordashboard', 'exportcsvbutton');
        self::assertLessThan(0.7, $score);
    }
}
