<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BoardRepository;

/**
 * Applies the plan an account now has to its existing data, after any plan
 * change (billing webhook, manual grant or revoke, grant expiry). Every
 * caller goes through this one place so no step can be forgotten:
 *
 *   1. boards whose visibility the plan does not allow -> frozen (read-only),
 *      visibility kept, still readable by link; lifted again automatically
 *      once the plan allows the visibility (then subject to step 2);
 *   2. boards above the plan's board limit -> frozen, newest first (the owner
 *      re-chooses the active ones via BoardActiveSetAction);
 *   3. team members above the plan's member limit -> suspended (the owner
 *      always keeps access), restored automatically when the plan allows it;
 *   4. API tokens, if the plan has no Agent API -> revoked.
 *
 * Idempotent and self-healing: every call derives the state from the plan it
 * is given. Errors propagate, so a caller inside a transaction rolls back
 * (fail-secure, the state is never half-applied).
 *
 * Core stays tier-agnostic: it only reads the PlanPolicy limits.
 */
final readonly class PlanLimitReconciler
{
    public function __construct(
        private BoardRepository $boards,
        private AccountMemberRepository $members,
        private ApiTokenRepository $apiTokens,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
    ) {}

    /** @param string $planName the plan that is now in effect (persisted before this call) */
    public function reconcile(int $accountId, string $planName): void
    {
        $plan = AccountPlanResolver::storedPlan($planName);

        $allowedVisibilities = $this->planPolicy->allowedVisibilities($plan);
        $visibilityRestored  = $this->boards->restoreVisibilityFrozen($accountId, $allowedVisibilities);
        if ($visibilityRestored !== []) {
            $this->audit->log('board.visibility_restored', ['account_id' => $accountId, 'plan' => $planName, 'board_ids' => $visibilityRestored]);
        }

        $visibilityFrozen = $this->boards->freezeVisibilitiesOutside($accountId, $allowedVisibilities);
        if ($visibilityFrozen !== []) {
            $this->audit->log('board.visibility_frozen', ['account_id' => $accountId, 'plan' => $planName, 'board_ids' => $visibilityFrozen]);
        }

        $limitFrozen = $this->boards->enforcePlanLimit($accountId, $this->planPolicy->boardLimit($plan));
        if ($limitFrozen !== []) {
            $this->audit->log('board.downgrade_frozen', ['account_id' => $accountId, 'plan' => $planName, 'board_ids' => $limitFrozen]);
        }

        $seats = $this->members->reconcileMemberLimit($accountId, $this->planPolicy->memberLimit($plan));
        if ($seats['suspended'] !== []) {
            $this->audit->log('member.plan_suspended', ['account_id' => $accountId, 'plan' => $planName, 'user_ids' => $seats['suspended']]);
        }
        if ($seats['restored'] !== []) {
            $this->audit->log('member.plan_restored', ['account_id' => $accountId, 'plan' => $planName, 'user_ids' => $seats['restored']]);
        }

        if (!$this->planPolicy->agentApiAllowed($plan)) {
            $revokedTokenIds = $this->apiTokens->revokeAllForAccount($accountId);
            if ($revokedTokenIds !== []) {
                $this->audit->log('api_token.downgrade_revoked', ['account_id' => $accountId, 'plan' => $planName, 'token_ids' => $revokedTokenIds]);
            }
        }
    }
}
