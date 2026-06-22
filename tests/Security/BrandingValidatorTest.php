<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\BrandingValidator;

/**
 * Unit-Tests für BrandingValidator (Issue 08).
 *
 * Sicherheits-Crux: kein roher Wert darf je in CSS/HTML landen. Jede Farbe wird
 * streng als Hex geprüft; Injection-Versuche werden abgewiesen (→ null → Default).
 */
final class BrandingValidatorTest extends TestCase
{
    // ── color(): gültige Hex-Werte ───────────────────────────────────────────

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

    // ── color(): Abweisung / Injection-Versuche ──────────────────────────────

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

    // ── logoUrl(): gültige Werte ─────────────────────────────────────────────

    public function test_accepts_relative_internal_path(): void
    {
        self::assertSame('/assets/logo.svg', BrandingValidator::logoUrl('/assets/logo.svg'));
    }

    public function test_accepts_https_url(): void
    {
        self::assertSame('https://cdn.example.com/l.png', BrandingValidator::logoUrl('https://cdn.example.com/l.png'));
    }

    // ── logoUrl(): Abweisung / Injection-Versuche ────────────────────────────

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

    // ── inlineStyle(): Override-String aus validierten Werten ─────────────────

    public function test_inline_style_emits_validated_brand_tokens(): void
    {
        $style = BrandingValidator::inlineStyle('#112233', '#445566');

        self::assertStringContainsString('--vp-primary: #112233;', $style);
        self::assertStringContainsString('--vp-secondary: #445566;', $style);
    }

    public function test_inline_style_skips_invalid_values(): void
    {
        // Primär ungültig (Injection-Versuch), Sekundär gültig.
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
}
