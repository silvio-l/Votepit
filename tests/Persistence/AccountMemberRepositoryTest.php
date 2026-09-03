<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\AccountMemberRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence tests for AccountMemberRepository::membershipsWithSlugFor()
 * (Fable audit 2026-09-02): the SPA uses this in the bootstrap payload to
 * determine the caller's account role — so it must return the slug + role
 * correctly, regardless of the number of accounts.
 */
final class AccountMemberRepositoryTest extends IntegrationTestCase
{
    private function repo(): AccountMemberRepository
    {
        return new AccountMemberRepository($this->conn);
    }

    public function test_returns_empty_list_for_user_with_no_membership(): void
    {
        $userId = $this->insertUser('lonely@example.com');

        self::assertSame([], $this->repo()->membershipsWithSlugFor($userId));
    }

    public function test_returns_slug_and_role_for_a_single_membership(): void
    {
        $userId    = $this->insertUser('owner@example.com');
        $accountId = $this->insertAccount(['slug' => 'acme']);
        $this->insertAccountMember($accountId, $userId, 'owner');

        $result = $this->repo()->membershipsWithSlugFor($userId);

        self::assertSame([['account_slug' => 'acme', 'role' => 'owner']], $result);
    }

    public function test_does_not_leak_other_users_memberships(): void
    {
        $userA = $this->insertUser('a@example.com');
        $userB = $this->insertUser('b@example.com');
        $accountId = $this->insertAccount(['slug' => 'acme']);
        $this->insertAccountMember($accountId, $userA, 'owner');
        $this->insertAccountMember($accountId, $userB, 'moderator');

        self::assertSame(
            [['account_slug' => 'acme', 'role' => 'moderator']],
            $this->repo()->membershipsWithSlugFor($userB),
        );
    }
}
