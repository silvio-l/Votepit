<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\ApiTokenRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence tests for ApiTokenRepository (Agent API).
 * Covers account scoping (no cross-tenant leak), multi-board grants
 * (api_token_boards), hash-only storage (never token_hash in
 * listForAccount()/findForAccount()), and the revoke/find-by-hash lifecycle.
 */
final class ApiTokenRepositoryTest extends IntegrationTestCase
{
    private function repo(): ApiTokenRepository
    {
        return new ApiTokenRepository($this->conn);
    }

    public function test_create_then_find_by_hash_returns_row_with_board_scopes(): void
    {
        $boardId = $this->insertBoard('board-a');
        $userId  = $this->insertUser('owner-a@example.com');
        $repo    = $this->repo();

        $tokenId = $repo->create($this->defaultAccountId(), [$boardId => 'write'], $userId, 'CI bot', 'hash-abc');

        $row = $repo->findByHash('hash-abc');
        self::assertIsArray($row);
        self::assertSame($tokenId, $row['id']);
        self::assertSame([$boardId => 'write'], $row['board_scopes']);
        self::assertSame($this->defaultAccountId(), $row['account_id']);
        self::assertSame($userId, $row['created_by_user_id']);
        self::assertNull($row['last_used_at']);
    }

    public function test_create_grants_multiple_boards(): void
    {
        $boardA = $this->insertBoard('board-multi-a');
        $boardB = $this->insertBoard('board-multi-b');
        $userId = $this->insertUser('owner-multi@example.com');
        $repo   = $this->repo();

        $repo->create($this->defaultAccountId(), [$boardA => 'read', $boardB => 'read'], $userId, 'Multi', 'hash-multi');

        $row = $repo->findByHash('hash-multi');
        self::assertIsArray($row);
        self::assertSame([$boardA => 'read', $boardB => 'read'], $row['board_scopes']);
    }

    public function test_create_grants_a_different_scope_per_board(): void
    {
        $writeBoard = $this->insertBoard('board-mixed-write');
        $readBoard  = $this->insertBoard('board-mixed-read');
        $userId     = $this->insertUser('owner-mixed@example.com');
        $repo       = $this->repo();

        $repo->create(
            $this->defaultAccountId(),
            [$writeBoard => 'write', $readBoard => 'read'],
            $userId,
            'Mixed',
            'hash-mixed',
        );

        $row = $repo->findByHash('hash-mixed');
        self::assertIsArray($row);
        self::assertSame('write', $row['board_scopes'][$writeBoard]);
        self::assertSame('read', $row['board_scopes'][$readBoard]);
    }

    public function test_find_by_hash_touches_last_used_at(): void
    {
        $boardId = $this->insertBoard('board-touch');
        $userId  = $this->insertUser('owner-touch@example.com');
        $repo    = $this->repo();
        $repo->create($this->defaultAccountId(), [$boardId => 'write'], $userId, 'CI bot', 'hash-touch');

        $before = $this->conn->fetchOne('SELECT last_used_at FROM api_tokens WHERE token_hash = :h', ['h' => 'hash-touch']);
        self::assertNull($before);

        $repo->findByHash('hash-touch');

        $after = $this->conn->fetchOne('SELECT last_used_at FROM api_tokens WHERE token_hash = :h', ['h' => 'hash-touch']);
        self::assertNotNull($after);
    }

    public function test_find_by_hash_returns_null_for_unknown_hash(): void
    {
        self::assertNull($this->repo()->findByHash('nope'));
    }

    public function test_find_by_hash_returns_null_for_revoked_token(): void
    {
        $boardId = $this->insertBoard('board-revoked');
        $userId  = $this->insertUser('owner-revoked@example.com');
        $tokenId = $this->insertApiToken($this->defaultAccountId(), $boardId, $userId, 'hash-revoked', overrides: [
            'revoked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        self::assertNull($this->repo()->findByHash('hash-revoked'));
        self::assertGreaterThan(0, $tokenId);
    }

    public function test_list_for_account_never_leaks_hash_and_is_account_scoped(): void
    {
        $boardA        = $this->insertBoard('board-list-a');
        $boardB        = $this->insertBoard('board-list-b');
        $userId        = $this->insertUser('owner-list@example.com');
        $repo          = $this->repo();
        $foreignAccount = $this->insertAccount();

        $repo->create($this->defaultAccountId(), [$boardA => 'write'], $userId, 'Token A', 'hash-a');
        $repo->create($foreignAccount, [$boardB => 'write'], $userId, 'Token B', 'hash-b');

        $rows = $repo->listForAccount($this->defaultAccountId());

        self::assertCount(1, $rows);
        self::assertSame('Token A', $rows[0]['label']);
        self::assertSame([$boardA => 'write'], $rows[0]['board_scopes']);
        self::assertArrayNotHasKey('token_hash', $rows[0]);
    }

    public function test_revoke_is_account_scoped_and_idempotent(): void
    {
        $boardA         = $this->insertBoard('board-revoke-a');
        $userId         = $this->insertUser('owner-revoke@example.com');
        $repo           = $this->repo();
        $foreignAccount = $this->insertAccount();

        $tokenId = $repo->create($this->defaultAccountId(), [$boardA => 'write'], $userId, 'To revoke', 'hash-revoke');

        // A foreign account must not be able to revoke (cross-tenant leak structurally excluded).
        self::assertFalse($repo->revoke($foreignAccount, $tokenId));
        self::assertNotNull($repo->findByHash('hash-revoke'));

        self::assertTrue($repo->revoke($this->defaultAccountId(), $tokenId));
        self::assertNull($repo->findByHash('hash-revoke'));

        // Revoking again is idempotent (no error, but no more "changed row").
        self::assertFalse($repo->revoke($this->defaultAccountId(), $tokenId));
    }

    public function test_find_for_account_sees_revoked_tokens_too(): void
    {
        $boardId = $this->insertBoard('board-find-for');
        $userId  = $this->insertUser('owner-find-for@example.com');
        $repo    = $this->repo();
        $tokenId = $repo->create($this->defaultAccountId(), [$boardId => 'write'], $userId, 'Findable', 'hash-find');

        $repo->revoke($this->defaultAccountId(), $tokenId);

        $row = $repo->findForAccount($this->defaultAccountId(), $tokenId);
        self::assertIsArray($row);
        self::assertNotNull($row['revoked_at']);
        self::assertArrayNotHasKey('token_hash', $row);
    }
}
