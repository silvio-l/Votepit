<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /admin/boards/{slug}/block
 * and the account-wide enforcement in BlockCheckMiddleware.
 *
 * All assertions run exclusively through the HTTP seam (AppFactory::create),
 * the same pipeline as production.
 *
 * AC coverage:
 *   AC1: Owner/moderator blocks a user account-wide → 200, persisted.
 *   AC2: Blocked user gets 403 when creating/editing/withdrawing/voting,
 *        on EVERY board in the account.
 *   AC3: Reading (GET) stays allowed for blocked users.
 *   AC4: Lifting the block restores participation.
 *   AC5: Tenancy isolation — a block in account A does not affect the same
 *        user in account B.
 *   AC6: anon → 401, unauthorized → 403 on the block endpoint.
 *   AC7: Foreign board → 404, no side effect.
 *   AC8: Masked audit (no PII/plaintext email).
 *   AC9: The global users.is_blocked kill switch keeps working unchanged
 *        (regression), independently of the new mechanism.
 */
final class UserBlockActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * Generates a valid time-trap stamp (5 s in the past) —
     * identical logic to TimeTrapService, needed for idea create/edit.
     */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = str_repeat('a', 64);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    private function postBlock(
        string $slug,
        ?int $targetUserId,
        ?bool $blocked,
        ?int $actingUserId,
        ?string $scope = null,
    ): ServerRequestInterface {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $body = ['_csrf' => $token];
        if ($targetUserId !== null) {
            $body['user_id'] = $targetUserId;
        }
        if ($blocked !== null) {
            $body['blocked'] = $blocked;
        }
        if ($scope !== null) {
            $body['scope'] = $scope;
        }

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/block')
            ->withCookieParams($cookies)
            ->withParsedBody($body);
    }

    private function postIdea(string $boardSlug, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody([
                '_csrf'    => $token,
                '_form_at' => $this->validTimeTrap(),
                'title'    => 'A sufficiently long idea',
                'body'     => 'A sufficiently long description of the idea.',
            ]);
    }

    private function postEdit(string $boardSlug, int $ideaId, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas/' . $ideaId)
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody([
                '_csrf'    => $token,
                '_form_at' => $this->validTimeTrap(),
                'title'    => 'Updated title of the idea',
                'body'     => 'Updated description of the idea.',
            ]);
    }

    private function postWithdraw(string $boardSlug, int $ideaId, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas/' . $ideaId . '/withdraw')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(['_csrf' => $token]);
    }

    private function postVote(string $boardSlug, int $ideaId, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas/' . $ideaId . '/vote')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(['_csrf' => $token, 'value' => 'up']);
    }

    private function getBoardHome(string $boardSlug, ?int $userId = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/' . $boardSlug);

        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    private function isBlockedRow(int $accountId, int $userId): bool
    {
        $row = $this->conn->fetchOne(
            'SELECT 1 FROM blocked_users WHERE account_id = :a AND user_id = :u AND board_id IS NULL',
            ['a' => $accountId, 'u' => $userId],
        );

        return $row !== false;
    }

    private function isBoardBlockedRow(int $accountId, int $boardId, int $userId): bool
    {
        $row = $this->conn->fetchOne(
            'SELECT 1 FROM blocked_users WHERE account_id = :a AND user_id = :u AND board_id = :b',
            ['a' => $accountId, 'u' => $userId, 'b' => $boardId],
        );

        return $row !== false;
    }

    // -------------------------------------------------------------------------
    // AC1: Owner blocks a user account-wide → 200, persisted
    // -------------------------------------------------------------------------

    public function test_owner_blocks_user_account_wide_returns_200_and_persists(): void
    {
        $boardId  = $this->insertBoard('block-ok');
        $ownerId  = $this->insertUser('owner-block@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-block@example.com');
        $this->seedIdea($boardId, $targetId, 'Test idea');

        $response = $this->createApp()->handle($this->postBlock('block-ok', $targetId, true, $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertTrue($data['blocked'] ?? false);
        self::assertTrue($this->isBlockedRow($this->defaultAccountId(), $targetId));
    }

    // -------------------------------------------------------------------------
    // AC1: Admin blocks a user for a single board only; moderator cannot
    // (accountAdmin no longer includes moderator — restricted to comment/idea
    // moderation only).
    // -------------------------------------------------------------------------

    public function test_admin_blocks_user_for_single_board_returns_200_and_persists_board_scoped(): void
    {
        $boardId = $this->insertBoard('block-board-scope');
        $adminId = $this->insertUser('admin-board-scope@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');
        $targetId = $this->insertUser('target-board-scope@example.com');

        $response = $this->createApp()->handle(
            $this->postBlock('block-board-scope', $targetId, true, $adminId, 'board'),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertTrue($data['blocked'] ?? false);
        self::assertTrue($this->isBoardBlockedRow($this->defaultAccountId(), $boardId, $targetId));
        self::assertFalse($this->isBlockedRow($this->defaultAccountId(), $targetId), 'A board-scoped block must not create an account-wide row.');
    }

    public function test_moderator_cannot_block_user_returns_403(): void
    {
        $this->insertBoard('block-board-scope-mod');
        $modId = $this->insertUser('mod-board-scope@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');
        $targetId = $this->insertUser('target-board-scope-mod@example.com');

        $response = $this->createApp()->handle(
            $this->postBlock('block-board-scope-mod', $targetId, true, $modId, 'board'),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC2: blocked user → 403 on create/edit/withdraw/vote, on EVERY board
    // -------------------------------------------------------------------------

    public function test_blocked_user_gets_403_creating_idea_on_any_board_in_account(): void
    {
        $boardA  = $this->insertBoard('block-create-a');
        $boardB  = $this->insertBoard('block-create-b');
        $ownerId = $this->insertUser('owner-create@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-create@example.com');

        $app = $this->createApp();
        $app->handle($this->postBlock('block-create-a', $targetId, true, $ownerId));

        $responseA = $app->handle($this->postIdea('block-create-a', $targetId));
        $responseB = $app->handle($this->postIdea('block-create-b', $targetId));

        self::assertSame(403, $responseA->getStatusCode());
        self::assertSame(403, $responseB->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id IN (:a, :b)', ['a' => $boardA, 'b' => $boardB]));
    }

    public function test_blocked_user_gets_403_editing_own_idea(): void
    {
        $boardId = $this->insertBoard('block-edit');
        $ownerId = $this->insertUser('owner-edit@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-edit@example.com');
        $ideaId   = $this->seedIdea($boardId, $targetId, 'Original title');

        $app = $this->createApp();
        $app->handle($this->postBlock('block-edit', $targetId, true, $ownerId));

        $response = $app->handle($this->postEdit('block-edit', $ideaId, $targetId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Original title', $this->conn->fetchOne('SELECT title FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_blocked_user_gets_403_withdrawing_own_idea(): void
    {
        $boardId = $this->insertBoard('block-withdraw');
        $ownerId = $this->insertUser('owner-withdraw@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-withdraw@example.com');
        $ideaId   = $this->seedIdea($boardId, $targetId, 'Test idea');

        $app = $this->createApp();
        $app->handle($this->postBlock('block-withdraw', $targetId, true, $ownerId));

        $response = $app->handle($this->postWithdraw('block-withdraw', $ideaId, $targetId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_blocked_user_gets_403_voting(): void
    {
        $boardId = $this->insertBoard('block-vote');
        $ownerId = $this->insertUser('owner-vote@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-vote@example.com');
        $ideaId   = $this->seedIdea($boardId, $ownerId, 'Test idea');

        $app = $this->createApp();
        $app->handle($this->postBlock('block-vote', $targetId, true, $ownerId));

        $response = $app->handle($this->postVote('block-vote', $ideaId, $targetId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]));
    }

    // -------------------------------------------------------------------------
    // AC3: reading stays allowed for blocked users
    // -------------------------------------------------------------------------

    public function test_blocked_user_can_still_read_board_home(): void
    {
        $this->insertBoard('block-read');
        $ownerId = $this->insertUser('owner-read@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-read@example.com');

        $app = $this->createApp();
        $app->handle($this->postBlock('block-read', $targetId, true, $ownerId));

        $response = $app->handle($this->getBoardHome('block-read', $targetId));

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC4: unblock restores participation
    // -------------------------------------------------------------------------

    public function test_unblock_restores_ability_to_vote(): void
    {
        $boardId = $this->insertBoard('block-unblock');
        $ownerId = $this->insertUser('owner-unblock@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-unblock@example.com');
        $ideaId   = $this->seedIdea($boardId, $ownerId, 'Test idea');

        $app = $this->createApp();
        $app->handle($this->postBlock('block-unblock', $targetId, true, $ownerId));
        $app->handle($this->postBlock('block-unblock', $targetId, false, $ownerId));

        $response = $app->handle($this->postVote('block-unblock', $ideaId, $targetId));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->isBlockedRow($this->defaultAccountId(), $targetId));
    }

    // -------------------------------------------------------------------------
    // AC5: tenancy isolation — a block in account A doesn't affect account B
    // -------------------------------------------------------------------------

    public function test_block_in_account_a_does_not_affect_user_in_account_b(): void
    {
        // AccountContextMiddleware ALWAYS resolves to the default account
        // (self-host = exactly one account; the {account} path-segment resolution
        // for cloud is out of scope here) — a board in a foreign account is
        // therefore structurally unreachable via any HTTP request (see
        // CrossTenantAccountScopingTest). Tenancy isolation is therefore checked
        // at the level that actually enforces it: the account_id-scoped
        // query in BlockRepository::isBlocked().
        $accountA = $this->defaultAccountId();
        $accountB = $this->insertAccount(['slug' => 'acct-block-b', 'name' => 'Account B']);

        $this->insertBoard('block-tenancy-a', ['account_id' => $accountA]);

        $ownerA = $this->insertUser('owner-tenancy-a@example.com');
        $this->insertAccountMember($accountA, $ownerA, 'owner');

        $targetId = $this->insertUser('target-tenancy@example.com');

        $this->createApp()->handle($this->postBlock('block-tenancy-a', $targetId, true, $ownerA));

        self::assertTrue($this->isBlockedRow($accountA, $targetId));
        self::assertFalse($this->isBlockedRow($accountB, $targetId), 'A block in account A must not affect the same user in account B.');
    }

    // -------------------------------------------------------------------------
    // AC6: anon → 401, unauthorized → 403 on the block endpoint
    // -------------------------------------------------------------------------

    public function test_anon_block_request_returns_401(): void
    {
        $this->insertBoard('block-anon');
        $targetId = $this->insertUser('target-anon@example.com');

        $response = $this->createApp()->handle($this->postBlock('block-anon', $targetId, true, null));

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($this->isBlockedRow($this->defaultAccountId(), $targetId));
    }

    public function test_non_admin_block_request_returns_403(): void
    {
        $this->insertBoard('block-403');
        $userId   = $this->insertUser('user-403@example.com');
        $targetId = $this->insertUser('target-403@example.com');

        $response = $this->createApp()->handle($this->postBlock('block-403', $targetId, true, $userId));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->isBlockedRow($this->defaultAccountId(), $targetId));
    }

    // -------------------------------------------------------------------------
    // AC7: foreign board → 404, no side effect
    // -------------------------------------------------------------------------

    public function test_block_via_foreign_board_returns_404_and_no_row_created(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-block-foreign', 'name' => 'Foreign Account']);
        $this->insertBoard('block-foreign', ['account_id' => $foreignAccountId]);

        $ownerId = $this->insertUser('owner-foreign@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-foreign@example.com');

        $response = $this->createApp()->handle($this->postBlock('block-foreign', $targetId, true, $ownerId));

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($this->isBlockedRow($this->defaultAccountId(), $targetId));
        self::assertFalse($this->isBlockedRow($foreignAccountId, $targetId));
    }

    // -------------------------------------------------------------------------
    // Invalid target user → 404
    // -------------------------------------------------------------------------

    public function test_unknown_target_user_returns_404(): void
    {
        $this->insertBoard('block-unknown-user');
        $ownerId = $this->insertUser('owner-unknown@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postBlock('block-unknown-user', 999999, true, $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Invalid payload → 422
    // -------------------------------------------------------------------------

    public function test_missing_fields_returns_422(): void
    {
        $this->insertBoard('block-422');
        $ownerId = $this->insertUser('owner-422@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postBlock('block-422', null, null, $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Idempotent no-op — target state == current state
    // -------------------------------------------------------------------------

    public function test_blocking_already_blocked_user_is_noop_and_writes_no_second_audit_entry(): void
    {
        $this->insertBoard('block-noop');
        $ownerId = $this->insertUser('owner-noop@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-noop@example.com');

        $app = $this->createApp();
        $app->handle($this->postBlock('block-noop', $targetId, true, $ownerId));

        $log = $this->readAuditLog();
        $firstCount = substr_count($log, 'user.block.changed');
        self::assertSame(1, $firstCount);

        $response = $app->handle($this->postBlock('block-noop', $targetId, true, $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $secondLog = $this->readAuditLog();
        self::assertSame(1, substr_count($secondLog, 'user.block.changed'), 'A no-op must not create a second audit entry.');
    }

    // -------------------------------------------------------------------------
    // AC8: masked audit log, no PII
    // -------------------------------------------------------------------------

    public function test_audit_log_contains_user_block_changed_event_masked_no_pii(): void
    {
        $this->insertBoard('block-audit');
        $ownerId = $this->insertUser('owner-audit@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-audit-secret@example.com');

        $this->createApp()->handle($this->postBlock('block-audit', $targetId, true, $ownerId));

        $log = $this->readAuditLog();
        self::assertStringContainsString('user.block.changed', $log);
        self::assertStringContainsString('"blocked":true', $log);
        self::assertStringContainsString('"target_user_id":' . $targetId, $log);
        self::assertStringNotContainsString('target-audit-secret@example.com', $log, 'Email must not appear in the log.');
    }

    // -------------------------------------------------------------------------
    // AC9 (regression): global users.is_blocked kill switch stays independent
    // -------------------------------------------------------------------------

    public function test_global_kill_switch_still_blocks_independently_of_account_block(): void
    {
        $boardId = $this->insertBoard('block-global-regression');
        $globallyBlockedId = $this->insertUser('globally-blocked@example.com', ['is_blocked' => 1]);
        $ideaId = $this->seedIdea($boardId, $globallyBlockedId, 'Test idea');

        // No entry in blocked_users — only the global kill switch applies.
        self::assertFalse($this->isBlockedRow($this->defaultAccountId(), $globallyBlockedId));

        $response = $this->createApp()->handle($this->postVote('block-global-regression', $ideaId, $globallyBlockedId));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // JSON response format
    // -------------------------------------------------------------------------

    public function test_response_is_json_with_correct_content_type(): void
    {
        $this->insertBoard('block-ct');
        $ownerId = $this->insertUser('owner-ct@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-ct@example.com');

        $response = $this->createApp()->handle($this->postBlock('block-ct', $targetId, true, $ownerId));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('ok', $data);
        self::assertArrayHasKey('blocked', $data);
    }

    // -------------------------------------------------------------------------
    // Board-scoped enforcement — create/edit/withdraw/vote
    // 403 on the blocked board, second board in the same account unaffected.
    // -------------------------------------------------------------------------

    public function test_board_blocked_user_gets_403_creating_idea_on_blocked_board_but_not_on_second_board(): void
    {
        $boardA  = $this->insertBoard('board-scope-create-a');
        $boardB  = $this->insertBoard('board-scope-create-b');
        $ownerId = $this->insertUser('owner-scope-create@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-scope-create@example.com');

        $app = $this->createApp();
        $app->handle($this->postBlock('board-scope-create-a', $targetId, true, $ownerId, 'board'));

        $responseA = $app->handle($this->postIdea('board-scope-create-a', $targetId));
        $responseB = $app->handle($this->postIdea('board-scope-create-b', $targetId));

        self::assertSame(403, $responseA->getStatusCode());
        self::assertSame(201, $responseB->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :b', ['b' => $boardA]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :b', ['b' => $boardB]));
    }

    public function test_board_blocked_user_gets_403_editing_own_idea_on_blocked_board(): void
    {
        $boardId = $this->insertBoard('board-scope-edit');
        $ownerId = $this->insertUser('owner-scope-edit@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-scope-edit@example.com');
        $ideaId   = $this->seedIdea($boardId, $targetId, 'Original title');

        $app = $this->createApp();
        $app->handle($this->postBlock('board-scope-edit', $targetId, true, $ownerId, 'board'));

        $response = $app->handle($this->postEdit('board-scope-edit', $ideaId, $targetId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Original title', $this->conn->fetchOne('SELECT title FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_board_blocked_user_gets_403_withdrawing_own_idea_on_blocked_board(): void
    {
        $boardId = $this->insertBoard('board-scope-withdraw');
        $ownerId = $this->insertUser('owner-scope-withdraw@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-scope-withdraw@example.com');
        $ideaId   = $this->seedIdea($boardId, $targetId, 'Test idea');

        $app = $this->createApp();
        $app->handle($this->postBlock('board-scope-withdraw', $targetId, true, $ownerId, 'board'));

        $response = $app->handle($this->postWithdraw('board-scope-withdraw', $ideaId, $targetId));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE id = :id', ['id' => $ideaId]));
    }

    public function test_board_blocked_user_gets_403_voting_on_blocked_board_but_can_vote_on_second_board(): void
    {
        $boardA  = $this->insertBoard('board-scope-vote-a');
        $boardB  = $this->insertBoard('board-scope-vote-b');
        $ownerId = $this->insertUser('owner-scope-vote@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-scope-vote@example.com');
        $ideaA    = $this->seedIdea($boardA, $ownerId, 'Test idea A');
        $ideaB    = $this->seedIdea($boardB, $ownerId, 'Test idea B');

        $app = $this->createApp();
        $app->handle($this->postBlock('board-scope-vote-a', $targetId, true, $ownerId, 'board'));

        $responseA = $app->handle($this->postVote('board-scope-vote-a', $ideaA, $targetId));
        $responseB = $app->handle($this->postVote('board-scope-vote-b', $ideaB, $targetId));

        self::assertSame(403, $responseA->getStatusCode());
        self::assertSame(200, $responseB->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaA]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaB]));
    }

    public function test_board_blocked_user_can_still_read_the_blocked_board(): void
    {
        $this->insertBoard('board-scope-read');
        $ownerId = $this->insertUser('owner-scope-read@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-scope-read@example.com');

        $app = $this->createApp();
        $app->handle($this->postBlock('board-scope-read', $targetId, true, $ownerId, 'board'));

        $response = $app->handle($this->getBoardHome('board-scope-read', $targetId));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_unblocking_board_scoped_block_restores_voting_on_that_board_without_touching_others(): void
    {
        $boardId = $this->insertBoard('board-scope-unblock');
        $ownerId = $this->insertUser('owner-scope-unblock@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-scope-unblock@example.com');
        $ideaId   = $this->seedIdea($boardId, $ownerId, 'Test idea');

        $app = $this->createApp();
        $app->handle($this->postBlock('board-scope-unblock', $targetId, true, $ownerId, 'board'));
        $app->handle($this->postBlock('board-scope-unblock', $targetId, false, $ownerId, 'board'));

        $response = $app->handle($this->postVote('board-scope-unblock', $ideaId, $targetId));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->isBoardBlockedRow($this->defaultAccountId(), $boardId, $targetId));
    }

    public function test_account_wide_block_still_applies_to_second_board_alongside_board_scoped_check(): void
    {
        $this->insertBoard('board-scope-regression-a');
        $boardB  = $this->insertBoard('board-scope-regression-b');
        $ownerId = $this->insertUser('owner-scope-regression@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $targetId = $this->insertUser('target-scope-regression@example.com');
        $ideaB    = $this->seedIdea($boardB, $ownerId, 'Test idea B');

        $app = $this->createApp();
        // Account-wide block — no scope field sent, defaults to 'account'.
        $app->handle($this->postBlock('board-scope-regression-a', $targetId, true, $ownerId));

        $response = $app->handle($this->postVote('board-scope-regression-b', $ideaB, $targetId));

        self::assertSame(403, $response->getStatusCode(), 'The account-wide block must keep working additively.');
    }
}
