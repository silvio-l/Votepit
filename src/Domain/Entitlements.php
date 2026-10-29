<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * The `entitlements` object the SPA reads instead of the separate plan enums
 * (P-38). Single source for every feature gate's lock state: the answers come
 * from PlanPolicy, never from a second table.
 *
 * Shape:
 *   current_plan      stored `accounts.plan` (self-host: 'self-host')
 *   can_change_plan   role is exactly owner AND the installation has billing
 *   features[key]     {allowed, required_plan}; required_plan is the lowest plan
 *                     that includes the feature while it is locked, null otherwise
 *
 * Feature answers use the EFFECTIVE plan (AccountPlanResolver::forAccount for the
 * requesting user), the same plan the write gates enforce — so the UI never
 * shows a feature as open that the server would refuse. This only ever goes
 * into payloads for account members (bootstrap memberships, admin branding);
 * public and non-member payloads never carry it.
 */
final class Entitlements
{
    /**
     * @return array{current_plan: string, can_change_plan: bool, features: array<string, array{allowed: bool, required_plan: ?string}>}
     */
    public static function forAccount(
        PlanPolicy $policy,
        string $storedPlan,
        ResolvedPlan $enforcedPlan,
        ?string $role,
        bool $billingEnabled,
    ): array {
        $features = [];
        foreach (PlanPolicy::FEATURES as $feature) {
            $allowed = $policy->featureAllowed($enforcedPlan, $feature);
            $features[$feature] = [
                'allowed'       => $allowed,
                'required_plan' => $allowed ? null : $policy->lowestPlanAllowing($feature),
            ];
        }

        return [
            'current_plan'    => $storedPlan,
            'can_change_plan' => AccountRoles::isOwner($role) && $billingEnabled,
            'features'        => $features,
        ];
    }
}
