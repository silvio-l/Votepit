<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET/POST /signup/account (cloud signup onboarding).
 *
 * Boots via AppFactory with routing_mode='cloud' (SQLite in-memory).
 *
 * ACs:
 *  AC1 — full happy path: email → magic link → verify → account name/slug +
 *        first board → 201, account_members owner row, plan='starter',
 *        confirmed_at set, board immediately publicly visible.
 *  AC2 — reserved account slug → 422 fields.account_slug.
 *  AC3 — one account per signup: user with an existing membership → 409.
 *  AC4 — GET /signup/account reports has_account correctly (false/true).
 *  AC5 — anon → 401 (no login).
 *  AC6 — an unconfirmed account/board is not visible via the public read
 *        paths (board home, roadmap) — becomes visible after confirmation.
 *  AC7 — cross-tenant: the new account never sees/touches foreign accounts.
 */
final class SignupAccountActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function cloudConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => self::APP_KEY,
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'routing_mode'        => 'cloud',
        ]);
    }

    /** @return \Slim\App<null> */
    private function cloudApp(InMemoryMailer $mailer): \Slim\App
    {
        return AppFactory::create($this->cloudConfig(), $this->conn, $mailer, new AuditLogger($this->logFile), planPolicy: self::syntheticPlanPolicy());
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    private function cookieValue(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                return substr(explode(';', $header, 2)[0], strlen($name) + 1);
            }
        }
        return null;
    }

    /**
     * Logs in a new/unknown email via the magic-link flow; returns the signed session cookie.
     *
     * @param \Slim\App<null> $app
     */
    private function loginViaMagicLink(\Slim\App $app, InMemoryMailer $mailer, string $email): string
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $loginResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/login')
                ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
                ->withParsedBody(['email' => $email, 'r' => '/signup/account', '_csrf' => $token]),
        );
        self::assertSame(200, $loginResponse->getStatusCode());

        $sent = $mailer->lastSent();
        self::assertIsArray($sent);
        self::assertMatchesRegularExpression('/token=([a-f0-9]+)/', $sent['body'], $sent['body']);
        $matched = preg_match('/token=([a-f0-9]+)/', $sent['body'], $m);
        self::assertSame(1, $matched);
        $plainToken = $m[1];

        $verifyResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/login/verify')
                ->withQueryParams(['token' => $plainToken, 'r' => '/signup/account']),
        );
        self::assertSame(200, $verifyResponse->getStatusCode());
        $verifyData = json_decode((string) $verifyResponse->getBody(), true);
        self::assertSame('/signup/account', $verifyData['redirect'] ?? null);

        $sessCookie = $this->cookieValue($verifyResponse, 'votepit_sess');
        self::assertNotNull($sessCookie);

        return $sessCookie;
    }

    /** @param \Slim\App<null> $app */
    private function getSignupStatus(\Slim\App $app, string $sessCookie): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/signup/account')
                ->withCookieParams(['votepit_sess' => $sessCookie]),
        );
    }

    /**
     * @param \Slim\App<null> $app
     * @param array<string, string> $fields
     */
    private function postSignupAccount(\Slim\App $app, string $sessCookie, array $fields): ResponseInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/signup/account')
                ->withCookieParams([
                    'votepit_sess' => $sessCookie,
                    $csrf->cookieName() => $csrf->sign($csrfToken),
                ])
                ->withParsedBody(array_merge($fields, ['_csrf' => $csrfToken])),
        );
    }

    // -------------------------------------------------------------------------
    // AC1 — full happy path
    // -------------------------------------------------------------------------

    public function test_full_signup_flow_creates_confirmed_account_owner_and_public_board(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->cloudApp($mailer);
        $sessCookie = $this->loginViaMagicLink($app, $mailer, 'founder@example.com');

        $statusBefore = $this->getSignupStatus($app, $sessCookie);
        self::assertSame(200, $statusBefore->getStatusCode());
        $statusData = json_decode((string) $statusBefore->getBody(), true);
        self::assertFalse($statusData['has_account'] ?? true);

        $response = $this->postSignupAccount($app, $sessCookie, [
            'account_name' => 'Acme Inc',
            'account_slug' => 'acme',
            'board_name'   => 'Feedback',
            'board_slug'   => 'feedback',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertSame('acme', $data['account_slug'] ?? null);
        self::assertSame('feedback', $data['board_slug'] ?? null);

        $account = $this->conn->fetchAssociative('SELECT * FROM accounts WHERE slug = :slug', ['slug' => 'acme']);
        self::assertIsArray($account);
        self::assertSame('starter', $account['plan']);
        self::assertNotNull($account['confirmed_at']);

        $userId = (int) $this->conn->fetchOne('SELECT id FROM users WHERE email_hmac IS NOT NULL ORDER BY id DESC LIMIT 1');
        $role   = $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :aid AND user_id = :uid',
            ['aid' => $account['id'], 'uid' => $userId],
        );
        self::assertSame('owner', $role);

        $board = $this->conn->fetchAssociative(
            'SELECT * FROM boards WHERE slug = :slug AND account_id = :aid',
            ['slug' => 'feedback', 'aid' => $account['id']],
        );
        self::assertIsArray($board);

        // Immediately publicly visible (confirmed_at was set on creation).
        $publicResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/acme/feedback'),
        );
        self::assertSame(200, $publicResponse->getStatusCode());

        // GET /signup/account now reports has_account = true.
        $statusAfter = $this->getSignupStatus($app, $sessCookie);
        $statusAfterData = json_decode((string) $statusAfter->getBody(), true);
        self::assertTrue($statusAfterData['has_account'] ?? false);
    }

    // -------------------------------------------------------------------------
    // AC2 — reserved account slug
    // -------------------------------------------------------------------------

    public function test_reserved_account_slug_is_rejected(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->cloudApp($mailer);
        $sessCookie = $this->loginViaMagicLink($app, $mailer, 'reserved@example.com');

        $response = $this->postSignupAccount($app, $sessCookie, [
            'account_name' => 'Admin Corp',
            'account_slug' => 'admin',
            'board_name'   => 'Feedback',
            'board_slug'   => 'feedback',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('account_slug', $data['error']['fields'] ?? []);

        self::assertFalse($this->conn->fetchOne('SELECT id FROM accounts WHERE slug = :slug', ['slug' => 'admin']));
    }

    // -------------------------------------------------------------------------
    // AC3 — one account per signup
    // -------------------------------------------------------------------------

    public function test_user_who_already_belongs_to_an_account_cannot_create_a_second_one(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->cloudApp($mailer);
        $sessCookie = $this->loginViaMagicLink($app, $mailer, 'already@example.com');

        $first = $this->postSignupAccount($app, $sessCookie, [
            'account_name' => 'First Inc',
            'account_slug' => 'first-inc',
            'board_name'   => 'Feedback',
            'board_slug'   => 'feedback',
        ]);
        self::assertSame(201, $first->getStatusCode());

        $second = $this->postSignupAccount($app, $sessCookie, [
            'account_name' => 'Second Inc',
            'account_slug' => 'second-inc',
            'board_name'   => 'Feedback',
            'board_slug'   => 'feedback',
        ]);

        self::assertSame(409, $second->getStatusCode());
        $data = json_decode((string) $second->getBody(), true);
        self::assertSame('already_has_account', $data['error']['key'] ?? null);

        self::assertFalse($this->conn->fetchOne('SELECT id FROM accounts WHERE slug = :slug', ['slug' => 'second-inc']));
    }

    /**
     * Deliberately opposite of the AC3 test above: a user who is a plain
     * team member (not owner) of someone ELSE's account is NOT blocked from
     * starting their own paid account — only already OWNING one blocks a
     * signup (AccountMemberRepository::hasOwnAccount()).
     */
    public function test_a_team_member_of_a_foreign_account_can_still_create_their_own(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->cloudApp($mailer);
        $sessCookie = $this->loginViaMagicLink($app, $mailer, 'team-member@example.com');

        $userId = (int) $this->conn->fetchOne(
            'SELECT id FROM users WHERE email_hmac = :hmac',
            ['hmac' => (new \Votepit\Security\IdentityHasher(self::identityServerKey()))->hash('team-member@example.com')],
        );
        $foreignAccountId = $this->insertAccount();
        $this->insertAccountMember($foreignAccountId, $userId, 'moderator');

        $response = $this->postSignupAccount($app, $sessCookie, [
            'account_name' => 'My Own Inc',
            'account_slug' => 'my-own-inc',
            'board_name'   => 'Feedback',
            'board_slug'   => 'feedback',
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertNotFalse($this->conn->fetchOne('SELECT id FROM accounts WHERE slug = :slug', ['slug' => 'my-own-inc']));

        $ownRole = $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE user_id = :uid AND account_id != :foreign_id',
            ['uid' => $userId, 'foreign_id' => $foreignAccountId],
        );
        self::assertSame('owner', $ownRole);
    }

    // -------------------------------------------------------------------------
    // AC5 — anon
    // -------------------------------------------------------------------------

    public function test_anon_is_rejected_with_401(): void
    {
        $app = $this->cloudApp(new InMemoryMailer());

        $getResponse = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/signup/account'));
        self::assertSame(401, $getResponse->getStatusCode());

        // Include a CSRF token, so the 401 really comes from AuthZ::user()
        // (anon) — not from the global CsrfMiddleware.
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $postResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/signup/account')
                ->withCookieParams([$csrf->cookieName() => $csrf->sign($csrfToken)])
                ->withParsedBody([
                    'account_name' => 'x',
                    'account_slug' => 'x',
                    'board_name'   => 'x',
                    'board_slug'   => 'x',
                    '_csrf'        => $csrfToken,
                ]),
        );
        self::assertSame(401, $postResponse->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC6 — confirm-before-public: unconfirmed board invisible, then visible
    // -------------------------------------------------------------------------

    public function test_unconfirmed_account_board_is_not_publicly_visible_until_confirmed(): void
    {
        $accountId = $this->insertAccount(['slug' => 'pending-co', 'confirmed_at' => null]);
        $this->insertBoard('roadmap', ['account_id' => $accountId, 'name' => 'Roadmap']);

        $app = $this->cloudApp(new InMemoryMailer());

        $home = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/pending-co/roadmap'));
        self::assertSame(404, $home->getStatusCode());

        $roadmap = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/pending-co/roadmap/roadmap'));
        self::assertSame(404, $roadmap->getStatusCode());

        // Confirm ...
        $this->conn->executeStatement(
            'UPDATE accounts SET confirmed_at = :now WHERE id = :id',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $accountId],
        );

        $homeAfter = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/pending-co/roadmap'));
        self::assertSame(200, $homeAfter->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC7 — cross-tenant isolation
    // -------------------------------------------------------------------------

    public function test_new_signup_never_touches_a_foreign_account(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'foreign-co']);
        $this->insertBoard('secret-board', ['account_id' => $foreignAccountId, 'name' => 'Secret']);

        $mailer     = new InMemoryMailer();
        $app        = $this->cloudApp($mailer);
        $sessCookie = $this->loginViaMagicLink($app, $mailer, 'newcomer@example.com');

        $response = $this->postSignupAccount($app, $sessCookie, [
            'account_name' => 'Newcomer Inc',
            'account_slug' => 'newcomer-inc',
            'board_name'   => 'Feedback',
            'board_slug'   => 'feedback',
        ]);
        self::assertSame(201, $response->getStatusCode());

        $foreignMembership = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM account_members WHERE account_id = :aid',
            ['aid' => $foreignAccountId],
        );
        self::assertSame(0, (int) $foreignMembership);

        $foreignBoardsUntouched = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM boards WHERE account_id = :aid',
            ['aid' => $foreignAccountId],
        );
        self::assertSame(1, (int) $foreignBoardsUntouched);
    }
}
