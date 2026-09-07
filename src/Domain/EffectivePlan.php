<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Resolves the plan a request should actually be gated against.
 *
 * Two independent bypasses, either of which upgrades to $planPolicy->topPlan()
 * instead of the account's real `accounts.plan`:
 *
 *  1. The REQUESTING user is the platform operator (`users.is_operator`, see
 *     AuthZMiddleware — strictly above account owner/admin), regardless of
 *     which account they are acting in. A convenience for the operator's own
 *     day-to-day use of the product (support, testing, dogfooding).
 *  2. The TARGET ACCOUNT itself has the operator as a member ($accountHasOperatorMember,
 *     see AccountMemberRepository::isOperatorMember()) — e.g. the operator's
 *     own account for their own products. This upgrade applies regardless of
 *     who is viewing/acting (including anonymous visitors to a public board),
 *     since it's a property of the account, not of the current request's user.
 *
 * Neither is a billing decision — the account's stored `plan` column, and
 * what a paying member of an unrelated account sees, are completely
 * unaffected.
 */
final class EffectivePlan
{
    /** @param array<string, mixed>|null $user Request attribute AuthNMiddleware::ATTR_USER. */
    public static function resolve(
        string $accountPlan,
        ?array $user,
        PlanPolicy $planPolicy,
        bool $accountHasOperatorMember = false,
    ): string {
        if ($accountHasOperatorMember || ($user !== null && (bool) ($user['is_operator'] ?? false))) {
            return $planPolicy->topPlan();
        }

        return $accountPlan;
    }
}
