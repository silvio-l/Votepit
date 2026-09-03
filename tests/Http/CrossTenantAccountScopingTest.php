<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Cross-tenant leak tests (account scoping in the repository layer).
 *
 * Mandatory acceptance criterion of account separation:
 * a board must STRUCTURALLY NEVER be reachable via a foreign account.
 *
 * Context note: AccountContextMiddleware currently ALWAYS resolves to the
 * default account (self-host = exactly one account; the {account} path-segment
 * resolution for cloud is NOT part of this — only the ATTR_ACCOUNT_ID contract
 * stands). "Cross-tenant" therefore means here: a board belonging to an OTHER
 * (non-default) account is reachable via NO HTTP request — not even for a user
 * who is owner/moderator in the default account. BoardRepository::findBySlugForAccount()
 * is the only chokepoint that enforces this.
 */
final class CrossTenantAccountScopingTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function getRequest(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }

        return $request;
    }

    private function seedUser(string $email): int
    {
        return $this->insertUser($email);
    }

    // -------------------------------------------------------------------------
    // 1. Board in a foreign (non-default) account, owner of the default account
    //    → 404 (not 403 — the board structurally does not exist in the
    //    resolved account context).
    // -------------------------------------------------------------------------

    public function test_board_in_foreign_account_returns_404_for_default_account_owner(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign', 'name' => 'Foreign Account']);
        $this->insertBoard('secret-branding', ['account_id' => $foreignAccountId]);

        $ownerId = $this->seedUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle(
            $this->getRequest('/admin/boards/secret-branding/branding', $ownerId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // 2. Two boards with the same slug in two different accounts → only the
    //    board in the (resolved) default account is reachable, the board in
    //    the foreign account remains invisible under the same slug.
    // -------------------------------------------------------------------------

    public function test_same_slug_across_two_accounts_resolves_only_the_default_account_board(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-b', 'name' => 'Account B']);
        $this->insertBoard('shared-slug', [
            'account_id' => $foreignAccountId,
            'name'       => 'Foreign Board',
        ]);
        $this->insertBoard('shared-slug', [
            'account_id' => $this->defaultAccountId(),
            'name'       => 'Default-Account Board',
        ]);

        $ownerId = $this->seedUser('owner-shared@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle(
            $this->getRequest('/admin/boards/shared-slug/branding', $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Default-Account Board', $data['board_name'] ?? null);
    }

    // -------------------------------------------------------------------------
    // 3. Moderator (not owner) of the default account → also 404 on a board
    //    that belongs to a foreign account (no owner-only exception).
    // -------------------------------------------------------------------------

    public function test_moderator_role_also_gets_404_for_board_in_foreign_account(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-mod', 'name' => 'Foreign Account']);
        $this->insertBoard('mod-secret', ['account_id' => $foreignAccountId]);

        $moderatorId = $this->seedUser('moderator@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');

        $response = $this->createApp()->handle(
            $this->getRequest('/admin/boards/mod-secret/branding', $moderatorId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // 4. Positive control: owner of the correct (default) account → 200 on
    //    their own board.
    // -------------------------------------------------------------------------

    public function test_owner_of_correct_account_gets_200_for_own_board(): void
    {
        $this->insertBoard('own-board');

        $ownerId = $this->seedUser('rightful-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle(
            $this->getRequest('/admin/boards/own-board/branding', $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('own-board', $data['board_slug'] ?? null);
    }

    // -------------------------------------------------------------------------
    // 5. Self-host bootstrap continuity: an admin_emails match at /login/verify
    //    creates account_members(role='owner') for the default account —
    //    otherwise an existing operator would lose access to /admin/boards/*
    //    after the upgrade. Runs through the real HTTP login flow, not
    //    through direct DB manipulation.
    // -------------------------------------------------------------------------

    public function test_admin_email_bootstrap_grants_board_admin_access_via_login(): void
    {
        $email  = 'bootstrap-admin@example.com';
        $plain  = bin2hex(random_bytes(32));
        $userId = $this->insertUser($email, ['verified_at' => null]);

        $this->conn->insert('login_tokens', [
            'user_id'    => $userId,
            'token_hash' => (new TokenVault())->hash($plain),
            'purpose'    => 'login',
            'expires_at' => (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->insertBoard('bootstrap-board');

        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => self::APP_KEY,
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'admin_emails'        => [$email],
        ]);
        $app = AppFactory::create($config, $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $verifyResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/login/verify')
                ->withQueryParams(['token' => $plain]),
        );
        self::assertSame(200, $verifyResponse->getStatusCode());

        // Promotion to platform admin AND account owner.
        $isAdmin = (int) $this->conn->fetchOne('SELECT is_admin FROM users WHERE id = :id', ['id' => $userId]);
        self::assertSame(1, $isAdmin);
        $role = $this->conn->fetchOne(
            'SELECT role FROM account_members WHERE account_id = :a AND user_id = :u',
            ['a' => $this->defaultAccountId(), 'u' => $userId],
        );
        self::assertSame('owner', $role);

        // After this, this user has access to /admin/boards/{slug}/branding in the default account.
        $sessCookie = null;
        foreach ($verifyResponse->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, 'votepit_sess=')) {
                $sessCookie = explode(';', $header, 2)[0];
                $sessCookie = substr($sessCookie, strlen('votepit_sess='));
            }
        }
        self::assertNotNull($sessCookie);

        $brandingResponse = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/boards/bootstrap-board/branding')
                ->withCookieParams(['votepit_sess' => $sessCookie]),
        );
        self::assertSame(200, $brandingResponse->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Extra: a user with no account membership at all (neither owner nor
    // moderator, regardless of account) → 403, not 404 — confirms that the
    // AuthZ check itself (not just the board lookup) takes effect.
    // -------------------------------------------------------------------------

    public function test_user_without_any_account_membership_gets_403_on_own_default_account_board(): void
    {
        $this->insertBoard('members-only');
        $userId = $this->seedUser('no-membership@example.com');

        $response = $this->createApp()->handle(
            $this->getRequest('/admin/boards/members-only/branding', $userId),
        );

        self::assertSame(403, $response->getStatusCode());
    }
}
