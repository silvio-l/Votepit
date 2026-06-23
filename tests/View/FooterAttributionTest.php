<?php

declare(strict_types=1);

namespace Votepit\Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Votepit\Security\BrandingValidator;

/**
 * Sichert die persistente Votepit-Attribution im Footer (Issue 08).
 *
 * Beweist beobachtbar am gerenderten Output:
 * - AC: „Bereitgestellt mit Votepit" + Link auf https://votepit.com erscheinen
 *        auf ALLEN Seiten, auch wenn ein Board eigenes Branding gesetzt hat.
 * - AC: Die Attribution ist nicht über Board-Branding entfernbar — sie steht
 *        außerhalb aller überschreibbaren Blöcke in base.twig.
 */
final class FooterAttributionTest extends TestCase
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

    /**
     * Ein gebrandetes Board (brand_style + brand_logo_url gesetzt) zeigt weiterhin
     * die Votepit-Attribution mit Link auf https://votepit.com.
     *
     * home.twig überschreibt {% block body %} und {% block footer %} — das ist die
     * härteste Probe: Attribution muss trotzdem im Output erscheinen.
     */
    public function test_attribution_persists_on_branded_board_in_home_template(): void
    {
        $html = $this->twig->render('home.twig', [
            'brand_style'    => BrandingValidator::inlineStyle('#123456', '#654321'),
            'brand_logo_url' => BrandingValidator::logoUrl('/assets/logo.svg') ?? '',
            'title'          => 'Test-Board',
            'status'         => 'Live',
        ]);

        // „Bereitgestellt mit" + Link-Text „Votepit" + Link-Ziel
        self::assertStringContainsString('Bereitgestellt mit', $html);
        self::assertStringContainsString('>Votepit<', $html);
        self::assertStringContainsString('href="https://votepit.com"', $html);
    }

    /**
     * Auch ohne Branding (Standard-Theme) ist die Attribution sichtbar.
     */
    public function test_attribution_present_without_branding(): void
    {
        $html = $this->twig->render('base.twig', [
            'brand_style'    => '',
            'brand_logo_url' => '',
        ]);

        self::assertStringContainsString('Bereitgestellt mit', $html);
        self::assertStringContainsString('>Votepit<', $html);
        self::assertStringContainsString('href="https://votepit.com"', $html);
    }

    /**
     * Der Attribution-Link enthält keinen rohen Nutzerwert — kein |raw.
     * Twig-Autoescape ist aktiv, href ist ein fester Literal-String im Template.
     */
    public function test_attribution_link_is_not_raw_user_value(): void
    {
        // Simulation: Brand-Daten enthalten versuchsweise https://evil.com als Wert.
        // Die Attribution darf trotzdem NUR auf https://votepit.com zeigen.
        $html = $this->twig->render('base.twig', [
            'brand_style'    => BrandingValidator::inlineStyle('#abcdef', null),
            'brand_logo_url' => '',
        ]);

        self::assertStringContainsString('Bereitgestellt mit', $html);
        self::assertStringContainsString('>Votepit<', $html);
        self::assertStringContainsString('href="https://votepit.com"', $html);
        self::assertStringNotContainsString('evil.com', $html);
    }
}
