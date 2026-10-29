<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Parsed free-text search over idea titles (board search, competitor-parity H6).
 *
 * Pure value object — no DB, no HTTP. It turns raw user input (`?q=`) into a
 * bounded list of search terms; IdeaRepository composes the SQL from it:
 *
 *  - Terms are the maximal runs of Unicode letters/digits, lowercased and
 *    de-duplicated. Everything else (whitespace, punctuation, LIKE wildcards
 *    `%`/`_`, FULLTEXT operators `+-*"<>()~@`) is a separator, so a term can
 *    never carry SQL-pattern or FULLTEXT syntax — they reach the database
 *    only as bound parameters, and a term needs no LIKE escaping.
 *  - Every term must match (AND semantics, the usual search-box expectation).
 *  - MySQL/InnoDB: terms the ft_ideas_title FULLTEXT index can find become
 *    required prefix terms of one BOOLEAN MODE query (`+dark* +mode*`).
 *    Terms it cannot find — shorter than innodb_ft_min_token_size (default 3)
 *    or on InnoDB's default stopword list — fall back to a substring match.
 *  - Other platforms (the SQLite test seam): every term is a substring match.
 *
 * Input is truncated to MAX_LENGTH characters and MAX_TERMS terms, keeping
 * the generated WHERE clause bounded regardless of what a client sends.
 */
final readonly class IdeaSearchQuery
{
    /** Maximum accepted input length in characters (longer input is truncated). */
    public const MAX_LENGTH = 100;

    /** Maximum number of distinct terms (further terms are ignored). */
    public const MAX_TERMS = 8;

    /** InnoDB's default innodb_ft_min_token_size. */
    private const FULLTEXT_MIN_TOKEN_LENGTH = 3;

    /** INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD (MySQL 8 / MariaDB default list). */
    private const FULLTEXT_STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from',
        'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to',
        'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www', // export-ok: comment-language
    ];

    /** @param non-empty-list<string> $terms */
    private function __construct(
        private string $text,
        private array $terms,
    ) {}

    /** Returns null when the input contains no searchable term (= no search). */
    public static function fromRaw(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $text = trim(mb_substr($text, 0, self::MAX_LENGTH));
        if ($text === '') {
            return null;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $matches);
        $terms = array_slice(array_values(array_unique($matches[0])), 0, self::MAX_TERMS);
        if ($terms === []) {
            return null;
        }

        return new self($text, $terms);
    }

    /** Normalised input as echoed back to the client (`active_query`). */
    public function text(): string
    {
        return $this->text;
    }

    /** @return non-empty-list<string> */
    public function terms(): array
    {
        return $this->terms;
    }

    /**
     * MySQL BOOLEAN MODE query requiring every FULLTEXT-indexable term as a
     * prefix (`+term*`). '' when no term is indexable — the caller must then
     * skip MATCH() entirely (an empty BOOLEAN MODE query matches nothing).
     */
    public function fulltextBooleanQuery(): string
    {
        $indexable = array_filter($this->terms, self::isFulltextIndexable(...));

        return implode(' ', array_map(static fn (string $term): string => '+' . $term . '*', $indexable));
    }

    /**
     * Terms the FULLTEXT index cannot find, to be matched as substrings on
     * MySQL. (On non-MySQL platforms the caller substring-matches all terms().)
     *
     * @return list<string>
     */
    public function substringTerms(): array
    {
        return array_values(array_filter(
            $this->terms,
            static fn (string $term): bool => !self::isFulltextIndexable($term),
        ));
    }

    /** LIKE pattern for a term — terms never contain `%`/`_`, so no escaping is needed. */
    public static function likePattern(string $term): string
    {
        return '%' . $term . '%';
    }

    private static function isFulltextIndexable(string $term): bool
    {
        return mb_strlen($term) >= self::FULLTEXT_MIN_TOKEN_LENGTH
            && !in_array($term, self::FULLTEXT_STOPWORDS, true);
    }
}
