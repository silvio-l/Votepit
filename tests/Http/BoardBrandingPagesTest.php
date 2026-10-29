<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\TablePlanPolicy;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Board branding on the board-scoped pages outside the board home (idea
 * detail, submit, edit, member profile with `?board=`): the same plan gates as
 * BoardHomeAction, the downgrade rule (Q19: kept, not applied) and the
 * account scope of every read path.
 */
final class BoardBrandingPagesTest extends IntegrationTestCase
{
    /** @var array<string, string> */
    private const BRAND = [
        'primary_color'   => '#1fa890',
        'secondary_color' => '#15161a',
        'logo_url'        => 'https://example.com/logo.png',
    ];

    /** @return array{status: int, data: array<string, mixed>} */
    private function getJson(string $path, ?int $userId = null, ?PlanPolicy $policy = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        $response = $this->createApp(planPolicy: $policy)->handle($request);
        $data     = json_decode((string) $response->getBody(), true);

        self::assertIsArray($data, 'GET ' . $path . ' returned no JSON');

        return ['status' => $response->getStatusCode(), 'data' => $data];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{board_id: int, idea_id: int, author_id: int}
     */
    private function branded(string $plan, array $overrides = []): array
    {
        $this->setAccountPlan($this->defaultAccountId(), $plan);
        $boardId  = $this->insertBoard('demo', array_merge(self::BRAND, $overrides));
        $authorId = $this->insertUser('author-' . bin2hex(random_bytes(3)) . '@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId, 'Dark mode');

        return ['board_id' => $boardId, 'idea_id' => $ideaId, 'author_id' => $authorId];
    }

    // -------------------------------------------------------------------------
    // Idea detail
    // -------------------------------------------------------------------------

    public function test_idea_page_applies_colours_and_logo_on_a_plan_that_allows_them(): void
    {
        $ctx = $this->branded('lite');

        $board = $this->getJson('/demo/ideas/' . $ctx['idea_id'])['data']['board'];

        self::assertSame(self::BRAND['primary_color'], $board['primary_color']);
        self::assertSame(self::BRAND['secondary_color'], $board['secondary_color']);
        self::assertSame(self::BRAND['logo_url'], $board['logo_url']);
    }

    public function test_idea_page_keeps_the_accent_but_drops_gated_colour_and_logo_on_free(): void
    {
        $ctx = $this->branded('free');

        $board = $this->getJson('/demo/ideas/' . $ctx['idea_id'])['data']['board'];

        self::assertSame(self::BRAND['primary_color'], $board['primary_color'], 'the accent is ungated');
        self::assertNull($board['secondary_color']);
        self::assertNull($board['logo_url']);
        self::assertTrue($board['show_badge']);
    }

    public function test_idea_page_badge_and_footer_follow_the_plan(): void
    {
        $ctx = $this->branded('pro', [
            'hide_badge'      => 1,
            'back_link_url'   => 'https://example.com/back',
            'back_link_label' => 'Back to our site',
            'footer_note'     => 'Sponsored by us',
        ]);

        $business = $this->getJson('/demo/ideas/' . $ctx['idea_id'])['data']['board'];
        self::assertFalse($business['show_badge']);
        self::assertSame(['url' => 'https://example.com/back', 'label' => 'Back to our site'], $business['back_link']);
        self::assertSame('Sponsored by us', $business['footer_note']);

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $free = $this->getJson('/demo/ideas/' . $ctx['idea_id'])['data']['board'];
        self::assertTrue($free['show_badge'], 'hide_badge is not applied on Free');
        self::assertNull($free['back_link']);
        self::assertNull($free['footer_note']);
    }

    public function test_downgrade_keeps_stored_values_unapplied_and_upgrade_restores_them(): void
    {
        $ctx = $this->branded('lite');
        $path = '/demo/ideas/' . $ctx['idea_id'];

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $down = $this->getJson($path)['data']['board'];
        self::assertNull($down['secondary_color']);
        self::assertSame(self::BRAND['secondary_color'], $this->conn->fetchOne("SELECT secondary_color FROM boards WHERE slug = 'demo'"), 'stored value is kept');

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $up = $this->getJson($path)['data']['board'];
        self::assertSame(self::BRAND['secondary_color'], $up['secondary_color']);
        self::assertSame(self::BRAND['logo_url'], $up['logo_url']);
    }

    // -------------------------------------------------------------------------
    // Submit and edit
    // -------------------------------------------------------------------------

    public function test_submit_form_carries_the_board_look_on_a_plan_that_allows_it(): void
    {
        $this->branded('lite');

        $board = $this->getJson('/demo/ideas/new')['data']['board'];

        self::assertSame(self::BRAND['primary_color'], $board['primary_color']);
        self::assertSame(self::BRAND['secondary_color'], $board['secondary_color']);
        self::assertSame(self::BRAND['logo_url'], $board['logo_url']);
    }

    public function test_submit_form_drops_gated_colour_and_logo_on_free(): void
    {
        $this->branded('free');

        $board = $this->getJson('/demo/ideas/new')['data']['board'];

        self::assertSame(self::BRAND['primary_color'], $board['primary_color']);
        self::assertNull($board['secondary_color']);
        self::assertNull($board['logo_url']);
    }

    public function test_edit_form_carries_the_board_look_for_the_author_on_a_plan_that_allows_it(): void
    {
        $ctx = $this->branded('lite');

        $result = $this->getJson('/demo/ideas/' . $ctx['idea_id'] . '/edit', $ctx['author_id']);

        self::assertSame(200, $result['status']);
        self::assertSame(self::BRAND['secondary_color'], $result['data']['board']['secondary_color']);
        self::assertSame(self::BRAND['logo_url'], $result['data']['board']['logo_url']);
    }

    public function test_edit_form_drops_gated_colour_and_logo_on_free(): void
    {
        $ctx = $this->branded('free');

        $result = $this->getJson('/demo/ideas/' . $ctx['idea_id'] . '/edit', $ctx['author_id']);

        self::assertSame(200, $result['status']);
        self::assertSame(self::BRAND['primary_color'], $result['data']['board']['primary_color']);
        self::assertNull($result['data']['board']['secondary_color']);
        self::assertNull($result['data']['board']['logo_url']);
    }

    public function test_edit_form_carries_the_header_links_only_while_the_plan_includes_them(): void
    {
        $ctx = $this->branded('pro', [
            'header_links_json' => '[{"url":"https://example.com/about","label":"About us"}]',
        ]);
        $path = '/demo/ideas/' . $ctx['idea_id'] . '/edit';

        // Header links are a Pro feature (features list), which the default test policy does not grant.
        $policy = new TablePlanPolicy([
            'lite'     => ['board_limit' => 5, 'member_limit' => 5, 'agent_api' => false, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => ['secondary_color', 'logo_url', 'intro'], 'roadmap_gantt' => false],
            'pro' => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => PlanPolicy::ALL_BRANDING_FIELDS, 'roadmap_gantt' => true, 'features' => ['header_links_sections']],
        ], 'lite', 'pro', ['lite', 'pro']);

        $pro = $this->getJson($path, $ctx['author_id'], $policy)['data']['board'];
        self::assertArrayHasKey('branding_pro', $pro);
        self::assertSame('https://example.com/about', $pro['branding_pro']['header_links'][0]['url'] ?? null);

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $lite = $this->getJson($path, $ctx['author_id'], $policy)['data']['board'];
        self::assertSame([], $lite['branding_pro']['header_links']);
    }

    public function test_edit_form_of_a_board_in_another_account_is_not_found(): void
    {
        $otherAccount = $this->insertAccount(['plan' => 'pro']);
        $this->insertBoard('elsewhere', array_merge(self::BRAND, ['account_id' => $otherAccount]));
        $authorId = $this->insertUser('cross-tenant@example.com');
        $ideaId   = $this->seedIdea($this->insertBoard('own-board'), $authorId, 'Not yours');

        // The idea id exists on 'own-board', but the request names a board of another account.
        $result = $this->getJson('/elsewhere/ideas/' . $ideaId . '/edit', $authorId);

        self::assertSame(404, $result['status']);
        self::assertArrayNotHasKey('primary_color', $result['data']['board'] ?? []);
    }

    // -------------------------------------------------------------------------
    // Member profile with board context
    // -------------------------------------------------------------------------

    public function test_profile_without_a_board_context_has_no_board_block(): void
    {
        $this->branded('lite');
        $userId = $this->insertUser('profile-plain@example.com');

        $data = $this->getJson('/members/' . $userId . '/profile')['data'];

        self::assertArrayNotHasKey('board', $data);
    }

    public function test_profile_with_a_board_context_carries_the_board_look_per_plan(): void
    {
        $this->branded('lite');
        $userId = $this->insertUser('profile-team@example.com');

        $board = $this->getJson('/members/' . $userId . '/profile?board=demo')['data']['board'];
        self::assertSame('demo', $board['slug']);
        self::assertSame(self::BRAND['secondary_color'], $board['secondary_color']);
        self::assertSame(self::BRAND['logo_url'], $board['logo_url']);

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $free = $this->getJson('/members/' . $userId . '/profile?board=demo')['data']['board'];
        self::assertSame(self::BRAND['primary_color'], $free['primary_color']);
        self::assertNull($free['secondary_color']);
        self::assertNull($free['logo_url']);
    }

    public function test_profile_board_context_never_reaches_a_board_of_another_account(): void
    {
        $this->branded('lite');
        $otherAccount = $this->insertAccount(['plan' => 'pro']);
        $this->insertBoard('elsewhere', array_merge(self::BRAND, ['account_id' => $otherAccount]));
        $userId = $this->insertUser('profile-cross@example.com');

        $data = $this->getJson('/members/' . $userId . '/profile?board=elsewhere')['data'];

        self::assertArrayNotHasKey('board', $data);
    }

    public function test_profile_board_context_ignores_a_private_board_for_non_members(): void
    {
        $this->branded('lite', ['visibility' => 'private']);
        $userId = $this->insertUser('profile-private@example.com');

        $data = $this->getJson('/members/' . $userId . '/profile?board=demo')['data'];

        self::assertArrayNotHasKey('board', $data);
    }
}
