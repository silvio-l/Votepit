<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Board branding on the roadmap (the one board page that had no colours, logo
 * or footer). The roadmap payload carries the same branding view as the board
 * home, so the accent, topbar logo, cover, footer extras and the plan gates
 * apply there too. Cross-tenant: another account's assets never appear.
 */
final class BoardRoadmapBrandingTest extends IntegrationTestCase
{
    private const BRAND = [
        'primary_color'   => '#1fa890',
        'secondary_color' => '#15161a',
        'logo_url'        => 'https://example.com/logo.png',
    ];

    /** @return array{status: int, data: array<string, mixed>} */
    private function getJson(string $path): array
    {
        $response = $this->createApp()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertIsArray($data, 'GET ' . $path . ' returned no JSON');

        return ['status' => $response->getStatusCode(), 'data' => $data];
    }

    private function seedAsset(int $boardId, string $variant, string $publicId): void
    {
        $this->conn->insert('board_assets', [
            'board_id'     => $boardId,
            'variant'      => $variant,
            'public_id'    => $publicId,
            'file_name'    => 'x.webp',
            'content_type' => 'image/webp',
            'width'        => 2400,
            'height'       => 400,
            'created_at'   => '2026-10-25 10:00:00',
        ]);
    }

    public function test_roadmap_carries_the_board_accent_logo_and_footer_on_lite(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $this->insertBoard('demo', array_merge(self::BRAND, ['footer_note' => 'ignored on lite']));

        $board = $this->getJson('/demo/roadmap')['data']['board'];

        self::assertSame(self::BRAND['primary_color'], $board['primary_color']);
        self::assertSame(self::BRAND['secondary_color'], $board['secondary_color']);
        self::assertSame(self::BRAND['logo_url'], $board['logo_url']);
        self::assertTrue($board['show_badge']);
        self::assertNull($board['footer_note'], 'footer note is Pro only');
        self::assertSame([], $board['assets']);
    }

    public function test_roadmap_drops_gated_colour_and_logo_on_free_but_keeps_the_accent(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $this->insertBoard('demo', self::BRAND);

        $board = $this->getJson('/demo/roadmap')['data']['board'];

        self::assertSame(self::BRAND['primary_color'], $board['primary_color'], 'the accent is ungated');
        self::assertNull($board['secondary_color']);
        self::assertNull($board['logo_url']);
    }

    public function test_roadmap_applies_the_plan_gated_images_of_the_board(): void
    {
        $boardId = $this->insertBoard('demo', self::BRAND);
        $this->seedAsset($boardId, 'cover_wide', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        $this->seedAsset($boardId, 'favicon', '0f1e2d3c4b5a69788796a5b4c3d2e1f0');

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        self::assertSame([], $this->getJson('/demo/roadmap')['data']['board']['assets'], 'images stay hidden on Free');

        // Downgrade keeps the rows; a plan with the image gates serves them again.
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $assets = $this->getJson('/demo/roadmap')['data']['board']['assets'];
        self::assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90', $assets['cover_wide']);
        self::assertSame('0f1e2d3c4b5a69788796a5b4c3d2e1f0', $assets['favicon']);
    }

    public function test_roadmap_of_another_account_never_leaks_its_assets(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $this->insertBoard('demo', self::BRAND);
        $otherAccount = $this->insertAccount(['plan' => 'pro']);
        $otherBoard   = $this->insertBoard('elsewhere', array_merge(self::BRAND, ['account_id' => $otherAccount]));
        $this->seedAsset($otherBoard, 'favicon', 'ffffffffffffffffffffffffffffffff');

        $own = $this->getJson('/demo/roadmap')['data']['board'];
        self::assertSame([], $own['assets']);

        $elsewhere = $this->getJson('/elsewhere/roadmap');
        self::assertSame(404, $elsewhere['status'], 'the other account is not reachable under this one');
        self::assertArrayNotHasKey('assets', $elsewhere['data']['board'] ?? []);
    }
}
