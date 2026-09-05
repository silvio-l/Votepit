<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Generic, table-driven PlanPolicy: one row per plan name, every
 * plan-derived number/rule in ONE place so no gate anywhere else ever
 * hardcodes a limit of its own.
 *
 * The Community Edition does not ship any tier table (it runs on
 * UnrestrictedPlanPolicy); this class exists so an extension can plug in
 * its own tiers, and so the gating mechanism itself stays testable in core
 * with synthetic tiers.
 *
 * Fail-safe: an unrecognized plan value is NEVER treated as unlimited/
 * allowed. boardLimit()/memberLimit() return 0 (deny any count check
 * outright), agentApiAllowed() returns false, allowedVisibilities() returns
 * only ['public'] and allowedBrandingFields() returns [] — the most
 * restrictive shape possible.
 */
final readonly class TablePlanPolicy implements PlanPolicy
{
    /**
     * @param array<string, array{board_limit: int|null, member_limit: int|null, agent_api: bool, visibilities: list<string>, branding_fields: list<string>}> $tiers
     *        Plan name => limits. `null` for board_limit/member_limit means unlimited.
     * @param string $initialPlan Plan written for newly created accounts; must be a key of $tiers.
     * @param string|null $topPlan Most permissive tier, handed to an is_operator caller
     *        by callers of topPlan(); must be a key of $tiers. Defaults to $initialPlan
     *        when omitted (harmless for synthetic/test tiers with no real hierarchy).
     */
    public function __construct(
        private array $tiers,
        private string $initialPlan,
        private ?string $topPlan = null,
    ) {
        if (!array_key_exists($initialPlan, $tiers)) {
            throw new \InvalidArgumentException("TablePlanPolicy: initial plan '{$initialPlan}' is not a configured tier.");
        }

        if ($this->topPlan !== null && !array_key_exists($this->topPlan, $tiers)) {
            throw new \InvalidArgumentException("TablePlanPolicy: top plan '{$this->topPlan}' is not a configured tier.");
        }
    }

    public function initialPlan(): string
    {
        return $this->initialPlan;
    }

    public function topPlan(): string
    {
        return $this->topPlan ?? $this->initialPlan;
    }

    public function isKnownPlan(string $plan): bool
    {
        return array_key_exists($plan, $this->tiers);
    }

    public function boardLimit(string $plan): int
    {
        if (!$this->isKnownPlan($plan)) {
            return 0;
        }

        return $this->tiers[$plan]['board_limit'] ?? PHP_INT_MAX;
    }

    public function memberLimit(string $plan): int
    {
        if (!$this->isKnownPlan($plan)) {
            return 0;
        }

        return $this->tiers[$plan]['member_limit'] ?? PHP_INT_MAX;
    }

    public function agentApiAllowed(string $plan): bool
    {
        return $this->isKnownPlan($plan) && $this->tiers[$plan]['agent_api'];
    }

    public function allowedVisibilities(string $plan): array
    {
        if (!$this->isKnownPlan($plan)) {
            return ['public'];
        }

        return $this->tiers[$plan]['visibilities'];
    }

    public function isVisibilityAllowed(string $plan, string $visibility): bool
    {
        return in_array($visibility, $this->allowedVisibilities($plan), true);
    }

    public function defaultVisibility(string $plan): string
    {
        $allowed = $this->allowedVisibilities($plan);
        foreach (['private', 'unlisted', 'public'] as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        return 'public';
    }

    public function allowedBrandingFields(string $plan): array
    {
        if (!$this->isKnownPlan($plan)) {
            return [];
        }

        return $this->tiers[$plan]['branding_fields'];
    }

    public function isBrandingFieldAllowed(string $plan, string $field): bool
    {
        return in_array($field, $this->allowedBrandingFields($plan), true);
    }
}
