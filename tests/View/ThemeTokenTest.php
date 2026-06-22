<?php

declare(strict_types=1);

namespace Votepit\Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Theme-Token-Rendering-Tests (Issue 07 — Sprint 2)
 *
 * Beweist:
 * - AC1: base.twig definiert alle --vp-*-Tokens in :root; Login-Templates
 *        referenzieren var(--vp-*) statt hartkodierter Marken-Werte.
 * - AC2: Semantische Tokens (vote-up/down, consensus) sind unabhängige
 *        Konstantwerte — nicht von --vp-primary abgeleitet.
 * - AC3: Default-Theme entspricht der Light-Modern-Richtung aus CONTEXT.md
 *        (Ink/Schwarz Primary, warm-neutraler Hintergrund).
 */
final class ThemeTokenTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $root         = dirname(__DIR__, 2);
        $loader       = new FilesystemLoader($root . '/templates');
        $this->twig   = new Environment($loader, [
            'autoescape' => 'html',
            'cache'      => false,
        ]);
    }

    // ── AC1: :root-Block mit allen Marken-Tokens ─────────────────────────────

    public function test_base_layout_defines_all_brand_tokens_in_root(): void
    {
        $html = $this->twig->render('base.twig', []);

        self::assertStringContainsString('--vp-primary:', $html);
        self::assertStringContainsString('--vp-primary-contrast:', $html);
        self::assertStringContainsString('--vp-bg:', $html);
        self::assertStringContainsString('--vp-surface:', $html);
        self::assertStringContainsString('--vp-border:', $html);
        self::assertStringContainsString('--vp-radius:', $html);
        self::assertStringContainsString('--vp-radius-sm:', $html);
        self::assertStringContainsString('--vp-text:', $html);
        self::assertStringContainsString('--vp-text-muted:', $html);
    }

    // ── AC1: :root-Block mit allen semantischen Tokens ───────────────────────

    public function test_base_layout_defines_all_semantic_tokens_in_root(): void
    {
        $html = $this->twig->render('base.twig', []);

        self::assertStringContainsString('--vp-vote-up:', $html);
        self::assertStringContainsString('--vp-vote-down:', $html);
        self::assertStringContainsString('--vp-consensus-high:', $html);
        self::assertStringContainsString('--vp-consensus-low:', $html);
        self::assertStringContainsString('--vp-status-open:', $html);
        self::assertStringContainsString('--vp-status-planned:', $html);
        self::assertStringContainsString('--vp-status-in-progress:', $html);
        self::assertStringContainsString('--vp-status-done:', $html);
        self::assertStringContainsString('--vp-status-declined:', $html);
    }

    // ── AC1: :root-Block im HTML vorhanden ───────────────────────────────────

    public function test_base_layout_has_root_block(): void
    {
        $html = $this->twig->render('base.twig', []);
        self::assertStringContainsString(':root {', $html);
    }

    // ── AC1: Komponenten nutzen var(--vp-*), kein alter --accent ─────────────

    public function test_brand_chrome_uses_vp_primary_token(): void
    {
        $html = $this->twig->render('base.twig', []);

        // Button-Hintergrund nutzt var(--vp-primary)
        self::assertStringContainsString('background: var(--vp-primary)', $html);

        // Alter --accent-Token aus Sprint-0-Templates darf nicht mehr auftauchen
        self::assertStringNotContainsString('--accent:', $html);

        // Alter hartkodierter Blau-Akzent (#3b82f6) darf nicht als Usage erscheinen
        self::assertStringNotContainsString('#3b82f6', $html);
    }

    // ── AC1: Login-Templates erben base.twig (DOCTYPE aus base) ──────────────

    public function test_login_extends_base_layout(): void
    {
        $html = $this->twig->render('login.twig', ['csrf_token' => 'tok123']);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('--vp-primary:', $html);
        self::assertStringContainsString('var(--vp-', $html);
    }

    public function test_login_sent_extends_base_layout(): void
    {
        $html = $this->twig->render('login-sent.twig', []);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('--vp-primary:', $html);
        self::assertStringContainsString('var(--vp-', $html);
    }

    public function test_home_extends_base_layout(): void
    {
        $html = $this->twig->render('home.twig', ['title' => 'Votepit', 'status' => 'OK']);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('--vp-primary:', $html);
        self::assertStringContainsString('var(--vp-', $html);
    }

    // ── AC1: Login-Templates ohne hartkodierte Marken-Hex-Werte ─────────────

    public function test_login_template_has_no_hardcoded_brand_hex(): void
    {
        $html = $this->twig->render('login.twig', ['csrf_token' => '']);

        // Alter --accent Wert aus Sprint-0-Templates darf nicht mehr auftauchen
        self::assertStringNotContainsString('#3b82f6', $html);
        self::assertStringNotContainsString('--accent:', $html);
    }

    public function test_login_sent_template_has_no_hardcoded_brand_hex(): void
    {
        $html = $this->twig->render('login-sent.twig', []);

        self::assertStringNotContainsString('#3b82f6', $html);
        self::assertStringNotContainsString('--accent:', $html);
    }

    // ── AC2: Semantische Tokens unabhängig von --vp-primary ──────────────────

    public function test_vote_up_token_not_derived_from_primary(): void
    {
        $html = $this->twig->render('base.twig', []);

        preg_match('/--vp-vote-up\s*:\s*([^;]+);/', $html, $match);

        self::assertArrayHasKey(1, $match, '--vp-vote-up muss definiert sein');
        self::assertStringNotContainsString('--vp-primary', trim($match[1]));
    }

    public function test_vote_down_token_not_derived_from_primary(): void
    {
        $html = $this->twig->render('base.twig', []);

        preg_match('/--vp-vote-down\s*:\s*([^;]+);/', $html, $match);

        self::assertArrayHasKey(1, $match, '--vp-vote-down muss definiert sein');
        self::assertStringNotContainsString('--vp-primary', trim($match[1]));
    }

    public function test_consensus_tokens_not_derived_from_primary(): void
    {
        $html = $this->twig->render('base.twig', []);

        preg_match('/--vp-consensus-high\s*:\s*([^;]+);/', $html, $highMatch);
        preg_match('/--vp-consensus-low\s*:\s*([^;]+);/', $html, $lowMatch);

        self::assertArrayHasKey(1, $highMatch, '--vp-consensus-high muss definiert sein');
        self::assertStringNotContainsString('--vp-primary', trim($highMatch[1]));

        self::assertArrayHasKey(1, $lowMatch, '--vp-consensus-low muss definiert sein');
        self::assertStringNotContainsString('--vp-primary', trim($lowMatch[1]));
    }

    // ── AC3: Default-Theme = Light Modern (Ink/Schwarz Brand, warm-neutral BG) ─

    public function test_default_primary_is_ink_black(): void
    {
        $html = $this->twig->render('base.twig', []);

        // Ink/Schwarz — #111111 (CONTEXT.md: "Brand-Chrome = Ink (Schwarz)")
        preg_match('/--vp-primary\s*:\s*([^;]+);/', $html, $match);

        self::assertArrayHasKey(1, $match, '--vp-primary muss definiert sein');
        self::assertSame('#111111', trim($match[1]));
    }

    public function test_default_vote_up_is_green(): void
    {
        $html = $this->twig->render('base.twig', []);

        preg_match('/--vp-vote-up\s*:\s*([^;]+);/', $html, $match);

        self::assertArrayHasKey(1, $match);
        // Grün (CONTEXT.md: "funktionale Farben nur Grün (Up)")
        self::assertSame('#16a34a', trim($match[1]));
    }

    public function test_default_vote_down_is_vermillion(): void
    {
        $html = $this->twig->render('base.twig', []);

        preg_match('/--vp-vote-down\s*:\s*([^;]+);/', $html, $match);

        self::assertArrayHasKey(1, $match);
        // Vermillion (CONTEXT.md: "Vermillion (Down)")
        self::assertSame('#e03535', trim($match[1]));
    }

    // ── AC5: Kein JS nötig (keine script-Tags außer optionalen externen) ─────

    public function test_base_layout_works_without_javascript(): void
    {
        $html = $this->twig->render('base.twig', []);

        // Kein Inline-JavaScript für Theme-Tokens notwendig — reine CSS-Variablen
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('document.documentElement', $html);
    }
}
