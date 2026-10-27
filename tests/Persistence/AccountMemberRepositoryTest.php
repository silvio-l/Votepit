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

    public function test_has_own_account_is_false_for_a_user_with_no_membership(): void
    {
        $userId = $this->insertUser('lonely2@example.com');

        self::assertFalse($this->repo()->hasOwnAccount($userId));
    }

    /**
     * The whole point of the owner-specific check (2026-09-05 fix): a plain
     * team member elsewhere must NOT count as "already has an account" —
     * only actually owning one does.
     */
    public function test_has_own_account_is_false_for_a_member_moderator_or_admin_role(): void
    {
        $accountId = $this->insertAccount();

        foreach (['member', 'moderator', 'admin'] as $role) {
            $userId = $this->insertUser($role . '@example.com');
            $this->insertAccountMember($accountId, $userId, $role);

            self::assertFalse($this->repo()->hasOwnAccount($userId), "role={$role} must not count as owning an account");
        }
    }

    public function test_has_own_account_is_true_for_an_owner(): void
    {
        $userId    = $this->insertUser('owner2@example.com');
        $accountId = $this->insertAccount();
        $this->insertAccountMember($accountId, $userId, 'owner');

        self::assertTrue($this->repo()->hasOwnAccount($userId));
    }

    public function test_is_operator_member_is_false_when_no_member_is_the_operator(): void
    {
        $accountId = $this->insertAccount();
        $userId    = $this->insertUser('plain-owner@example.com');
        $this->insertAccountMember($accountId, $userId, 'owner');

        self::assertFalse($this->repo()->isOperatorMember($accountId));
    }

    public function test_is_operator_member_is_true_when_the_operator_belongs_to_the_account(): void
    {
        $accountId  = $this->insertAccount();
        $operatorId = $this->insertUser('operator@example.com', ['is_operator' => 1]);
        $this->insertAccountMember($accountId, $operatorId, 'owner');

        self::assertTrue($this->repo()->isOperatorMember($accountId));
    }

    public function test_is_operator_member_does_not_leak_across_accounts(): void
    {
        $operatorAccountId = $this->insertAccount(['slug' => 'operator-account']);
        $otherAccountId    = $this->insertAccount(['slug' => 'other-account']);
        $operatorId        = $this->insertUser('operator2@example.com', ['is_operator' => 1]);
        $this->insertAccountMember($operatorAccountId, $operatorId, 'owner');

        self::assertFalse($this->repo()->isOperatorMember($otherAccountId));
    }
}
