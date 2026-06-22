<?php

declare(strict_types=1);

namespace Votepit\Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Votepit\Security\BrandingValidator;

/**
 * Konsum-Seam-Tests für Per-Board-Branding (Issue 08).
 *
 * Beweist beobachtbar am gerenderten base.twig:
 * - AC2: gesetztes Branding überschreibt die Marken-Tokens inline am <html>;
 *        die semantischen Tokens (vote/consensus) bleiben unberührt; Logo erscheint.
 * - AC3: ohne Branding → kein Inline-Override (Default-Theme greift).
 * - AC4: ungültiger Farbwert → kein roher Wert im style-Attribut.
 */
final class BrandingThemeTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $root       = dirname(__DIR__, 2);
        $loader     = new FilesystemLoader($root . '/templates');
        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'cache'      => false,
        ]);
    }

    /** Rendert das Layout mit dem (validierten) Branding eines Boards. */
    private function renderWithBoard(?string $primary, ?string $secondary, ?string $logo): string
    {
        return $this->twig->render('base.twig', [
            'brand_style'    => BrandingValidator::inlineStyle($primary, $secondary),
            'brand_logo_url' => $logo !== null ? (BrandingValidator::logoUrl($logo) ?? '') : '',
        ]);
    }

    // ── AC2: Branding überschreibt Marken-Tokens inline am <html> ────────────

    public function test_branded_board_overrides_brand_tokens_inline_on_html(): void
    {
        $html = $this->renderWithBoard('#123456', '#654321', '/assets/logo.svg');

        // Inline-Override am <html> vorhanden.
        self::assertMatchesRegularExpression(
            '/<html[^>]*\sstyle="[^"]*--vp-primary:\s*#123456;[^"]*"/',
            $html,
        );
        self::assertStringContainsString('--vp-secondary: #654321;', $html);
    }

    public function test_branding_does_not_override_semantic_tokens_inline(): void
    {
        $html = $this->renderWithBoard('#123456', '#654321', null);

        // Den Inline-style-Wert am <html> isolieren …
        self::assertSame(1, preg_match('/<html[^>]*\sstyle="([^"]*)"/', $html, $m));
        $inline = $m[1];

        // … semantische Tokens dürfen dort NICHT auftauchen.
        self::assertStringNotContainsString('--vp-vote-up', $inline);
        self::assertStringNotContainsString('--vp-vote-down', $inline);
        self::assertStringNotContainsString('--vp-consensus', $inline);

        // Die semantischen :root-Defaults bleiben im Dokument erhalten.
        self::assertStringContainsString('--vp-vote-up:', $html);
        self::assertStringContainsString('--vp-vote-down:', $html);
        self::assertStringContainsString('--vp-consensus-high:', $html);
    }

    public function test_branded_logo_is_rendered_and_escaped(): void
    {
        $html = $this->renderWithBoard('#123456', null, '/assets/logo.svg');

        self::assertStringContainsString('src="/assets/logo.svg"', $html);
    }

    // ── AC3: ohne Branding → kein Inline-Override (Default-Theme) ─────────────

    public function test_board_without_branding_renders_default_theme(): void
    {
        $html = $this->renderWithBoard(null, null, null);

        // Kein style-Attribut am <html>.
        self::assertSame(0, preg_match('/<html[^>]*\sstyle=/', $html));
        // Kein Logo-Slot.
        self::assertStringNotContainsString('<img class="vp-logo"', $html);
        // Default-Token weiter vorhanden.
        self::assertStringContainsString('--vp-primary:', $html);
    }

    // ── AC4: ungültiges Hex → kein roher Wert im style-Attribut ──────────────

    public function test_invalid_color_is_not_emitted_into_style(): void
    {
        $html = $this->renderWithBoard('#abc;color:red', null, null);

        // Kein Inline-Override (ungültig → leerer brand_style → kein style-Attr).
        self::assertSame(0, preg_match('/<html[^>]*\sstyle=/', $html));
        // Der rohe Injection-Versuch taucht nirgends auf.
        self::assertStringNotContainsString('color:red', $html);
    }
}
