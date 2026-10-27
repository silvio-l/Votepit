<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Votepit\Mail\MailBrand;
use Votepit\Mail\MailTemplate;
use Votepit\Security\BrandingValidator;

final class MailBrandTest extends TestCase
{
    public function test_a_contrasty_secondary_colour_drives_the_button(): void
    {
        $brand = MailBrand::fromColors('#112233', '#1d4ed8', MailTemplate::COLOR_INK, MailTemplate::COLOR_CTA);

        self::assertSame('#112233', $brand->ruleColor);
        self::assertSame('#1d4ed8', $brand->ctaColor);
    }

    public function test_colours_failing_white_button_text_contrast_fall_back_to_the_default(): void
    {
        // Yellow is far below 4.5:1 against white button text, so neither candidate qualifies.
        $brand = MailBrand::fromColors('#ffff00', '#ffff00', MailTemplate::COLOR_INK, MailTemplate::COLOR_CTA);

        self::assertSame(MailTemplate::COLOR_CTA, $brand->ctaColor);
        self::assertSame('#ffff00', $brand->ruleColor);
    }

    public function test_the_primary_colour_is_the_button_fallback_when_the_secondary_fails(): void
    {
        $brand = MailBrand::fromColors('#1d4ed8', '#ffff00', MailTemplate::COLOR_INK, MailTemplate::COLOR_CTA);

        self::assertSame('#1d4ed8', $brand->ctaColor);
    }

    public function test_invalid_values_use_the_votepit_defaults(): void
    {
        $brand = MailBrand::fromColors('url(https://evil.example)', 'red; x', MailTemplate::COLOR_INK, MailTemplate::COLOR_CTA);

        self::assertSame(MailTemplate::COLOR_INK, $brand->ruleColor);
        self::assertSame(MailTemplate::COLOR_CTA, $brand->ctaColor);
    }

    public function test_branded_render_uses_the_accent_and_escapes_the_content(): void
    {
        $brand = MailBrand::fromColors('#112233', '#1d4ed8', MailTemplate::COLOR_INK, MailTemplate::COLOR_CTA);

        $rendered = MailTemplate::render(
            '<script>alert(1)</script>',
            ['Hello "team"'],
            'https://feedback.example.com/acme/demo/ideas/1',
            'View on Votepit',
            brand: $brand,
        );

        self::assertStringContainsString('background-color: #1d4ed8; border-radius: 8px;', $rendered['html']);
        self::assertStringContainsString('background-color: #112233; border-radius: 10px 10px 0 0;', $rendered['html']);
        self::assertStringNotContainsString('<script>', $rendered['html']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered['html']);
        // The plaintext part is unchanged by branding.
        self::assertStringContainsString('Hello "team"', $rendered['text']);
    }

    public function test_the_default_cta_colour_passes_the_custom_colour_check_with_white_text(): void
    {
        // The Votepit default must clear the same gate a board's custom colour has to clear.
        self::assertSame(MailTemplate::COLOR_CTA, BrandingValidator::secondaryColor(MailTemplate::COLOR_CTA));
        self::assertGreaterThanOrEqual(4.5, $this->contrastWithWhite(MailTemplate::COLOR_CTA));
    }

    public function test_the_former_default_teal_fails_the_white_text_check(): void
    {
        // #1FA890 gave the white button label only 2.97:1, so it must no longer pass.
        self::assertNull(BrandingValidator::secondaryColor('#1FA890'));
        self::assertLessThan(4.5, $this->contrastWithWhite('#1FA890'));
    }

    /** WCAG 2.x contrast of a six-digit hex against white (independent of the validator). */
    private function contrastWithWhite(string $hex): float
    {
        $channel = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(substr($hex, 1, 2))
            + 0.7152 * $channel(substr($hex, 3, 2))
            + 0.0722 * $channel(substr($hex, 5, 2));

        return 1.05 / ($luminance + 0.05);
    }

    public function test_unbranded_render_keeps_the_votepit_colours(): void
    {
        $rendered = MailTemplate::render('Sign in', ['Use the link.'], 'https://feedback.example.com/login', 'Sign in');

        self::assertStringContainsString('background-color: #167665; border-radius: 8px;', $rendered['html']);
        self::assertStringContainsString('background-color: #15161a; border-radius: 10px 10px 0 0;', $rendered['html']);
        self::assertStringContainsString('src="cid:votepit-logo"', $rendered['html']);
    }
}
