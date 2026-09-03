<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * The Community Edition default: no plan-derived limits at all.
 *
 * A self-hosted installation has no notion of paid tiers — every account may
 * create any number of boards, invite any number of members, issue Agent API
 * tokens and use every visibility and branding option. Whatever value sits
 * in `accounts.plan` is accepted (isKnownPlan() is always true) so a
 * database that was previously driven by a tiered policy keeps working
 * unchanged after the policy is removed.
 */
final class UnrestrictedPlanPolicy implements PlanPolicy
{
    /** Matches the seeded default account (migrations/0003_seed_default_account.sql). */
    public const DEFAULT_PLAN = 'self-host';

    public function initialPlan(): string
    {
        return self::DEFAULT_PLAN;
    }

    public function isKnownPlan(string $plan): bool
    {
        return true;
    }

    public function boardLimit(string $plan): int
    {
        return PHP_INT_MAX;
    }

    public function memberLimit(string $plan): int
    {
        return PHP_INT_MAX;
    }

    public function agentApiAllowed(string $plan): bool
    {
        return true;
    }

    public function allowedVisibilities(string $plan): array
    {
        return self::ALL_VISIBILITIES;
    }

    public function isVisibilityAllowed(string $plan, string $visibility): bool
    {
        return in_array($visibility, self::ALL_VISIBILITIES, true);
    }

    public function allowedBrandingFields(string $plan): array
    {
        return self::ALL_BRANDING_FIELDS;
    }

    public function isBrandingFieldAllowed(string $plan, string $field): bool
    {
        return in_array($field, self::ALL_BRANDING_FIELDS, true);
    }
}
