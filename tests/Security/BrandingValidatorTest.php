<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\BrandingValidator;

/**
 * Unit tests for BrandingValidator.
 *
 * Security crux: no raw value must ever land in CSS/HTML. Every color is
 * strictly checked as hex; injection attempts are rejected (→ null → default).
 */
final class BrandingValidatorTest extends TestCase
{
    // ── color(): valid hex values ────────────────────────────────────────────

    public function test_accepts_six_digit_hex(): void
    {
        self::assertSame('#aabbcc', BrandingValidator::color('#aabbcc'));
    }

    public function test_accepts_three_digit_hex(): void
    {
        self::assertSame('#abc', BrandingValidator::color('#abc'));
    }

    public function test_normalizes_uppercase_hex_to_lowercase(): void
    {
        self::assertSame('#aabbcc', BrandingValidator::color('#AABBCC'));
    }

    public function test_trims_surrounding_whitespace(): void
    {
        self::assertSame('#112233', BrandingValidator::color('  #112233  '));
    }

    // ── color(): rejection / injection attempts ──────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidColorProvider(): array
    {
        return [
            'empty'              => [''],
            'missing hash'       => ['aabbcc'],
            'too short'          => ['#ab'],
            'too long'           => ['#aabbccdd'],
            'non-hex chars'      => ['#gggggg'],
            'named color'        => ['red'],
            'css injection ;'    => ['#abc;color:red'],
            'css breakout }'     => ['#abc}body{display:none'],
            'url() injection'    => ['#abc;background:url(x)'],
            'expression'         => ['#abc) expression(alert(1)'],
            'whitespace inside'  => ['#aa bb cc'],
            'quote breakout'     => ['#abc"'],
        ];
    }

    #[DataProvider('invalidColorProvider')]
    public function test_rejects_invalid_color(string $value): void
    {
        self::assertNull(BrandingValidator::color($value));
    }

    // ── logoUrl(): valid values ───────────────────────────────────────────────

    public function test_accepts_relative_internal_path(): void
    {
        self::assertSame('/assets/logo.svg', BrandingValidator::logoUrl('/assets/logo.svg'));
    }

    public function test_accepts_https_url(): void
    {
        self::assertSame('https://cdn.example.com/l.png', BrandingValidator::logoUrl('https://cdn.example.com/l.png'));
    }

    // ── logoUrl(): rejection / injection attempts ────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidLogoProvider(): array
    {
        return [
            'empty'             => [''],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme'       => ['data:image/svg+xml,<svg/onload=alert(1)>'],
            'plain http'        => ['http://example.com/l.png'],
            'protocol-relative' => ['//evil.com/l.png'],
            'backslash trick'   => ['/\\evil.com'],
            'relative no slash' => ['logo.png'],
            'whitespace'        => ['/assets/ logo.png'],
        ];
    }

    #[DataProvider('invalidLogoProvider')]
    public function test_rejects_invalid_logo_url(string $value): void
    {
        self::assertNull(BrandingValidator::logoUrl($value));
    }

    public function test_rejects_overlong_logo_url(): void
    {
        self::assertNull(BrandingValidator::logoUrl('/' . str_repeat('a', 600)));
    }

    // ── inlineStyle(): override string built from validated values ────────────

    public function test_inline_style_emits_validated_brand_tokens(): void
    {
        $style = BrandingValidator::inlineStyle('#112233', '#445566');

        self::assertStringContainsString('--vp-primary: #112233;', $style);
        self::assertStringContainsString('--vp-secondary: #445566;', $style);
    }

    public function test_inline_style_skips_invalid_values(): void
    {
        // Primary invalid (injection attempt), secondary valid.
        $style = BrandingValidator::inlineStyle('#abc;color:red', '#445566');

        self::assertStringNotContainsString('color:red', $style);
        self::assertStringNotContainsString('--vp-primary', $style);
        self::assertStringContainsString('--vp-secondary: #445566;', $style);
    }

    public function test_inline_style_is_empty_without_branding(): void
    {
        self::assertSame('', BrandingValidator::inlineStyle(null, null));
    }

    public function test_inline_style_never_emits_semantic_tokens(): void
    {
        $style = BrandingValidator::inlineStyle('#112233', '#445566');

        self::assertStringNotContainsString('--vp-vote-up', $style);
        self::assertStringNotContainsString('--vp-vote-down', $style);
        self::assertStringNotContainsString('--vp-consensus', $style);
    }

    // ── introText(): valid values (branding tiers) ────────────────────────────

    public function test_intro_text_accepts_plain_sentence(): void
    {
        self::assertSame(
            'Welcome to our idea board!',
            BrandingValidator::introText('Welcome to our idea board!'),
        );
    }

    public function test_intro_text_trims_surrounding_whitespace(): void
    {
        self::assertSame('Hello world', BrandingValidator::introText('   Hello world   '));
    }

    public function test_intro_text_accepts_unicode(): void
    {
        self::assertSame('Ideenboard für München — äöü', BrandingValidator::introText('Ideenboard für München — äöü')); // export-ok: comment-language (deliberate umlaut input)
    }

    // ── introText(): rejection / injection attempts ───────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidIntroProvider(): array
    {
        return [
            'empty'                    => [''],
            'whitespace only'          => ['   '],
            'script tag'               => ['<script>alert(1)</script>'],
            'script tag with text'     => ['Hello <script>alert(1)</script> world'],
            'img onerror'              => ['<img src=x onerror=alert(1)>'],
            'bare onerror fragment'    => ['" onerror="alert(1)'],
            'anchor javascript scheme' => ['<a href="javascript:alert(1)">click</a>'],
            'bare javascript scheme'   => ['javascript:alert(1)'],
            'iframe injection'         => ['<iframe src="evil.example"></iframe>'],
            'svg onload'               => ['<svg onload=alert(1)>'],
            'markdown link js'         => ['[click me](javascript:alert(1))'],
        ];
    }

    #[DataProvider('invalidIntroProvider')]
    public function test_rejects_invalid_intro_text(string $value): void
    {
        self::assertNull(BrandingValidator::introText($value));
    }

    public function test_rejects_overlong_intro_text(): void
    {
        self::assertNull(BrandingValidator::introText(str_repeat('a', 1001)));
    }

    public function test_accepts_intro_text_at_max_length(): void
    {
        $value = str_repeat('a', 1000);
        self::assertSame($value, BrandingValidator::introText($value));
    }
}
