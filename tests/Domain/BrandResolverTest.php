<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\TablePlanPolicy;
use Votepit\Tests\Support\ResolvedPlanFactory;

/**
 * Per-board branding resolution: the board's own validated values, then the
 * per-field plan gates and the Q19 downgrade rule (stored, not applied).
 */
final class BrandResolverTest extends TestCase
{
    private const BASE = ['board_limit' => null, 'member_limit' => null, 'agent_api' => false, 'visibilities' => ['public'], 'roadmap_gantt' => false];

    private function policy(): TablePlanPolicy
    {
        return new TablePlanPolicy([
            'free' => self::BASE + ['branding_fields' => [], 'features' => []],
            'lite' => self::BASE + ['branding_fields' => ['secondary_color', 'logo_url'], 'features' => []],
            'pro'  => self::BASE + ['branding_fields' => ['secondary_color', 'logo_url', 'intro', 'hide_badge'], 'features' => []],
        ], 'free', 'pro', ['free', 'lite', 'pro']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function board(array $overrides = []): array
    {
        return $overrides + ['id' => 7, 'account_id' => 3, 'primary_color' => null, 'secondary_color' => null, 'logo_url' => null];
    }

    public function test_the_board_own_values_are_shown_on_a_plan_that_includes_them(): void
    {
        $brand = BrandResolver::compute(
            $this->board(['primary_color' => '#102F28', 'secondary_color' => '#FF8F6B', 'logo_url' => 'https://example.com/logo.png']),
            ResolvedPlanFactory::of('lite'),
            $this->policy(),
        );

        self::assertSame('#102f28', $brand->primaryColor);
        self::assertSame('#ff8f6b', $brand->secondaryColor);
        self::assertSame('https://example.com/logo.png', $brand->logoUrl);
    }

    public function test_an_unset_board_shows_the_default_look(): void
    {
        $brand = BrandResolver::compute($this->board(), ResolvedPlanFactory::of('pro'), $this->policy());

        self::assertNull($brand->primaryColor);
        self::assertNull($brand->secondaryColor);
        self::assertNull($brand->logoUrl);
    }

    public function test_free_plan_drops_the_gated_fields_but_keeps_the_ungated_primary(): void
    {
        // Q19=a: a downgraded board keeps its stored values; only the gated ones are not applied.
        $brand = BrandResolver::compute(
            $this->board(['primary_color' => '#102F28', 'secondary_color' => '#FF8F6B', 'logo_url' => 'https://example.com/logo.png']),
            ResolvedPlanFactory::of('free'),
            $this->policy(),
        );

        self::assertSame('#102f28', $brand->primaryColor);
        self::assertNull($brand->secondaryColor);
        self::assertNull($brand->logoUrl);
    }

    public function test_invalid_stored_values_never_reach_the_result(): void
    {
        $brand = BrandResolver::compute(
            $this->board(['primary_color' => 'red;}', 'secondary_color' => '#12', 'logo_url' => 'javascript:alert(1)']),
            ResolvedPlanFactory::of('pro'),
            $this->policy(),
        );

        self::assertNull($brand->primaryColor);
        self::assertNull($brand->secondaryColor);
        self::assertNull($brand->logoUrl);
    }

    private const UPLOAD = '0123456789abcdef0123456789abcdef';

    public function test_an_uploaded_logo_wins_over_the_legacy_link(): void
    {
        $brand = BrandResolver::compute(
            $this->board(['logo_url' => 'https://example.com/logo.png']),
            ResolvedPlanFactory::of('lite'),
            $this->policy(),
            self::UPLOAD,
        );

        self::assertSame(self::UPLOAD, $brand->logoPublicId);
        self::assertNull($brand->logoUrl, 'the legacy link is not shown while an upload exists');
    }

    public function test_the_legacy_link_is_shown_while_no_upload_exists(): void
    {
        $brand = BrandResolver::compute($this->board(['logo_url' => 'https://example.com/logo.png']), ResolvedPlanFactory::of('lite'), $this->policy());

        self::assertNull($brand->logoPublicId);
        self::assertSame('https://example.com/logo.png', $brand->logoUrl);
    }

    public function test_a_plan_without_the_logo_gate_shows_neither_upload_nor_link(): void
    {
        $brand = BrandResolver::compute(
            $this->board(['logo_url' => 'https://example.com/logo.png']),
            ResolvedPlanFactory::of('free'),
            $this->policy(),
            self::UPLOAD,
        );

        self::assertNull($brand->logoPublicId);
        self::assertNull($brand->logoUrl);
    }

    public function test_a_malformed_upload_id_is_never_shown(): void
    {
        $brand = BrandResolver::compute($this->board(), ResolvedPlanFactory::of('lite'), $this->policy(), '../../etc/passwd');

        self::assertNull($brand->logoPublicId);
    }

    /** A policy whose Lite tier includes the v0.1.5 accent, per-mode and logo plate fields. */
    private function branded(): TablePlanPolicy
    {
        $lite = ['secondary_color', 'logo_url', 'accent_color', 'primary_color_dark', 'accent_color_dark', 'logo_plate'];

        return new TablePlanPolicy([
            'free' => self::BASE + ['branding_fields' => [], 'features' => []],
            'lite' => self::BASE + ['branding_fields' => $lite, 'features' => []],
        ], 'free', 'lite', ['free', 'lite']);
    }

    /** @return array<string, mixed> */
    private function brandedBoard(): array
    {
        return $this->board([
            'brand_accent_color'       => '#FFB547',
            'brand_primary_color_dark' => '#6FDDF0',
            'brand_accent_color_dark'  => '#FFC870',
            'brand_logo_plate'         => 'white',
            'brand_logo_ring_hidden'   => 1,
        ]);
    }

    public function test_lite_applies_the_accent_per_mode_colours_plate_and_ring(): void
    {
        $brand = BrandResolver::compute($this->brandedBoard(), ResolvedPlanFactory::of('lite'), $this->branded());

        self::assertSame('#ffb547', $brand->accentColor);
        self::assertSame('#6fddf0', $brand->primaryColorDark);
        self::assertSame('#ffc870', $brand->accentColorDark);
        self::assertSame('white', $brand->logoPlate);
        self::assertTrue($brand->logoRingHidden);
    }

    public function test_free_keeps_the_stored_values_out_of_the_result(): void
    {
        $brand = BrandResolver::compute($this->brandedBoard(), ResolvedPlanFactory::of('free'), $this->branded());

        self::assertNull($brand->accentColor);
        self::assertNull($brand->primaryColorDark);
        self::assertNull($brand->accentColorDark);
        self::assertNull($brand->logoPlate);
        self::assertFalse($brand->logoRingHidden);
    }

    public function test_an_invalid_stored_accent_or_plate_is_never_applied(): void
    {
        $board = $this->board(['brand_accent_color' => 'red; x:y', 'brand_logo_plate' => 'rainbow', 'brand_logo_ring_hidden' => 0]);
        $brand = BrandResolver::compute($board, ResolvedPlanFactory::of('lite'), $this->branded());

        self::assertNull($brand->accentColor);
        self::assertNull($brand->logoPlate);
        self::assertFalse($brand->logoRingHidden);
    }
}
