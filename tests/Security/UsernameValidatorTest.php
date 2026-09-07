<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\UsernameValidator;

/**
 * Unit tests for UsernameValidator (optional public display name,
 * profile-visibility follow-up).
 */
final class UsernameValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validUsernameProvider(): array
    {
        return [
            'min length (3)'        => ['abc', 'abc'],
            'max length (30)'       => [str_repeat('a', 30), str_repeat('a', 30)],
            'letters, digits, underscore' => ['max_mustermann1', 'max_mustermann1'],
            'trims surrounding whitespace' => ['  maxmustermann  ', 'maxmustermann'],
        ];
    }

    #[DataProvider('validUsernameProvider')]
    public function test_accepts_valid_usernames(string $raw, string $expected): void
    {
        self::assertSame($expected, UsernameValidator::validate($raw));
    }

    public function test_rejects_string_shorter_than_3_characters(): void
    {
        self::assertNull(UsernameValidator::validate('ab'));
    }

    public function test_rejects_string_longer_than_30_characters(): void
    {
        self::assertNull(UsernameValidator::validate(str_repeat('a', 31)));
    }

    public function test_rejects_leading_digit(): void
    {
        self::assertNull(UsernameValidator::validate('1max'));
    }

    public function test_rejects_leading_underscore(): void
    {
        self::assertNull(UsernameValidator::validate('_max'));
    }

    public function test_rejects_spaces(): void
    {
        self::assertNull(UsernameValidator::validate('max muster'));
    }

    public function test_rejects_umlaut_non_ascii(): void
    {
        self::assertNull(UsernameValidator::validate('müller')); // export-ok: comment-language (deliberate umlaut input)
    }

    public function test_rejects_hyphen(): void
    {
        self::assertNull(UsernameValidator::validate('max-muster'));
    }

    public function test_rejects_empty_string(): void
    {
        self::assertNull(UsernameValidator::validate(''));
    }

    public function test_rejects_whitespace_only_string(): void
    {
        self::assertNull(UsernameValidator::validate('   '));
    }
}
