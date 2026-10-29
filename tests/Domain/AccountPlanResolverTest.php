<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use Votepit\Domain\AccountPlanResolver;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * The operator-member rule, once, at the resolver: an account with the platform
 * operator as a member resolves to the top plan for everyone; a requesting
 * operator resolves to the top plan in any account; otherwise the stored plan.
 * The resolver only ever reads the account id it is given.
 */
final class AccountPlanResolverTest extends IntegrationTestCase
{
    private function resolver(): AccountPlanResolver
    {
        $policy = self::syntheticPlanPolicy();

        return new AccountPlanResolver(
            new AccountRepository($this->conn),
            new AccountMemberRepository($this->conn),
            $policy,
        );
    }

    /** @return array<string, mixed> */
    private function operatorUser(): array
    {
        $id = $this->insertUser('operator@example.com', ['is_operator' => 1]);

        return ['id' => $id, 'is_operator' => 1];
    }

    public function test_anonymous_visitor_gets_the_stored_plan_when_no_operator_is_a_member(): void
    {
        $accountId = $this->insertAccount(['plan' => 'lite']);

        self::assertSame('lite', $this->resolver()->forAccount($accountId, null)->name());
    }

    public function test_requesting_operator_gets_the_top_plan_in_any_account(): void
    {
        $accountId = $this->insertAccount(['plan' => 'free']);

        self::assertSame('pro', $this->resolver()->forAccount($accountId, $this->operatorUser())->name());
    }

    public function test_operator_member_upgrades_the_account_for_anonymous_visitors(): void
    {
        $accountId = $this->insertAccount(['plan' => 'free']);
        $operatorId = $this->insertUser('operator@example.com', ['is_operator' => 1]);
        $this->insertAccountMember($accountId, $operatorId, 'member');

        self::assertSame('pro', $this->resolver()->forAccount($accountId, null)->name());
    }

    public function test_non_operator_user_does_not_upgrade_the_stored_plan(): void
    {
        $accountId = $this->insertAccount(['plan' => 'free']);
        $userId = $this->insertUser('user@example.com');

        self::assertSame('free', $this->resolver()->forAccount($accountId, ['id' => $userId, 'is_operator' => 0])->name());
    }

    public function test_stored_plan_skips_the_operator_bypass_that_forAccount_applies(): void
    {
        $accountId = $this->insertAccount(['plan' => 'free']);
        $operatorId = $this->insertUser('operator@example.com', ['is_operator' => 1]);
        $this->insertAccountMember($accountId, $operatorId, 'member');

        self::assertSame('pro', $this->resolver()->forAccount($accountId, null)->name());
        self::assertSame('free', AccountPlanResolver::storedPlan('free')->name());
    }

    public function test_operator_membership_only_upgrades_the_account_it_belongs_to(): void
    {
        $member = $this->insertAccount(['plan' => 'free']);
        $other = $this->insertAccount(['plan' => 'free']);
        $operatorId = $this->insertUser('operator@example.com', ['is_operator' => 1]);
        $this->insertAccountMember($member, $operatorId, 'member');

        self::assertSame('pro', $this->resolver()->forAccount($member, null)->name());
        self::assertSame('free', $this->resolver()->forAccount($other, null)->name());
    }

    public function test_stored_plan_is_never_written(): void
    {
        $accountId = $this->insertAccount(['plan' => 'free']);

        $this->resolver()->forAccount($accountId, $this->operatorUser());

        self::assertSame('free', $this->conn->fetchOne('SELECT plan FROM accounts WHERE id = ?', [$accountId]));
    }
}
