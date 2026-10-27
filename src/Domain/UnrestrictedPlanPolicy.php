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
 *
 * roadmapGanttAllowed() also returns true here, same as everything else:
 * the Roadmap Gantt view ships as a separate AppExtension package, not as
 * part of this MIT-licensed core — a self-hoster only ever reaches this
 * method at all once they've installed (i.e. purchased) that package, at
 * which point it behaves like any other Community feature. The protection
 * is the extension's absence from core, not a flag inside it (see ADR
 * 0003). Votepit Cloud's own CloudPlanPolicy still uses this flag for its
 * actual purpose: gating a plan tier on shared multi-tenant infrastructure
 * where the extension is always present.
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

    /** See class doc — true once the roadmap-gantt extension is actually installed. */
    public function roadmapGanttAllowed(string $plan): bool
    {
        return true;
    }

    public function chatNotificationsAllowed(string $plan): bool
    {
        return true;
    }

    public function widgetAllowed(string $plan): bool
    {
        return true;
    }

    public function issueTrackerAllowed(string $plan): bool
    {
        return true;
    }

    public function anonymousSubmissionAllowed(string $plan): bool
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

    /** Self-host allows every visibility, so the safest one ('private') is always available. */
    public function defaultVisibility(string $plan): string
    {
        return 'private';
    }

    public function featureAllowed(string $plan, string $feature): bool
    {
        return in_array($feature, self::FEATURES, true);
    }

    /** Nothing is locked on Community, so no plan ever has to be named as the upgrade target. */
    public function lowestPlanAllowing(string $feature): ?string
    {
        return null;
    }

    public function allowedBrandingFields(string $plan): array
    {
        return self::ALL_BRANDING_FIELDS;
    }

    public function isBrandingFieldAllowed(string $plan, string $field): bool
    {
        return in_array($field, self::ALL_BRANDING_FIELDS, true);
    }

    /** No tiers exist here, so the "top" plan is nominal — everything is already unrestricted. */
    public function topPlan(): string
    {
        return self::DEFAULT_PLAN;
    }
}
