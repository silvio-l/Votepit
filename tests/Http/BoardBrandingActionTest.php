<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

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
        $slug    = $this->seedBoard();
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
        $this->setAccountPlan($this->defaultAccountId(), 'team');
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
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
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
        $this->setAccountPlan($this->defaultAccountId(), 'team');
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
        $this->setAccountPlan($this->defaultAccountId(), 'business');
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
        $this->setAccountPlan($this->defaultAccountId(), 'team');
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
        $this->setAccountPlan($this->defaultAccountId(), 'team');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('lite-fields-get@example.com', true);

        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['secondary_color', 'logo_url', 'intro'], $data['allowed_branding_fields']);
    }

    public function test_free_plan_cannot_set_secondary_color(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
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
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
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
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
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
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
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
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
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
        $this->setAccountPlan($this->defaultAccountId(), 'team');
        $slug    = $this->seedBoard();
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
        $this->setAccountPlan($this->defaultAccountId(), 'business');
        $slug    = $this->seedBoard();
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
        $this->setAccountPlan($this->defaultAccountId(), 'business');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('xss-' . md5($payload) . '@example.com', true);

        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color'   => $payload,
            'secondary_color' => $payload,
            'logo_url'        => $payload,
            'intro'           => $payload,
        ]));

        // Field-level plan gating passes (pro allows everything) — the payload
        // itself must still never reach the DB unsanitized.
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertIsArray($row);
        self::assertNull($row['primary_color'], 'primary_color must reject the XSS payload to null');
        self::assertNull($row['secondary_color'], 'secondary_color must reject the XSS payload to null');
        self::assertNull($row['logo_url'], 'logo_url must reject the XSS payload to null');
        self::assertNull($row['intro'], 'intro must reject the XSS payload to null');
    }

    // -------------------------------------------------------------------------
    // Downgrade behaviour: stored over-plan value is frozen, not force-cleared.
    // -------------------------------------------------------------------------

    public function test_downgrade_freezes_stored_value_instead_of_force_clearing(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'business');
        $slug    = $this->seedBoard();
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
        $this->setAccountPlan($this->defaultAccountId(), 'starter');

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
        $this->setAccountPlan($this->defaultAccountId(), 'business');
        $slug    = $this->seedBoard();
        $adminId = $this->seedUser('downgrade-public-admin@example.com', true);

        $this->createApp()->handle($this->postRequest($slug, $adminId, [
            'primary_color' => '#123456',
            'intro'         => 'Welcome!',
            'hide_badge'    => '1',
        ]));

        $this->setAccountPlan($this->defaultAccountId(), 'starter');

        $publicResponse = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/' . $slug),
        );
        $data = json_decode((string) $publicResponse->getBody(), true);

        self::assertSame(200, $publicResponse->getStatusCode());
        self::assertSame('', $data['board']['intro'], 'downgraded plan must not publicly expose stale intro');
        self::assertTrue($data['board']['show_badge'], 'downgraded plan must not honour stale hide_badge');
    }
}
