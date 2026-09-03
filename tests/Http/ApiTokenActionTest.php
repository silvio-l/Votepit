<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the admin CRUD routes of the Agent API tokens:
 * GET/POST /admin/boards/{slug}/tokens,
 * POST /admin/boards/{slug}/tokens/{id}/revoke.
 *
 * AC coverage:
 *   - Owner AND moderator may read/create/revoke (accountAdmin); anon → 401.
 *   - The plaintext token appears ONLY in the create() response, list() never sees it.
 *   - Cross-tenant isolation: a board from a foreign account is unfindable → 404.
 */
final class ApiTokenActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function getTokens(string $slug, ?int $actingUserId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', "/admin/boards/{$slug}/tokens");

        if ($actingUserId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($actingUserId)]);
        }

        return $request;
    }

    private function postCreate(string $slug, string $label, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/boards/{$slug}/tokens")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'label' => $label]);
    }

    private function postRevoke(string $slug, int $tokenId, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/boards/{$slug}/tokens/{$tokenId}/revoke")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token]);
    }

    // -------------------------------------------------------------------------
    // GET — list
    // -------------------------------------------------------------------------

    public function test_owner_lists_tokens_without_leaking_hash(): void
    {
        $boardId = $this->insertBoard('tok-list');
        $ownerId = $this->insertUser('owner-tok-list@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, (new TokenVault())->hash('plain'), 'CI bot');

        $response = $this->createApp()->handle($this->getTokens('tok-list', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['tokens']);
        self::assertSame('CI bot', $data['tokens'][0]['label']);
        self::assertArrayNotHasKey('token', $data['tokens'][0]);
        self::assertArrayNotHasKey('token_hash', $data['tokens'][0]);
    }

    public function test_moderator_can_also_list_tokens(): void
    {
        $this->insertBoard('tok-list-mod');
        $modId = $this->insertUser('mod-tok-list@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->getTokens('tok-list-mod', $modId));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_anon_list_returns_401(): void
    {
        $this->insertBoard('tok-list-anon');

        $response = $this->createApp()->handle($this->getTokens('tok-list-anon', null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_unknown_board_returns_404(): void
    {
        $ownerId = $this->insertUser('owner-tok-404@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getTokens('does-not-exist', $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST — create
    // -------------------------------------------------------------------------

    public function test_owner_creates_token_and_receives_plaintext_once(): void
    {
        $this->insertBoard('tok-create');
        $ownerId = $this->insertUser('owner-tok-create@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreate('tok-create', 'My integration', $ownerId));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
        self::assertSame('My integration', $data['label']);
        self::assertIsString($data['token']);
        self::assertSame(64, strlen($data['token'])); // TokenVault: 32 bytes hex

        // After that the plaintext is no longer retrievable anywhere — only the hash is stored in the DB.
        $stored = $this->conn->fetchOne('SELECT token_hash FROM api_tokens WHERE id = :id', ['id' => $data['id']]);
        self::assertNotSame($data['token'], $stored);
        self::assertSame(hash('sha256', $data['token']), $stored);
    }

    public function test_moderator_can_also_create_token(): void
    {
        $this->insertBoard('tok-create-mod');
        $modId = $this->insertUser('mod-tok-create@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postCreate('tok-create-mod', 'Mod integration', $modId));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_empty_label_returns_422(): void
    {
        $this->insertBoard('tok-create-empty');
        $ownerId = $this->insertUser('owner-tok-empty@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreate('tok-create-empty', '', $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_anon_create_returns_401(): void
    {
        $this->insertBoard('tok-create-anon');

        $response = $this->createApp()->handle($this->postCreate('tok-create-anon', 'X', null));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST — revoke
    // -------------------------------------------------------------------------

    public function test_owner_revokes_token(): void
    {
        $boardId = $this->insertBoard('tok-revoke');
        $ownerId = $this->insertUser('owner-tok-revoke@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $tokenId = $this->insertApiToken($this->defaultAccountId(), $boardId, $ownerId, 'hash-revoke-admin');

        $response = $this->createApp()->handle($this->postRevoke('tok-revoke', $tokenId, $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->conn->fetchOne(
            'SELECT revoked_at FROM api_tokens WHERE id = :id',
            ['id' => $tokenId],
        ));
    }

    public function test_revoking_unknown_token_returns_404(): void
    {
        $this->insertBoard('tok-revoke-404');
        $ownerId = $this->insertUser('owner-tok-revoke-404@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRevoke('tok-revoke-404', 999999, $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Cross-Tenant-Isolation
    // -------------------------------------------------------------------------

    public function test_owner_cannot_see_or_revoke_tokens_of_foreign_account_board(): void
    {
        $accountB = $this->insertAccount(['slug' => 'acct-tok-foreign', 'name' => 'Foreign Account']);
        $boardB   = $this->insertBoard('board-foreign-tok', ['account_id' => $accountB]);
        $userB    = $this->insertUser('user-foreign-tok@example.com');
        $tokenB   = $this->insertApiToken($accountB, $boardB, $userB, 'hash-foreign-tok');

        $ownerA = $this->insertUser('owner-foreign-tok@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerA, 'owner');

        // Board B does not exist in Account A's slug space (self-host: default account) —
        // findBySlugForAccount() won't find it → 404, no leak.
        $response = $this->createApp()->handle($this->getTokens('board-foreign-tok', $ownerA));
        self::assertSame(404, $response->getStatusCode());

        $revokeResponse = $this->createApp()->handle($this->postRevoke('board-foreign-tok', $tokenB, $ownerA));
        self::assertSame(404, $revokeResponse->getStatusCode());
        self::assertNull($this->conn->fetchOne(
            'SELECT revoked_at FROM api_tokens WHERE id = :id',
            ['id' => $tokenB],
        ), 'Foreign token must remain untouched by account A.');
    }

    // =========================================================================
    // Tier enforcement: Agent API is Pro-only
    // =========================================================================

    public function test_free_plan_rejects_token_creation(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'starter');
        $ownerId = $this->insertUser('owner-free-token@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertBoard('free-token-board');

        $response = $this->createApp()->handle($this->postCreate('free-token-board', 'Free Token', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_agent_api', $data['error']['key'] ?? null);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM api_tokens');
        self::assertSame(0, $count);
    }

    public function test_lite_plan_rejects_token_creation(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'team');
        $ownerId = $this->insertUser('owner-lite-token@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertBoard('lite-token-board');

        $response = $this->createApp()->handle($this->postCreate('lite-token-board', 'Lite Token', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_agent_api', $data['error']['key'] ?? null);
    }

    public function test_pro_plan_allows_token_creation(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'business');
        $ownerId = $this->insertUser('owner-pro-token@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertBoard('pro-token-board');

        $response = $this->createApp()->handle($this->postCreate('pro-token-board', 'Pro Token', $ownerId));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_unknown_plan_rejects_token_creation(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'not-a-real-plan');
        $ownerId = $this->insertUser('owner-unknown-plan-token@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertBoard('unknown-plan-token-board');

        $response = $this->createApp()->handle($this->postCreate('unknown-plan-token-board', 'Unknown Plan Token', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_agent_api', $data['error']['key'] ?? null);
    }
}
