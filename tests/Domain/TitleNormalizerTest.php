<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\TitleNormalizer;

/**
 * Unit tests for TitleNormalizer::normalize (deterministic title normalization).
 *
 * Pipeline (fixed order): mb_strtolower → NFC → Latin-ASCII → strip
 * punctuation → strip whitespace → trim.
 *
 * Tested exclusively through the public interface normalize(). Table-
 * driven with DataProvider, analogous to CsrfServiceTest / ReturnToValidatorTest.
 */
final class TitleNormalizerTest extends TestCase
{
    private TitleNormalizer $sut;

    protected function setUp(): void
    {
        $this->sut = new TitleNormalizer();
    }

    // -------------------------------------------------------------------------
    // AC1 + AC2 — Deterministic output; key != raw title
    // -------------------------------------------------------------------------

    public function test_same_input_always_yields_same_key(): void
    {
        $title = 'Dark Mode Feature';
        $key1  = $this->sut->normalize($title);
        $key2  = $this->sut->normalize($title);

        self::assertSame($key1, $key2);
    }

    public function test_key_is_lowercase_and_stripped(): void
    {
        // Key is lowercase and stripped of punctuation/whitespace — not the raw title
        $key = $this->sut->normalize('Dark Mode!');

        self::assertSame('darkmode', $key);
    }

    // -------------------------------------------------------------------------
    // AC3 — "Dark-Mode" variants → identical key
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function darkModeVariants(): array
    {
        return [
            'hyphenated'    => ['Dark-Mode'],
            'spaced'        => ['dark mode'],
            'no-separator'  => ['Darkmode'],
            'upper-spaced'  => ['DARK MODE'],
        ];
    }

    #[DataProvider('darkModeVariants')]
    public function test_dark_mode_variants_yield_identical_key(string $input): void
    {
        self::assertSame('darkmode', $this->sut->normalize($input));
    }

    // -------------------------------------------------------------------------
    // AC4 — Café (NFC + NFD-decomposed) and Cafe → identical key
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function cafeVariants(): array
    {
        return [
            'plain-ascii'    => ['Cafe'],
            'nfc-accented'   => ["Caf\u{00E9}"],      // é precomposed U+00E9
            'nfd-decomposed' => ["Cafe\u{0301}"],      // e + combining acute U+0301
        ];
    }

    #[DataProvider('cafeVariants')]
    public function test_cafe_variants_yield_identical_key(string $input): void
    {
        self::assertSame('cafe', $this->sut->normalize($input));
    }

    // -------------------------------------------------------------------------
    // AC5 — punctuation + whitespace are stripped/collapsed
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function punctuationAndWhitespaceCases(): array
    {
        return [
            'exclamation'       => ['Hello!', 'hello'],
            'question-mark'     => ['Why?', 'why'],
            'comma-separated'   => ['A, B, C', 'abc'],
            'multiple-spaces'   => ['hello   world', 'helloworld'],
            'mixed-punctuation' => ['Hello, World!', 'helloworld'],
            'hyphen-compound'   => ['state-of-the-art', 'stateoftheart'],
            'leading-trailing'  => ['  hello  ', 'hello'],
            'ellipsis'          => ['Wait...', 'wait'],
        ];
    }

    #[DataProvider('punctuationAndWhitespaceCases')]
    public function test_punctuation_and_whitespace_normalisation(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sut->normalize($input));
    }
}
