<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\ApiTokenRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence tests for ApiTokenRepository (Agent API).
 * Covers board scoping (no cross-board leak), hash-only storage (never
 * token_hash in listForBoard()/findForBoard()), and the revoke/find-by-hash
 * lifecycle.
 */
final class ApiTokenRepositoryTest extends IntegrationTestCase
{
    private function repo(): ApiTokenRepository
    {
        return new ApiTokenRepository($this->conn);
    }

    public function test_create_then_find_by_hash_returns_row(): void
    {
        $boardId = $this->insertBoard('board-a');
        $userId  = $this->insertUser('owner-a@example.com');
        $repo    = $this->repo();

        $tokenId = $repo->create($this->defaultAccountId(), $boardId, $userId, 'CI bot', 'hash-abc');

        $row = $repo->findByHash('hash-abc');
        self::assertIsArray($row);
        self::assertSame($tokenId, (int) $row['id']);
        self::assertSame($boardId, (int) $row['board_id']);
        self::assertSame($this->defaultAccountId(), (int) $row['account_id']);
        self::assertSame($userId, (int) $row['created_by_user_id']);
        self::assertNull($row['last_used_at']);
    }

    public function test_find_by_hash_touches_last_used_at(): void
    {
        $boardId = $this->insertBoard('board-touch');
        $userId  = $this->insertUser('owner-touch@example.com');
        $repo    = $this->repo();
        $repo->create($this->defaultAccountId(), $boardId, $userId, 'CI bot', 'hash-touch');

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

    public function test_list_for_board_never_leaks_hash_and_is_board_scoped(): void
    {
        $boardA = $this->insertBoard('board-list-a');
        $boardB = $this->insertBoard('board-list-b');
        $userId = $this->insertUser('owner-list@example.com');
        $repo   = $this->repo();

        $repo->create($this->defaultAccountId(), $boardA, $userId, 'Token A', 'hash-a');
        $repo->create($this->defaultAccountId(), $boardB, $userId, 'Token B', 'hash-b');

        $rows = $repo->listForBoard($boardA);

        self::assertCount(1, $rows);
        self::assertSame('Token A', $rows[0]['label']);
        self::assertArrayNotHasKey('token_hash', $rows[0]);
    }

    public function test_revoke_is_board_scoped_and_idempotent(): void
    {
        $boardA = $this->insertBoard('board-revoke-a');
        $boardB = $this->insertBoard('board-revoke-b');
        $userId = $this->insertUser('owner-revoke@example.com');
        $repo   = $this->repo();

        $tokenId = $repo->create($this->defaultAccountId(), $boardA, $userId, 'To revoke', 'hash-revoke');

        // A foreign board must not be able to revoke (cross-board leak structurally excluded).
        self::assertFalse($repo->revoke($boardB, $tokenId));
        self::assertNotNull($repo->findByHash('hash-revoke'));

        self::assertTrue($repo->revoke($boardA, $tokenId));
        self::assertNull($repo->findByHash('hash-revoke'));

        // Revoking again is idempotent (no error, but no more "changed row").
        self::assertFalse($repo->revoke($boardA, $tokenId));
    }

    public function test_find_for_board_sees_revoked_tokens_too(): void
    {
        $boardId = $this->insertBoard('board-find-for');
        $userId  = $this->insertUser('owner-find-for@example.com');
        $repo    = $this->repo();
        $tokenId = $repo->create($this->defaultAccountId(), $boardId, $userId, 'Findable', 'hash-find');

        $repo->revoke($boardId, $tokenId);

        $row = $repo->findForBoard($boardId, $tokenId);
        self::assertIsArray($row);
        self::assertNotNull($row['revoked_at']);
        self::assertArrayNotHasKey('token_hash', $row);
    }
}
