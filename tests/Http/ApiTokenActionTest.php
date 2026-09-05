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
 * GET/POST /admin/tokens, POST /admin/tokens/{id}/revoke.
 *
 * Account-scoped since migration 0044 — no board slug in the path, a
 * token's board grants are chosen in the create() request body
 * (`boards: [{slug, scope}, ...]`, one scope PER board since migration 0047).
 *
 * AC coverage:
 *   - Owner AND admin may read/create/revoke (accountAdmin); moderator/anon → 403/401.
 *   - The plaintext token appears ONLY in the create() response, list() never sees it.
 *   - Cross-tenant isolation: a token of a foreign account is unfindable → 404.
 */
final class ApiTokenActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function getTokens(?int $actingUserId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/tokens');

        if ($actingUserId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($actingUserId)]);
        }

        return $request;
    }

    /**
     * Builds the create() request body — every slug in $boardSlugs is
     * granted the SAME $scope. Use postCreateWithBoards() directly for a
     * request granting a DIFFERENT scope per board.
     *
     * @param list<string> $boardSlugs
     */
    private function postCreate(array $boardSlugs, string $label, ?int $actingUserId, string $scope = 'write'): ServerRequestInterface
    {
        $boards = array_map(static fn (string $slug): array => ['slug' => $slug, 'scope' => $scope], $boardSlugs);
        return $this->postCreateWithBoards($boards, $label, $actingUserId);
    }

    /** @param list<array{slug: string, scope: string}> $boards */
    private function postCreateWithBoards(array $boards, string $label, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/tokens')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'label' => $label, 'boards' => $boards]);
    }

    private function postRevoke(int $tokenId, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/admin/tokens/{$tokenId}/revoke")
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

        $response = $this->createApp()->handle($this->getTokens($ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['tokens']);
        self::assertSame('CI bot', $data['tokens'][0]['label']);
        self::assertSame([['board_id' => $boardId, 'scope' => 'write']], $data['tokens'][0]['boards']);
        self::assertArrayNotHasKey('token', $data['tokens'][0]);
        self::assertArrayNotHasKey('token_hash', $data['tokens'][0]);
    }

    public function test_admin_can_also_list_tokens(): void
    {
        $adminId = $this->insertUser('admin-tok-list@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');

        $response = $this->createApp()->handle($this->getTokens($adminId));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_moderator_cannot_list_tokens_returns_403(): void
    {
        $modId = $this->insertUser('mod-tok-list@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->getTokens($modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anon_list_returns_401(): void
    {
        $response = $this->createApp()->handle($this->getTokens(null));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST — create
    // -------------------------------------------------------------------------

    public function test_owner_creates_token_and_receives_plaintext_once(): void
    {
        $this->insertBoard('tok-create');
        $ownerId = $this->insertUser('owner-tok-create@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreate(['tok-create'], 'My integration', $ownerId));

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

    public function test_owner_creates_token_granting_multiple_boards(): void
    {
        $this->insertBoard('tok-multi-a');
        $this->insertBoard('tok-multi-b');
        $ownerId = $this->insertUser('owner-tok-multi@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle(
            $this->postCreate(['tok-multi-a', 'tok-multi-b'], 'Multi board', $ownerId, 'read'),
        );

        self::assertSame(201, $response->getStatusCode());
        $data     = json_decode((string) $response->getBody(), true);
        $boardIds = $this->conn->fetchFirstColumn(
            'SELECT board_id FROM api_token_boards WHERE token_id = :id ORDER BY board_id',
            ['id' => $data['id']],
        );
        self::assertCount(2, $boardIds);
    }

    public function test_owner_grants_a_different_scope_per_board_on_the_same_token(): void
    {
        $writeBoard = $this->insertBoard('tok-granular-write');
        $readBoard  = $this->insertBoard('tok-granular-read');
        $ownerId    = $this->insertUser('owner-tok-granular@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreateWithBoards(
            [
                ['slug' => 'tok-granular-write', 'scope' => 'write'],
                ['slug' => 'tok-granular-read', 'scope' => 'read'],
            ],
            'Mixed scopes',
            $ownerId,
        ));

        self::assertSame(201, $response->getStatusCode());
        $data   = json_decode((string) $response->getBody(), true);
        $scopes = [];
        foreach ($data['boards'] as $grant) {
            $scopes[$grant['board_id']] = $grant['scope'];
        }
        self::assertSame('write', $scopes[$writeBoard]);
        self::assertSame('read', $scopes[$readBoard]);

        $stored = $this->conn->fetchAllAssociative(
            'SELECT board_id, scope FROM api_token_boards WHERE token_id = :id',
            ['id' => $data['id']],
        );
        self::assertCount(2, $stored);
    }

    public function test_invalid_scope_for_one_board_rejects_the_whole_creation(): void
    {
        $this->insertBoard('tok-granular-badscope-a');
        $this->insertBoard('tok-granular-badscope-b');
        $ownerId = $this->insertUser('owner-tok-granular-badscope@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreateWithBoards(
            [
                ['slug' => 'tok-granular-badscope-a', 'scope' => 'write'],
                ['slug' => 'tok-granular-badscope-b', 'scope' => 'delete'],
            ],
            'Bad mixed scope',
            $ownerId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM api_tokens');
        self::assertSame(0, $count, 'A partially-invalid request must create nothing at all.');
    }

    public function test_admin_can_also_create_token(): void
    {
        $this->insertBoard('tok-create-admin');
        $adminId = $this->insertUser('admin-tok-create@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');

        $response = $this->createApp()->handle($this->postCreate(['tok-create-admin'], 'Admin integration', $adminId));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_moderator_cannot_create_token_returns_403(): void
    {
        $this->insertBoard('tok-create-mod');
        $modId = $this->insertUser('mod-tok-create@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postCreate(['tok-create-mod'], 'Mod integration', $modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_empty_label_returns_422(): void
    {
        $this->insertBoard('tok-create-empty');
        $ownerId = $this->insertUser('owner-tok-empty@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreate(['tok-create-empty'], '', $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_no_boards_selected_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-tok-noboard@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreate([], 'No boards', $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_invalid_scope_returns_422(): void
    {
        $this->insertBoard('tok-create-badscope');
        $ownerId = $this->insertUser('owner-tok-badscope@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreate(['tok-create-badscope'], 'Bad scope', $ownerId, 'delete'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_unknown_board_slug_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-tok-unknown-board@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postCreate(['does-not-exist'], 'X', $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_anon_create_returns_401(): void
    {
        $this->insertBoard('tok-create-anon');

        $response = $this->createApp()->handle($this->postCreate(['tok-create-anon'], 'X', null));

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

        $response = $this->createApp()->handle($this->postRevoke($tokenId, $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->conn->fetchOne(
            'SELECT revoked_at FROM api_tokens WHERE id = :id',
            ['id' => $tokenId],
        ));
    }

    public function test_revoking_unknown_token_returns_404(): void
    {
        $ownerId = $this->insertUser('owner-tok-revoke-404@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRevoke(999999, $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Cross-Tenant-Isolation
    // -------------------------------------------------------------------------

    public function test_owner_cannot_see_or_revoke_tokens_of_foreign_account(): void
    {
        $accountB = $this->insertAccount(['slug' => 'acct-tok-foreign', 'name' => 'Foreign Account']);
        $boardB   = $this->insertBoard('board-foreign-tok', ['account_id' => $accountB]);
        $userB    = $this->insertUser('user-foreign-tok@example.com');
        $tokenB   = $this->insertApiToken($accountB, $boardB, $userB, 'hash-foreign-tok');

        $ownerA = $this->insertUser('owner-foreign-tok@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerA, 'owner');

        $listResponse = $this->createApp()->handle($this->getTokens($ownerA));
        self::assertSame(200, $listResponse->getStatusCode());
        $data = json_decode((string) $listResponse->getBody(), true);
        self::assertSame([], $data['tokens']);

        $revokeResponse = $this->createApp()->handle($this->postRevoke($tokenB, $ownerA));
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

        $response = $this->createApp()->handle($this->postCreate(['free-token-board'], 'Free Token', $ownerId));

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

        $response = $this->createApp()->handle($this->postCreate(['lite-token-board'], 'Lite Token', $ownerId));

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

        $response = $this->createApp()->handle($this->postCreate(['pro-token-board'], 'Pro Token', $ownerId));

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_unknown_plan_rejects_token_creation(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'not-a-real-plan');
        $ownerId = $this->insertUser('owner-unknown-plan-token@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertBoard('unknown-plan-token-board');

        $response = $this->createApp()->handle($this->postCreate(['unknown-plan-token-board'], 'Unknown Plan Token', $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_agent_api', $data['error']['key'] ?? null);
    }
}
