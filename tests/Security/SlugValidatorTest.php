<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\SlugInvalidReason;
use Votepit\Security\SlugValidator;

/**
 * Unit tests for SlugValidator (ADR 0001 §2c/§5b).
 *
 * Security crux: slugs land in the URL path (`/{account-slug}/…`) and
 * must be structurally secured against reserved-word collisions with system
 * routes — protection from rule checking, never from unguessability.
 */
final class SlugValidatorTest extends TestCase
{
    // ── valid slugs ──────────────────────────────────────────────────────

    public function test_accepts_a_valid_slug(): void
    {
        self::assertTrue(SlugValidator::isValid('my-board'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validSlugProvider(): array
    {
        return [
            'single letter'         => ['a'],
            'digits'                => ['12345'],
            'lowercase + digits'    => ['board2'],
            'multiple hyphens'      => ['my-great-board'],
            'max length (64)'       => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('validSlugProvider')]
    public function test_accepts_valid_slugs(string $slug): void
    {
        self::assertTrue(SlugValidator::isValid($slug));
        self::assertNull(SlugValidator::validate($slug));
    }

    // ── character set violations ─────────────────────────────────────────

    public function test_rejects_uppercase_letters(): void
    {
        self::assertFalse(SlugValidator::isValid('MyBoard'));
        self::assertSame(SlugInvalidReason::InvalidCharacters, SlugValidator::validate('MyBoard'));
    }

    public function test_rejects_spaces(): void
    {
        self::assertFalse(SlugValidator::isValid('my board'));
        self::assertSame(SlugInvalidReason::InvalidCharacters, SlugValidator::validate('my board'));
    }

    public function test_rejects_underscore(): void
    {
        self::assertFalse(SlugValidator::isValid('my_board'));
        self::assertSame(SlugInvalidReason::InvalidCharacters, SlugValidator::validate('my_board'));
    }

    public function test_rejects_umlaut_non_ascii(): void
    {
        self::assertFalse(SlugValidator::isValid('müllabfuhr')); // export-ok: comment-language (deliberate umlaut input)
        self::assertSame(SlugInvalidReason::InvalidCharacters, SlugValidator::validate('müllabfuhr')); // export-ok: comment-language (deliberate umlaut input)
    }

    // ── hyphen rules ─────────────────────────────────────────────────────

    public function test_rejects_leading_hyphen(): void
    {
        self::assertFalse(SlugValidator::isValid('-board'));
        self::assertSame(SlugInvalidReason::LeadingHyphen, SlugValidator::validate('-board'));
    }

    public function test_rejects_trailing_hyphen(): void
    {
        self::assertFalse(SlugValidator::isValid('board-'));
        self::assertSame(SlugInvalidReason::TrailingHyphen, SlugValidator::validate('board-'));
    }

    public function test_rejects_double_hyphen(): void
    {
        self::assertFalse(SlugValidator::isValid('my--board'));
        self::assertSame(SlugInvalidReason::DoubleHyphen, SlugValidator::validate('my--board'));
    }

    // ── length violations ────────────────────────────────────────────────

    public function test_rejects_empty_string(): void
    {
        self::assertFalse(SlugValidator::isValid(''));
        self::assertSame(SlugInvalidReason::InvalidLength, SlugValidator::validate(''));
    }

    public function test_rejects_string_longer_than_64_characters(): void
    {
        $tooLong = str_repeat('a', 65);

        self::assertFalse(SlugValidator::isValid($tooLong));
        self::assertSame(SlugInvalidReason::InvalidLength, SlugValidator::validate($tooLong));
    }

    // ── reserved word list ───────────────────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function reservedWordProvider(): array
    {
        return array_combine(
            SlugValidator::reservedWords(),
            array_map(static fn (string $word): array => [$word], SlugValidator::reservedWords()),
        );
    }

    #[DataProvider('reservedWordProvider')]
    public function test_rejects_reserved_word(string $word): void
    {
        self::assertFalse(SlugValidator::isValid($word));
        self::assertSame(SlugInvalidReason::ReservedWord, SlugValidator::validate($word));
    }

    /**
     * Account slugs go through this exact same validator (no separate list)
     * — this pins a fixed example reserved-word set so a future edit of
     * RESERVED_WORDS can't accidentally drop one of them.
     */
    public function test_reserved_words_cover_the_account_slug_example_set(): void
    {
        $expected = ['login', 'pricing', 'docs', 'api', 'app', 'de', 'en', 'admin', 'invite']; // export-ok: pricing

        foreach ($expected as $word) {
            self::assertContains($word, SlugValidator::reservedWords(), "'{$word}' must be reserved.");
        }
    }
}
