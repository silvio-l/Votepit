<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;

/**
 * The single entry point for "which plan does this account act on, for this
 * request?". Every plan-gated HTTP action, support gate and domain helper
 * resolves through here, so the operator-member rule of EffectivePlan cannot
 * be forgotten at one call site (it used to be opt-in per call).
 *
 * The rule itself lives in EffectivePlan::resolve(): the account has the
 * platform operator as a member, or the requesting user is the operator →
 * $planPolicy->topPlan(); otherwise the stored `accounts.plan`. This class
 * only supplies both inputs for the account id it is given — it never looks at
 * any other account, and it never writes `accounts.plan`.
 *
 * Guard: tests/Domain/AccountPlanResolverGuardTest.php fails if anything other
 * than this class (and EffectivePlan itself) references EffectivePlan::resolve.
 */
final readonly class AccountPlanResolver
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountMemberRepository $members,
        private PlanPolicy $planPolicy,
    ) {}

    /**
     * @param mixed                     $user    the request user (AuthNMiddleware::ATTR_USER), or null/anything else for
     *                                           anonymous visitors. Only an array with `is_operator` set upgrades the plan.
     * @param array<string, mixed>|null $account the already-loaded `accounts` row for $accountId, when the caller
     *                                           has it (saves one primary-key read). Must be that account's row.
     */
    public function forAccount(int $accountId, mixed $user, ?array $account = null): string
    {
        $account ??= $this->accounts->findById($accountId);

        return EffectivePlan::resolve(
            is_array($account) ? (string) ($account['plan'] ?? '') : '',
            is_array($user) ? $user : null,
            $this->planPolicy,
            $this->members->isOperatorMember($accountId),
        );
    }
}
