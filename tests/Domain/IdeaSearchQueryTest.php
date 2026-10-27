<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\IdeaSearchQuery;

/**
 * Unit tests for IdeaSearchQuery — the pure parsing half of the board search
 * (competitor-parity H6). No DB: the SQL composition lives in IdeaRepository.
 */
final class IdeaSearchQueryTest extends TestCase
{
    public function test_null_or_blank_input_is_no_search(): void
    {
        self::assertNull(IdeaSearchQuery::fromRaw(null));
        self::assertNull(IdeaSearchQuery::fromRaw(''));
        self::assertNull(IdeaSearchQuery::fromRaw("   \t "));
    }

    public function test_input_without_any_letter_or_digit_is_no_search(): void
    {
        // LIKE wildcards / FULLTEXT operators alone must never become a filter.
        self::assertNull(IdeaSearchQuery::fromRaw('%_*+-"<>()~@'));
    }

    public function test_splits_into_lowercased_letter_and_digit_terms(): void
    {
        $query = IdeaSearchQuery::fromRaw('  Dark-Mode, für iOS 18!  '); // export-ok: comment-language

        self::assertNotNull($query);
        self::assertSame(['dark', 'mode', 'für', 'ios', '18'], $query->terms()); // export-ok: comment-language
    }

    public function test_display_text_is_trimmed_with_collapsed_whitespace(): void
    {
        $query = IdeaSearchQuery::fromRaw("  dark \n  mode ");

        self::assertNotNull($query);
        self::assertSame('dark mode', $query->text());
    }

    public function test_duplicate_terms_are_collapsed(): void
    {
        $query = IdeaSearchQuery::fromRaw('mode Mode MODE dark');

        self::assertNotNull($query);
        self::assertSame(['mode', 'dark'], $query->terms());
    }

    public function test_overlong_input_is_truncated_and_term_count_capped(): void
    {
        $query = IdeaSearchQuery::fromRaw(str_repeat('abcdefghij ', 50));

        self::assertNotNull($query);
        self::assertLessThanOrEqual(IdeaSearchQuery::MAX_LENGTH, mb_strlen($query->text()));

        $many = IdeaSearchQuery::fromRaw('a1 b2 c3 d4 e5 f6 g7 h8 i9 j10 k11 l12');
        self::assertNotNull($many);
        self::assertCount(IdeaSearchQuery::MAX_TERMS, $many->terms());
    }

    public function test_fulltext_partition_requires_every_indexable_term_as_prefix(): void
    {
        $query = IdeaSearchQuery::fromRaw('dark mode export');

        self::assertNotNull($query);
        self::assertSame('+dark* +mode* +export*', $query->fulltextBooleanQuery());
        self::assertSame([], $query->substringTerms());
    }

    public function test_terms_below_the_innodb_token_size_or_stopwords_fall_back_to_substring(): void
    {
        // "ui" (2 chars) is below innodb_ft_min_token_size (3); "the"/"for"
        // are InnoDB default stopwords — a FULLTEXT "+term" on those would
        // never match, so they are filtered by substring instead.
        $query = IdeaSearchQuery::fromRaw('the UI for dashboards');

        self::assertNotNull($query);
        self::assertSame('+dashboards*', $query->fulltextBooleanQuery());
        self::assertSame(['the', 'ui', 'for'], $query->substringTerms());
    }

    public function test_query_with_only_short_terms_has_no_fulltext_part(): void
    {
        $query = IdeaSearchQuery::fromRaw('ui 2');

        self::assertNotNull($query);
        self::assertSame('', $query->fulltextBooleanQuery());
        self::assertSame(['ui', '2'], $query->substringTerms());
    }

    public function test_like_pattern_wraps_term_in_wildcards(): void
    {
        self::assertSame('%dark%', IdeaSearchQuery::likePattern('dark'));
    }
}
