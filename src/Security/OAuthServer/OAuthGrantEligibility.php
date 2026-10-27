<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\AccountRoles;
use Votepit\Domain\PlanPolicy;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\UserRepository;

/**
 * May this user delegate Agent API access to this account through MCP
 * OAuth? The same rule as creating a static token on the token page
 * (ApiTokenAction: AuthZ accountAdmin + PlanPolicy::agentApiAllowed()),
 * plus the account must not be locked and the user not globally blocked.
 *
 * Checked at consent, again when the code is exchanged and on every refresh
 * — a consenting user who lost the owner/admin role (or was removed or
 * blocked), a locked account or a downgrade below the Agent API plan ends
 * the grant at the next refresh (the access token itself is short-lived).
 * Fail-secure: anything not positively allowed yields a denial reason.
 */
final readonly class OAuthGrantEligibility
{
    public const DENY_ACCOUNT = 'account_unavailable';
    public const DENY_ROLE    = 'not_account_admin';
    public const DENY_PLAN    = 'plan_limit_agent_api';

    public function __construct(
        private AccountRepository $accounts,
        private AccountPlanResolver $plans,
        private AccountMemberRepository $members,
        private UserRepository $users,
        private PlanPolicy $planPolicy,
    ) {}

    /**
     * @param array<string, mixed>|null $user the session user if already hydrated; loaded otherwise
     * @return string|null null = eligible, else one of the DENY_* reasons
     */
    public function denialReason(int $accountId, int $userId, ?array $user = null): ?string
    {
        $user ??= $this->users->findById($userId);
        if ($user === null || (int) ($user['id'] ?? 0) !== $userId || (bool) ($user['is_blocked'] ?? false)) {
            return self::DENY_ROLE;
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null || ($account['locked_at'] ?? null) !== null) {
            return self::DENY_ACCOUNT;
        }

        if (!AccountRoles::canAdminister($this->members->roleFor($accountId, $userId))) {
            return self::DENY_ROLE;
        }

        $plan = $this->plans->forAccount($accountId, $user);

        return $this->planPolicy->agentApiAllowed($plan) ? null : self::DENY_PLAN;
    }

    /**
     * Accounts the user could delegate (owner/admin, unlocked) — the consent
     * screen's choice list. Plan eligibility is reported per account by the
     * caller via denialReason(), so an ineligible account can be shown
     * disabled with a reason instead of silently missing.
     *
     * @return list<array{id: int, slug: string, name: string}>
     */
    public function candidateAccounts(int $userId): array
    {
        // Same membership filter the weekly digest uses for "owner or admin,
        // unlocked" — one query, no second copy of the rule.
        return $this->members->digestAccountsFor($userId, true);
    }
}
