<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Extension\DuplicateConfirmationAvailability;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RecordingDuplicateConfirmer;
use Votepit\Tests\Support\StubExtension;

/**
 * Integration tests for the branding settings page.
 *
 * Boots through AppFactory with SQLite in-memory. Assertions check exclusively
 * observable behavior: HTTP status, rendered HTML (inline override) and
 * DB state (boards branding columns).
 */
final class BoardBrandingActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Seeds a board; returns its slug. */
    private function seedBoard(
        string $slug = 'demo',
        ?string $primary = null,
        ?string $secondary = null,
        ?string $logo = null,
    ): string {
        $this->conn->insert('boards', [
            'account_id'      => $this->defaultAccountId(),
            'slug'            => $slug,
            'name'            => 'Demo Board',
            // Explicit, not relying on the column default (now 'private',
            // migration 0031) — this fixture is about branding fields, not
            // visibility, and its assertions expect an anon-readable
            // 'public' board exactly as before that migration.
            'visibility'      => 'public',
            'primary_color'   => $primary,
            'secondary_color' => $secondary,
            'logo_url'        => $logo,
            'is_default'      => 1,
            'created_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $slug;
    }

    /**
     * Seeds a user (optionally admin); returns their ID.
     *
     * Board-scoped admin routes now check account_members.role
     * (AuthZMiddleware::accountAdmin()) instead of users.is_admin — an "admin" in
     * this test therefore additionally gets the owner role in the default account.
     */
    private function seedUser(string $email = 'user@example.com', bool $admin = false): int
    {
        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'      => $admin ? 1 : 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $userId = (int) $this->conn->lastInsertId();

        if ($admin) {
            $this->insertAccountMember($this->defaultAccountId(), $userId, 'owner');
        }

        return $userId;
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    /** GET-Request auf die Branding-Seite, optional als eingeloggter User. */
    private function getRequest(string $slug, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/boards/' . $slug . '/branding');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }

        return $request;
    }

    /**
     * POST request to the branding page with valid CSRF, optionally logged in.
     *
     * @param array<string, string> $fields
     */
    private function postRequest(string $slug, ?int $userId, array $fields, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
            $fields['_csrf']         = $csrfToken;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/branding')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    // -------------------------------------------------------------------------
    // AC5: admin-only — anon / non-admin rejected, admin allowed
    // -------------------------------------------------------------------------

    public function test_get_as_anon_is_rejected(): void
    {
        $slug     = $this->seedBoard();
        $response = $this->createApp()->handle($this->getRequest($slug, null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_as_non_admin_is_rejected(): void
    {
        $slug     = $this->seedBoard();
        $userId   = $this->seedUser('plain@example.com', false);
        $response = $this->createApp()->handle($this->getRequest($slug, $userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_as_admin_is_allowed(): void
    {
        $slug     = $this->seedBoard();
        $adminId  = $this->seedUser('admin@example.com', true);
        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('board_slug', $data);
    }

    // -------------------------------------------------------------------------
    // Per-board translation opt-in (ticket 11) — ungated, rides along on
    // this same GET/POST pair.
    // -------------------------------------------------------------------------

    public function test_get_returns_translation_enabled_false_by_default(): void
    {
        $slug     = $this->seedBoard();
        $adminId  = $this->seedUser('admin-translation-default@example.com', true);
        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));

        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['translation_enabled']);
    }

    public function test_post_persists_translation_enabled_and_get_reflects_it(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-translation-post@example.com', true);

        $postResponse = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'       => '#123456',
            'translation_enabled' => '1',
        ]));
        self::assertSame(200, $postResponse->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT translation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertEquals(1, $row['translation_enabled']);

        $getResponse = $this->createApp()->handle($this->getRequest($slug, $adminId));
        $data        = json_decode((string) $getResponse->getBody(), true);
        self::assertTrue($data['translation_enabled']);
    }

    public function test_post_without_translation_enabled_field_leaves_it_unchanged(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-translation-unchanged@example.com', true);

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'       => '#123456',
            'translation_enabled' => '1',
        ]));

        // A later save that omits the field entirely (e.g. an older client)
        // must not silently reset it back to disabled.
        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#654321',
        ]));

        $row = $this->conn->fetchAssociative('SELECT translation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertEquals(1, $row['translation_enabled']);
    }

    public function test_free_plan_can_enable_translation(): void
    {
        // Ungated on every plan, unlike the staged branding fields above —
        // disabling it only ever reduces API usage/cost, nothing to gate.
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-translation@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'       => '#123456',
            'translation_enabled' => '1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT translation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertEquals(1, $row['translation_enabled']);
    }

    // -------------------------------------------------------------------------
    // Per-board anonymous-voting opt-in (ADR 0004) — ungated, rides along on
    // this same GET/POST pair, exact mirror of translation_enabled above.
    // -------------------------------------------------------------------------

    public function test_kill_switch_off_refuses_switching_vote_reasons_on_but_allows_switching_them_off(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-vr-kill@example.com', true);
        $app     = $this->createApp(config: Config::fromArray($this->testConfigArray(['vote_reasons' => false])));

        $refused = $app->handle($this->postRequest($slug, $adminId, ['primary_color' => '#123456', 'vote_reasons_enabled' => '1']));
        self::assertSame(422, $refused->getStatusCode());
        self::assertArrayHasKey('vote_reasons_enabled', json_decode((string) $refused->getBody(), true)['error']['fields']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT vote_reasons_enabled FROM boards WHERE slug = :s', ['s' => $slug]));

        // A board that already has reasons on can still be switched off.
        $this->conn->update('boards', ['vote_reasons_enabled' => 1], ['slug' => $slug]);
        $off = $app->handle($this->postRequest($slug, $adminId, ['primary_color' => '#123456', 'vote_reasons_enabled' => '0']));
        self::assertSame(200, $off->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT vote_reasons_enabled FROM boards WHERE slug = :s', ['s' => $slug]));
    }

    public function test_default_installation_allows_switching_vote_reasons_on(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-vr-default@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, ['primary_color' => '#123456', 'vote_reasons_enabled' => '1']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT vote_reasons_enabled FROM boards WHERE slug = :s', ['s' => $slug]));
    }

    public function test_get_returns_anonymous_voting_enabled_false_by_default(): void
    {
        $slug     = $this->seedBoard();
        $adminId  = $this->seedUser('admin-anonvote-default@example.com', true);
        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));

        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['anonymous_voting_enabled']);
    }

    public function test_post_persists_anonymous_voting_enabled_and_get_reflects_it(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-anonvote-post@example.com', true);

        $postResponse = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'             => '#123456',
            'anonymous_voting_enabled'  => '1',
        ]));
        self::assertSame(200, $postResponse->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT anonymous_voting_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertEquals(1, $row['anonymous_voting_enabled']);

        $getResponse = $this->createApp()->handle($this->getRequest($slug, $adminId));
        $data        = json_decode((string) $getResponse->getBody(), true);
        self::assertTrue($data['anonymous_voting_enabled']);
    }

    public function test_post_without_anonymous_voting_enabled_field_leaves_it_unchanged(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-anonvote-unchanged@example.com', true);

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'            => '#123456',
            'anonymous_voting_enabled' => '1',
        ]));

        // A later save that omits the field entirely (e.g. an older client)
        // must not silently reset it back to disabled.
        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#654321',
        ]));

        $row = $this->conn->fetchAssociative('SELECT anonymous_voting_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertEquals(1, $row['anonymous_voting_enabled']);
    }

    public function test_post_can_disable_anonymous_voting_again(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-anonvote-disable@example.com', true);

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'            => '#123456',
            'anonymous_voting_enabled' => '1',
        ]));

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'            => '#123456',
            'anonymous_voting_enabled' => '0',
        ]));

        $row = $this->conn->fetchAssociative('SELECT anonymous_voting_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertEquals(0, $row['anonymous_voting_enabled']);
    }

    public function test_post_as_non_admin_with_valid_csrf_is_rejected(): void
    {
        $slug   = $this->seedBoard();
        $userId = $this->seedUser('plain2@example.com', false);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $userId, ['primary_color' => '#123456']),
        );

        self::assertSame(403, $response->getStatusCode());

        // Branding must NOT have been written.
        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored);
    }

    // -------------------------------------------------------------------------
    // AC6: CSRF-protected, board-scoped, prepared statements
    // -------------------------------------------------------------------------

    public function test_post_without_csrf_is_rejected(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin2@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['primary_color' => '#123456'], withCsrf: false),
        );

        self::assertSame(403, $response->getStatusCode());

        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored);
    }

    public function test_admin_saves_valid_branding(): void
    {
        $slug    = $this->seedBoard('demo', null, null, '/assets/logo.svg');
        $adminId = $this->seedUser('admin3@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '/assets/logo.svg',
        ]));

        // 200 + JSON {"ok": true} (no 302 redirect; SPA navigates itself)
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertSame('#123456', $row['primary_color']);
        self::assertSame('#654321', $row['secondary_color']);
        self::assertSame('/assets/logo.svg', $row['logo_url']);
    }

    // -------------------------------------------------------------------------
    // AC4: invalid hex is rejected → NULL (default), no raw value stored
    // -------------------------------------------------------------------------

    public function test_admin_save_rejects_invalid_color_to_null(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin4@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#abc;color:red',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored); // invalid → null, no raw value stored
    }

    // -------------------------------------------------------------------------
    // WCAG 1.4.3: well-formed but too-light colors are rejected with a field
    // error (not silently nulled) — distinct from malformed/XSS input above.
    // -------------------------------------------------------------------------

    public function test_admin_save_rejects_low_contrast_primary_color_with_field_error(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-contrast-primary@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#ffffcc',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('primary_color', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored, 'a rejected field must not be persisted');
    }

    public function test_admin_save_rejects_low_contrast_secondary_color_with_field_error(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('admin-contrast-secondary@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'secondary_color' => '#eeeeee',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('secondary_color', $data['error']['fields'] ?? []);
    }

    // -------------------------------------------------------------------------
    // AC2/AC3/AC4: consumption seam through the HTTP seam (rendered layout)
    // -------------------------------------------------------------------------

    public function test_branded_board_renders_inline_override(): void
    {
        $slug    = $this->seedBoard('branded', '#123456', '#654321', '/assets/logo.svg');
        $adminId = $this->seedUser('admin5@example.com', true);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(),
            true,
        );

        // JSON API returns sanitized branding fields (SPA renders the inline override)
        self::assertSame('#123456', $data['primary_color'] ?? null);
        self::assertSame('/assets/logo.svg', $data['logo_url'] ?? null);
        // No semantic token may appear directly in primary_color
        self::assertStringNotContainsString('--vp-vote-up', $data['primary_color'] ?? '');
    }

    public function test_unbranded_board_renders_default_theme(): void
    {
        $slug    = $this->seedBoard('plain-board');
        $adminId = $this->seedUser('admin6@example.com', true);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(),
            true,
        );

        // No branding set → null fields (SPA shows default theme)
        self::assertArrayHasKey('primary_color', $data);
        self::assertNull($data['primary_color']);
    }

    public function test_invalid_stored_color_falls_back_to_default(): void
    {
        // Write an invalid value directly into the DB (legacy/manual edits).
        $slug    = $this->seedBoard('legacy', '#abc;color:red');
        $adminId = $this->seedUser('admin7@example.com', true);

        $data = json_decode(
            (string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(),
            true,
        );

        // Invalid stored value → API sanitizes to null (default theme applies)
        self::assertArrayHasKey('primary_color', $data);
        self::assertNull($data['primary_color']);
    }

    public function test_unknown_board_returns_404(): void
    {
        $adminId  = $this->seedUser('admin8@example.com', true);
        $response = $this->createApp()->handle($this->getRequest('does-not-exist', $adminId));

        self::assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // Tier enforcement: board visibility gated by plan
    // =========================================================================

    public function test_get_branding_returns_visibility_and_allowed_visibilities(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('lite-vis-get@example.com', true);

        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public', $data['visibility']);
        self::assertSame(['public', 'unlisted', 'private'], $data['allowed_visibilities']);
    }

    public function test_free_plan_cannot_set_non_public_visibility(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-vis@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '',
            'secondary_color' => '',
            'logo_url'        => '',
            'visibility'      => 'private',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('public', $stored);
    }

    public function test_lite_plan_can_set_private_visibility(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('lite-vis-set@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '',
            'secondary_color' => '',
            'logo_url'        => '',
            'visibility'      => 'private',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('private', $stored);
    }

    public function test_invalid_visibility_value_returns_422(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('pro-vis-invalid@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '',
            'secondary_color' => '',
            'logo_url'        => '',
            'visibility'      => 'super-secret',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);
    }

    public function test_unknown_plan_cannot_set_non_public_visibility(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'not-a-real-plan');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('unknown-plan-vis@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '',
            'secondary_color' => '',
            'logo_url'        => '',
            'visibility'      => 'unlisted',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('public', $stored);
    }

    public function test_branding_save_without_visibility_field_leaves_it_unchanged(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug = $this->seedBoard();
        $this->conn->update('boards', ['visibility' => 'unlisted'], ['slug' => $slug]);
        $adminId = $this->seedUser('lite-vis-omit@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '',
            'logo_url'        => '',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('unlisted', $stored);
    }

    // =========================================================================
    // Branding tiers: staged field-level gating
    // =========================================================================

    public function test_get_branding_returns_allowed_branding_fields(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('lite-fields-get@example.com', true);

        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['secondary_color', 'logo_url', 'intro', 'cover_pattern', 'accent_color', 'primary_color_dark', 'accent_color_dark', 'logo_plate'], $data['allowed_branding_fields']);
    }

    public function test_free_plan_cannot_set_secondary_color(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-secondary@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('secondary_color', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT secondary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored);
    }

    public function test_free_plan_cannot_set_logo(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-logo@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'logo_url'      => '/assets/logo.svg',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('logo_url', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT logo_url FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored);
    }

    public function test_free_plan_cannot_set_intro(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-intro@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'intro'         => 'Welcome!',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('intro', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT intro FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertNull($stored);
    }

    public function test_free_plan_cannot_hide_badge(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-badge@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'hide_badge'    => '1',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('hide_badge', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT hide_badge FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertEquals(0, $stored);
    }

    public function test_free_plan_can_still_set_primary_color_and_name_only(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-primary-only@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '',
            'logo_url'        => '',
            'intro'           => '',
            'hide_badge'      => '0',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->conn->fetchOne('SELECT primary_color FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('#123456', $stored);
    }

    public function test_lite_plan_can_set_color_logo_and_intro_but_not_badge_hide(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard('demo', null, null, '/assets/logo.svg');
        $adminId = $this->seedUser('lite-full@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '/assets/logo.svg',
            'intro'           => 'Welcome to our board!',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertSame('#654321', $row['secondary_color']);
        self::assertSame('/assets/logo.svg', $row['logo_url']);
        self::assertSame('Welcome to our board!', $row['intro']);

        $badgeResponse = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'hide_badge'    => '1',
        ]));

        self::assertSame(422, $badgeResponse->getStatusCode());
    }

    public function test_pro_plan_can_set_every_staged_field_including_badge_hide(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard('demo', null, null, '/assets/logo.svg');
        $adminId = $this->seedUser('pro-full@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '/assets/logo.svg',
            'intro'           => 'Welcome to our board!',
            'hide_badge'      => '1',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertSame('#654321', $row['secondary_color']);
        self::assertSame('/assets/logo.svg', $row['logo_url']);
        self::assertSame('Welcome to our board!', $row['intro']);
        self::assertEquals(1, $row['hide_badge']);
    }

    // -------------------------------------------------------------------------
    // XSS-payload rejection on every branding field (color, logo, intro).
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function xssPayloadProvider(): array
    {
        return [
            'script tag'     => ['<script>alert(1)</script>'],
            'img onerror'    => ['<img src=x onerror=alert(1)>'],
            'javascript href' => ['javascript:alert(1)'],
            'svg onload'     => ['<svg onload=alert(1)>'],
        ];
    }

    #[DataProvider('xssPayloadProvider')]
    public function test_xss_payload_rejected_on_every_branding_field(string $payload): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('xss-' . md5($payload) . '@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => $payload,
            'secondary_color' => $payload,
            'intro'           => $payload,
        ]));

        // Field-level plan gating passes (pro allows everything) — the payload
        // itself must still never reach the DB unsanitized.
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertNull($row['primary_color'], 'primary_color must reject the XSS payload to null');
        self::assertNull($row['secondary_color'], 'secondary_color must reject the XSS payload to null');
        self::assertNull($row['intro'], 'intro must reject the XSS payload to null');
    }

    // -------------------------------------------------------------------------
    // Duplicate confirmation availability (competitor-parity issue 11)
    // -------------------------------------------------------------------------

    public function test_get_branding_reports_no_duplicate_confirmation_without_a_confirmer(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('dupconf-none@example.com', true);

        $data = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);

        self::assertNull($data['duplicate_confirmation']);
    }

    public function test_get_branding_reports_the_confirmers_availability(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('dupconf-upgrade@example.com', true);
        $app     = $this->createApp(extensions: [new StubExtension([
            'duplicate_confirmer' => new RecordingDuplicateConfirmer([], DuplicateConfirmationAvailability::UpgradeRequired),
        ])]);

        $data = json_decode((string) $app->handle($this->getRequest($slug, $adminId))->getBody(), true);

        self::assertSame('upgrade_required', $data['duplicate_confirmation']);
    }

    public function test_get_branding_reports_an_account_that_switched_the_confirmation_off(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('dupconf-disabled@example.com', true);
        $app     = $this->createApp(extensions: [new StubExtension([
            'duplicate_confirmer' => new RecordingDuplicateConfirmer([], DuplicateConfirmationAvailability::Disabled),
        ])]);

        $data = json_decode((string) $app->handle($this->getRequest($slug, $adminId))->getBody(), true);

        self::assertSame('disabled', $data['duplicate_confirmation']);
    }

    // -------------------------------------------------------------------------
    // Downgrade behaviour: stored over-plan value is frozen, not force-cleared.
    // -------------------------------------------------------------------------

    public function test_downgrade_freezes_stored_value_instead_of_force_clearing(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard('demo', null, null, '/assets/logo.svg');
        $adminId = $this->seedUser('downgrade-admin@example.com', true);

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '/assets/logo.svg',
            'intro'           => 'Welcome!',
            'hide_badge'      => '1',
        ]));

        // Downgrade the account — the admin GET should still show the raw
        // stored values (no forced DB rewrite), but allowed_branding_fields
        // now reports none of them as editable.
        $this->setAccountPlan($this->defaultAccountId(), 'free');

        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertSame('#654321', $row['secondary_color']);
        self::assertSame('/assets/logo.svg', $row['logo_url']);
        self::assertSame('Welcome!', $row['intro']);
        self::assertEquals(1, $row['hide_badge']);

        $getResponse = $this->createApp()->handle($this->getRequest($slug, $adminId));
        $data        = json_decode((string) $getResponse->getBody(), true);
        self::assertSame('#654321', $data['secondary_color']);
        self::assertSame([], $data['allowed_branding_fields']);
    }

    public function test_downgraded_account_hides_intro_and_badge_from_public_board_page(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('downgrade-public-admin@example.com', true);

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'intro'         => 'Welcome!',
            'hide_badge'    => '1',
        ]));

        $this->setAccountPlan($this->defaultAccountId(), 'free');

        $publicResponse = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/' . $slug),
        );
        $data = json_decode((string) $publicResponse->getBody(), true);

        self::assertSame(200, $publicResponse->getStatusCode());
        self::assertSame('', $data['board']['intro'], 'downgraded plan must not publicly expose stale intro');
        self::assertTrue($data['board']['show_badge'], 'downgraded plan must not honour stale hide_badge');
    }

    // -------------------------------------------------------------------------
    // Legal-operator gate (board-operator-compliance issue 02,
    // migrations/0058_add_legal_operator_identity.sql)
    // -------------------------------------------------------------------------

    public function test_setting_public_visibility_rejected_when_legal_operator_name_empty(): void
    {
        $this->conn->update('accounts', ['legal_operator_name' => null], ['id' => $this->defaultAccountId()]);
        $slug    = $this->seedBoard();
        $this->conn->update('boards', ['visibility' => 'private'], ['slug' => $slug]);
        $adminId = $this->seedUser('legal-gate-branding-public@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'visibility' => 'public',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('private', $stored, 'the gate must reject the write, not silently downgrade it');
    }

    public function test_setting_unlisted_visibility_rejected_when_legal_operator_name_empty(): void
    {
        $this->conn->update('accounts', ['legal_operator_name' => null], ['id' => $this->defaultAccountId()]);
        $slug    = $this->seedBoard();
        $this->conn->update('boards', ['visibility' => 'private'], ['slug' => $slug]);
        $adminId = $this->seedUser('legal-gate-branding-unlisted@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'visibility' => 'unlisted',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);

        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('private', $stored, 'the gate must reject the write, not silently downgrade it');
    }

    public function test_setting_private_visibility_allowed_when_legal_operator_name_empty(): void
    {
        $this->conn->update('accounts', ['legal_operator_name' => null], ['id' => $this->defaultAccountId()]);
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('legal-gate-branding-private@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'visibility' => 'private',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('private', $stored);
    }

    public function test_setting_public_visibility_accepted_when_legal_operator_name_set(): void
    {
        $this->conn->update('accounts', [
            'legal_operator_name'    => 'Acme GmbH',
            'legal_operator_address' => 'Musterstr. 1, 12345 Berlin',
            'legal_operator_email'   => 'jane@example.com',
        ], ['id' => $this->defaultAccountId()]);
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('legal-gate-branding-set@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'visibility' => 'public',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('public', $stored);
    }

    // -------------------------------------------------------------------------
    // Q19 downgrade: kept values, locked changes, re-upgrade (P-38)
    // -------------------------------------------------------------------------

    /**
     * Business admin saves every staged field; the account is then downgraded to free.
     *
     * @return array{string, int}
     */
    private function seedDowngradedBoard(): array
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard('demo', null, null, '/assets/logo.svg');
        $adminId = $this->seedUser('q19-admin@example.com', true);

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '/assets/logo.svg',
            'intro'           => 'Welcome!',
            'hide_badge'      => '1',
        ]));
        $this->setAccountPlan($this->defaultAccountId(), 'free');

        return [$slug, $adminId];
    }

    /** @return array<string, mixed> */
    private function storedBoard(string $slug): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);

        return $row;
    }

    public function test_downgrade_save_with_unchanged_locked_values_is_accepted(): void
    {
        [$slug, $adminId] = $this->seedDowngradedBoard();

        // The SPA sends back exactly what the GET showed, locked fields included.
        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#654321',
            'logo_url'        => '/assets/logo.svg',
            'intro'           => 'Welcome!',
            'hide_badge'      => '1',
            'visibility'      => 'public',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->storedBoard($slug);
        self::assertSame('#654321', $row['secondary_color']);
        self::assertSame('/assets/logo.svg', $row['logo_url']);
        self::assertSame('Welcome!', $row['intro']);
        self::assertEquals(1, $row['hide_badge']);
    }

    public function test_downgrade_save_that_omits_locked_fields_keeps_them(): void
    {
        [$slug, $adminId] = $this->seedDowngradedBoard();

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->storedBoard($slug);
        self::assertSame('#654321', $row['secondary_color']);
        self::assertSame('/assets/logo.svg', $row['logo_url']);
        self::assertSame('Welcome!', $row['intro']);
        self::assertEquals(1, $row['hide_badge']);
    }

    public function test_downgrade_changing_a_locked_value_is_rejected_with_a_stable_key(): void
    {
        [$slug, $adminId] = $this->seedDowngradedBoard();

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '#111111',
            'logo_url'        => '/assets/logo.svg',
            'intro'           => 'Welcome!',
            'hide_badge'      => '1',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        self::assertSame('plan_locked_field', $error['key']);
        self::assertSame(['secondary_color'], $error['locked_fields']);
        self::assertArrayHasKey('secondary_color', $error['fields']);
        self::assertSame('#654321', $this->storedBoard($slug)['secondary_color']);
    }

    public function test_downgrade_mixed_failure_keeps_the_generic_validation_key(): void
    {
        [$slug, $adminId] = $this->seedDowngradedBoard();

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#ffffff', // too little contrast: a normal validation error
            'secondary_color' => '#111111', // locked change
        ]));

        self::assertSame(422, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        self::assertSame('validation_error', $error['key']);
        self::assertContains('secondary_color', $error['locked_fields']);
        self::assertArrayHasKey('primary_color', $error['fields']);
    }

    public function test_downgrade_clearing_a_locked_value_is_allowed(): void
    {
        [$slug, $adminId] = $this->seedDowngradedBoard();

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => '#123456',
            'secondary_color' => '',
            'logo_url'        => '',
            'intro'           => '',
            'hide_badge'      => '',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->storedBoard($slug);
        self::assertNull($row['secondary_color']);
        self::assertNull($row['logo_url']);
        self::assertNull($row['intro']);
        self::assertEquals(0, $row['hide_badge']);
    }

    public function test_re_upgrade_makes_kept_values_active_again_without_a_write(): void
    {
        [$slug] = $this->seedDowngradedBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');

        $publicData = json_decode((string) $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/' . $slug),
        )->getBody(), true);

        self::assertSame('Welcome!', $publicData['board']['intro']);
        self::assertFalse($publicData['board']['show_badge']);
        self::assertSame('#654321', $publicData['board']['secondary_color']);
        self::assertSame('/assets/logo.svg', $publicData['board']['logo_url']);
    }

    public function test_downgraded_board_stays_private_and_is_saveable(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $this->conn->update('accounts', [
            'legal_operator_name'    => 'Acme GmbH',
            'legal_operator_address' => 'Musterstr. 1, 12345 Berlin',
            'legal_operator_email'   => 'jane@example.com',
        ], ['id' => $this->defaultAccountId()]);
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('q19-visibility@example.com', true);
        $this->conn->update('boards', ['visibility' => 'unlisted'], ['slug' => $slug]);
        $this->setAccountPlan($this->defaultAccountId(), 'free');

        // Same value as stored: kept, saving succeeds.
        $kept = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'visibility'    => 'unlisted',
        ]));
        self::assertSame(200, $kept->getStatusCode());
        self::assertSame('unlisted', $this->storedBoard($slug)['visibility']);

        // A different locked value is refused, the stored visibility stays.
        $changed = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'visibility'    => 'private',
        ]));
        self::assertSame(422, $changed->getStatusCode());
        $error = json_decode((string) $changed->getBody(), true)['error'];
        self::assertSame('plan_locked_field', $error['key']);
        self::assertSame(['visibility'], $error['locked_fields']);
        self::assertSame('unlisted', $this->storedBoard($slug)['visibility']);

        // Public is always allowed, so Free can still move the board there.
        $public = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'visibility'    => 'public',
        ]));
        self::assertSame(200, $public->getStatusCode());
        self::assertSame('public', $this->storedBoard($slug)['visibility']);
    }

    public function test_admin_get_reports_entitlements_for_the_stored_plan(): void
    {
        [$slug, $adminId] = $this->seedDowngradedBoard();

        $data = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);

        self::assertSame('free', $data['entitlements']['current_plan']);
        // The test app has no billing extension, so no one can change the plan here.
        self::assertFalse($data['entitlements']['can_change_plan']);
        // The synthetic tiers carry no explicit ladder, so the exact required_plan
        // is pinned by EntitlementsTest / CloudPlanPolicyMatrixTest instead.
        self::assertFalse($data['entitlements']['features']['intro']['allowed']);
        self::assertIsString($data['entitlements']['features']['intro']['required_plan']);
        self::assertFalse($data['entitlements']['features']['hide_badge']['allowed']);
        self::assertIsString($data['entitlements']['features']['hide_badge']['required_plan']);
        // Kept, not active: the stored value stays visible to the owner.
        self::assertSame('#654321', $data['secondary_color']);
    }

    public function test_admin_get_entitlements_never_reveal_another_account(): void
    {
        $otherAccount = $this->insertAccount(['slug' => 'other-acct', 'plan' => 'pro']);
        $this->conn->insert('boards', [
            'account_id' => $otherAccount,
            'slug'       => 'foreign',
            'name'       => 'Foreign',
            'visibility' => 'public',
            'is_default' => 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $adminId = $this->seedUser('q19-cross@example.com', true);

        $foreign = $this->createApp()->handle($this->getRequest('foreign', $adminId));
        self::assertSame(404, $foreign->getStatusCode());
        self::assertStringNotContainsString('entitlements', (string) $foreign->getBody());
    }

    // -------------------------------------------------------------------------
    // P-28c: cover pattern (Lite+ gate, Q19 downgrade freeze, validation)
    // -------------------------------------------------------------------------

    private function storedCoverPattern(string $slug): ?string
    {
        $value = $this->conn->fetchOne('SELECT cover_pattern FROM boards WHERE slug = :s', ['s' => $slug]);

        return $value === false || $value === null ? null : (string) $value;
    }

    public function test_business_sets_and_clears_a_cover_pattern(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('pattern-pro@example.com', true);

        $set = $this->createApp()->handle($this->postRequest($slug, $adminId, ['cover_pattern' => 'waves']));
        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        self::assertSame('waves', $this->storedCoverPattern($slug));

        $cleared = $this->createApp()->handle($this->postRequest($slug, $adminId, ['cover_pattern' => '']));
        self::assertSame(200, $cleared->getStatusCode());
        self::assertNull($this->storedCoverPattern($slug));
    }

    public function test_lite_sets_a_cover_pattern(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('pattern-lite@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, ['cover_pattern' => 'dots']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('dots', $this->storedCoverPattern($slug));
    }

    public function test_free_cannot_set_a_cover_pattern_and_the_error_names_the_field(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('pattern-free@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, ['cover_pattern' => 'stripes']));

        self::assertSame(422, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        self::assertSame('plan_locked_field', $error['key']);
        self::assertContains('cover_pattern', $error['locked_fields']);
        self::assertNull($this->storedCoverPattern($slug));
    }

    public function test_unknown_cover_pattern_is_a_field_error(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('pattern-unknown@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, ['cover_pattern' => 'confetti']));

        self::assertSame(422, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        self::assertArrayHasKey('cover_pattern', $error['fields']);
        self::assertNull($this->storedCoverPattern($slug));
    }

    public function test_downgrade_keeps_the_pattern_and_upgrade_restores_it(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('pattern-q19@example.com', true);
        $this->createApp()->handle($this->postRequest($slug, $adminId, ['cover_pattern' => 'bokeh']));

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        self::assertSame('bokeh', $this->storedCoverPattern($slug), 'stored value is frozen, not cleared');
        $get = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);
        self::assertSame('bokeh', $get['cover_pattern']);
        self::assertArrayHasKey('logo_public_id', $get);
        self::assertNotContains('cover_pattern', $get['allowed_branding_fields']);

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $again = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);
        self::assertContains('cover_pattern', $again['allowed_branding_fields']);
        self::assertSame('bokeh', $again['cover_pattern']);
    }

    public function test_omitting_the_pattern_keeps_it_and_a_locked_resend_of_the_same_value_is_accepted(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('pattern-keep@example.com', true);
        $this->createApp()->handle($this->postRequest($slug, $adminId, ['cover_pattern' => 'grid']));

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $omitted = $this->createApp()->handle($this->postRequest($slug, $adminId, ['primary_color' => '']));
        self::assertSame(200, $omitted->getStatusCode(), (string) $omitted->getBody());
        self::assertSame('grid', $this->storedCoverPattern($slug));
    }

    // -------------------------------------------------------------------------
    // P-26: display mode (system | light | dark), available on every plan (45a)
    // -------------------------------------------------------------------------

    private function storedThemeMode(string $slug): ?string
    {
        $value = $this->conn->fetchOne('SELECT theme_mode FROM boards WHERE slug = :s', ['s' => $slug]);

        return $value === false || $value === null ? null : (string) $value;
    }

    public function test_free_board_sets_a_display_mode_without_a_plan_gate(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('theme-free@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, ['theme_mode' => 'dark']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('dark', $this->storedThemeMode($slug));

        $get = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);
        self::assertSame('dark', $get['theme_mode']);
        self::assertNotContains('theme_mode', $get['allowed_branding_fields'] ?? []);
    }

    public function test_a_board_without_a_stored_mode_reads_as_system(): void
    {
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('theme-default@example.com', true);

        $get = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);
        self::assertSame('system', $get['theme_mode']);
    }

    public function test_every_known_mode_is_accepted_and_unknown_values_are_field_errors(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('theme-enum@example.com', true);

        foreach (['light', 'system', 'dark'] as $mode) {
            $ok = $this->createApp()->handle($this->postRequest($slug, $adminId, ['theme_mode' => $mode]));
            self::assertSame(200, $ok->getStatusCode(), $mode);
            self::assertSame($mode, $this->storedThemeMode($slug));
        }

        $bad = $this->createApp()->handle($this->postRequest($slug, $adminId, ['theme_mode' => 'neon']));
        self::assertSame(422, $bad->getStatusCode());
        $error = json_decode((string) $bad->getBody(), true)['error'];
        self::assertArrayHasKey('theme_mode', $error['fields']);
        self::assertSame('dark', $this->storedThemeMode($slug), 'an invalid value writes nothing');
    }

    public function test_another_account_cannot_set_the_display_mode(): void
    {
        $otherAccount = $this->insertAccount(['slug' => 'theme-other', 'plan' => 'pro']);
        $this->conn->insert('boards', [
            'account_id' => $otherAccount,
            'slug'       => 'theme-foreign',
            'name'       => 'Foreign',
            'visibility' => 'public',
            'is_default' => 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $adminId = $this->seedUser('theme-cross@example.com', true);

        $response = $this->createApp()->handle($this->postRequest('theme-foreign', $adminId, ['theme_mode' => 'dark']));
        self::assertSame(404, $response->getStatusCode());
        self::assertNotSame('dark', $this->storedThemeMode('theme-foreign'));
    }

    public function test_a_frozen_board_rejects_a_display_mode_change(): void
    {
        $slug = $this->seedBoard();
        $this->conn->executeStatement("UPDATE boards SET compliance_frozen_at = '2026-10-01 00:00:00' WHERE slug = :s", ['s' => $slug]);
        $adminId = $this->seedUser('theme-frozen@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, ['theme_mode' => 'dark']));
        self::assertNotSame(200, $response->getStatusCode());
        self::assertNotSame('dark', $this->storedThemeMode($slug));
    }

    // -------------------------------------------------------------------------
    // Dogfood v0.1.5 (items 07-09): accent, per-mode colours, logo plate and ring
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function brandRow(string $slug): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);

        return is_array($row) ? $row : [];
    }

    public function test_free_plan_cannot_set_accent_or_per_mode_colours(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-accent@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'accent_color'       => '#FFB547',
            'primary_color_dark' => '#6fddf0',
            'accent_color_dark'  => '#ffc870',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_locked_field', $data['error']['key']);
        self::assertSame(['accent_color', 'primary_color_dark', 'accent_color_dark'], $data['error']['locked_fields']);
        $row = $this->brandRow($slug);
        self::assertNull($row['brand_accent_color']);
        self::assertNull($row['brand_primary_color_dark']);
        self::assertNull($row['brand_accent_color_dark']);
    }

    public function test_lite_plan_saves_accent_and_per_mode_colours_and_get_reflects_them(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard('demo', '#005F73', '#0B122A');
        $adminId = $this->seedUser('lite-accent@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'      => '#005F73',
            'accent_color'       => '#ffb547',
            'primary_color_dark' => '#6fddf0',
            'accent_color_dark'  => '#ffc870',
        ]));
        self::assertSame(200, $response->getStatusCode());

        $row = $this->brandRow($slug);
        self::assertSame('#ffb547', $row['brand_accent_color']);
        self::assertSame('#6fddf0', $row['brand_primary_color_dark']);
        self::assertSame('#ffc870', $row['brand_accent_color_dark']);

        $get = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);
        self::assertSame('#ffb547', $get['accent_color']);
        self::assertSame('#6fddf0', $get['primary_color_dark']);
        self::assertSame('#ffc870', $get['accent_color_dark']);
        self::assertNull($get['logo_plate']);
        self::assertFalse($get['logo_ring_hidden']);
    }

    public function test_lite_plan_rejects_a_malformed_accent_colour(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('lite-accent-bad@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'accent_color' => 'red; background: url(x)',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('accent_color', $data['error']['fields'] ?? []);
        self::assertNull($this->brandRow($slug)['brand_accent_color']);
    }

    public function test_locked_accent_is_kept_when_the_form_resends_it_unchanged_and_cleared_when_emptied(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug = $this->seedBoard();
        $this->conn->executeStatement("UPDATE boards SET brand_accent_color = '#FFB547' WHERE slug = :s", ['s' => $slug]);
        $adminId = $this->seedUser('free-accent-kept@example.com', true);

        $kept = $this->createApp()->handle($this->postRequest($slug, $adminId, ['accent_color' => '#FFB547']));
        self::assertSame(200, $kept->getStatusCode());
        self::assertSame('#ffb547', $this->brandRow($slug)['brand_accent_color']);

        $cleared = $this->createApp()->handle($this->postRequest($slug, $adminId, ['accent_color' => '']));
        self::assertSame(200, $cleared->getStatusCode());
        self::assertNull($this->brandRow($slug)['brand_accent_color']);
    }

    public function test_free_plan_cannot_choose_a_logo_plate_or_hide_the_ring(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('free-plate@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'logo_plate'        => 'white',
            'logo_ring_hidden'  => '1',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('logo_plate', $data['error']['fields'] ?? []);
        $row = $this->brandRow($slug);
        self::assertNull($row['brand_logo_plate']);
        self::assertSame(0, (int) $row['brand_logo_ring_hidden']);
    }

    public function test_lite_plan_saves_logo_plate_and_ring_and_auto_clears_the_plate(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('lite-plate@example.com', true);

        $saved = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'logo_plate'       => 'white',
            'logo_ring_hidden' => '1',
        ]));
        self::assertSame(200, $saved->getStatusCode());
        $row = $this->brandRow($slug);
        self::assertSame('white', $row['brand_logo_plate']);
        self::assertSame(1, (int) $row['brand_logo_ring_hidden']);

        $auto = $this->createApp()->handle($this->postRequest($slug, $adminId, ['logo_plate' => 'auto']));
        self::assertSame(200, $auto->getStatusCode());
        self::assertNull($this->brandRow($slug)['brand_logo_plate']);
    }

    public function test_an_unknown_logo_plate_is_rejected(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('lite-plate-bad@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, ['logo_plate' => 'rainbow']));

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->brandRow($slug)['brand_logo_plate']);
    }

    public function test_another_account_cannot_set_the_accent_colour(): void
    {
        $otherAccount = $this->insertAccount(['slug' => 'accent-other', 'plan' => 'pro']);
        $this->conn->insert('boards', [
            'account_id' => $otherAccount,
            'slug'       => 'accent-foreign',
            'name'       => 'Foreign',
            'visibility' => 'public',
            'is_default' => 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $adminId = $this->seedUser('accent-cross@example.com', true);

        $response = $this->createApp()->handle($this->postRequest('accent-foreign', $adminId, [
            'accent_color' => '#FFB547',
            'logo_plate'   => 'white',
        ]));

        self::assertSame(404, $response->getStatusCode());
        $row = $this->brandRow('accent-foreign');
        self::assertNull($row['brand_accent_color']);
        self::assertNull($row['brand_logo_plate']);
    }
}
