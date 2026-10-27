<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\BoardLogoPlate;

final class BoardLogoPlateTest extends TestCase
{
    public function test_the_four_choices_are_known(): void
    {
        self::assertContains('auto', BoardLogoPlate::ALL);
        self::assertContains('brand', BoardLogoPlate::ALL);
        self::assertContains('white', BoardLogoPlate::ALL);
        self::assertContains('none', BoardLogoPlate::ALL);
        foreach (BoardLogoPlate::ALL as $choice) {
            self::assertTrue(BoardLogoPlate::isKnown($choice));
        }
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function normalizeProvider(): iterable
    {
        yield 'auto is stored as the default (null)' => ['auto', null];
        yield 'brand is kept' => ['brand', 'brand'];
        yield 'white is kept' => ['white', 'white'];
        yield 'none is kept' => ['none', 'none'];
        yield 'unknown becomes the default' => ['rainbow', null];
        yield 'upper case is not a choice' => ['WHITE', null];
        yield 'a non-string becomes the default' => [['white'], null];
        yield 'null becomes the default' => [null, null];
    }

    #[DataProvider('normalizeProvider')]
    public function test_normalize_only_returns_a_stored_choice(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, BoardLogoPlate::normalize($raw));
    }

    public function test_is_known_rejects_anything_outside_the_list(): void
    {
        self::assertFalse(BoardLogoPlate::isKnown('rainbow'));
        self::assertFalse(BoardLogoPlate::isKnown(''));
        self::assertFalse(BoardLogoPlate::isKnown(null));
    }
}
