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
 * outright), agentApiAllowed()/widgetAllowed() return false, allowedVisibilities() returns
 * only ['public'] and allowedBrandingFields() returns [] — the most
 * restrictive shape possible.
 */
final readonly class TablePlanPolicy implements PlanPolicy
{
    /**
     * @param array<string, array{board_limit: int|null, member_limit: int|null, agent_api: bool, visibilities: list<string>, branding_fields: list<string>, roadmap_gantt: bool, chat_notifications?: bool, widget?: bool, issue_tracker?: bool, anonymous_submission?: bool, features?: list<string>}> $tiers
     *        Plan name => limits. `null` for board_limit/member_limit means unlimited.
     *        `chat_notifications`, `widget`, `issue_tracker` and `anonymous_submission` are optional and default to false
     *        (fail-safe: a tier that doesn't mention a capability never gets it).
     * @param string $initialPlan Plan written for newly created accounts; must be a key of $tiers.
     * @param string|null $topPlan Most permissive tier, handed to an is_operator caller
     *        by callers of topPlan(); must be a key of $tiers. Defaults to $initialPlan
     *        when omitted (harmless for synthetic/test tiers with no real hierarchy).
     * @param list<string>|null $ladder Every tier exactly once, LOWEST plan first.
     *        Decides lowestPlanAllowing() (the `required_plan` of a locked feature).
     *        Defaults to the key order of $tiers. Tiers may also list
     *        `features` => list<string> with the FEATURES keys they include
     *        (default none); features with a dedicated flag are read from it.
     */
    public function __construct(
        private array $tiers,
        private string $initialPlan,
        private ?string $topPlan = null,
        private ?array $ladder = null,
    ) {
        if (!array_key_exists($initialPlan, $tiers)) {
            throw new \InvalidArgumentException("TablePlanPolicy: initial plan '{$initialPlan}' is not a configured tier.");
        }

        if ($this->topPlan !== null && !array_key_exists($this->topPlan, $tiers)) {
            throw new \InvalidArgumentException("TablePlanPolicy: top plan '{$this->topPlan}' is not a configured tier.");
        }

        if ($this->ladder !== null && (count($this->ladder) !== count($tiers) || array_diff($this->ladder, array_keys($tiers)) !== [] || count(array_unique($this->ladder)) !== count($this->ladder))) {
            throw new \InvalidArgumentException('TablePlanPolicy: ladder must list every configured tier exactly once.');
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

    public function boardLimit(ResolvedPlan $plan): int
    {
        if (!$this->isKnownPlan($plan->name())) {
            return 0;
        }

        return $this->tiers[$plan->name()]['board_limit'] ?? PHP_INT_MAX;
    }

    public function memberLimit(ResolvedPlan $plan): int
    {
        if (!$this->isKnownPlan($plan->name())) {
            return 0;
        }

        return $this->tiers[$plan->name()]['member_limit'] ?? PHP_INT_MAX;
    }

    public function agentApiAllowed(ResolvedPlan $plan): bool
    {
        return $this->isKnownPlan($plan->name()) && $this->tiers[$plan->name()]['agent_api'];
    }

    public function roadmapGanttAllowed(ResolvedPlan $plan): bool
    {
        return $this->isKnownPlan($plan->name()) && $this->tiers[$plan->name()]['roadmap_gantt'];
    }

    public function chatNotificationsAllowed(ResolvedPlan $plan): bool
    {
        return $this->isKnownPlan($plan->name()) && ($this->tiers[$plan->name()]['chat_notifications'] ?? false);
    }

    public function widgetAllowed(ResolvedPlan $plan): bool
    {
        return $this->isKnownPlan($plan->name()) && ($this->tiers[$plan->name()]['widget'] ?? false);
    }

    public function issueTrackerAllowed(ResolvedPlan $plan): bool
    {
        return $this->isKnownPlan($plan->name()) && ($this->tiers[$plan->name()]['issue_tracker'] ?? false);
    }

    public function anonymousSubmissionAllowed(ResolvedPlan $plan): bool
    {
        return $this->isKnownPlan($plan->name()) && ($this->tiers[$plan->name()]['anonymous_submission'] ?? false);
    }

    public function allowedVisibilities(ResolvedPlan $plan): array
    {
        if (!$this->isKnownPlan($plan->name())) {
            return ['public'];
        }

        return $this->tiers[$plan->name()]['visibilities'];
    }

    public function isVisibilityAllowed(ResolvedPlan $plan, string $visibility): bool
    {
        return in_array($visibility, $this->allowedVisibilities($plan), true);
    }

    public function defaultVisibility(ResolvedPlan $plan): string
    {
        $allowed = $this->allowedVisibilities($plan);
        foreach (['private', 'unlisted', 'public'] as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        return 'public';
    }

    public function featureAllowed(ResolvedPlan $plan, string $feature): bool
    {
        return $this->tierAllowsFeature($plan->name(), $feature);
    }

    /**
     * Tier-table lookup behind featureAllowed(). Takes a plain name because
     * lowestPlanAllowing() walks the catalogue, which is not a request decision
     * and must not mint ResolvedPlan values.
     */
    private function tierAllowsFeature(string $plan, string $feature): bool
    {
        if (!$this->isKnownPlan($plan) || !in_array($feature, self::FEATURES, true)) {
            return false;
        }

        $tier = $this->tiers[$plan];

        return match ($feature) {
            'secondary_color', 'logo_url', 'intro', 'hide_badge',
            'accent_color', 'primary_color_dark', 'accent_color_dark', 'logo_plate' => in_array($feature, $tier['branding_fields'], true),
            'visibility_unlisted'  => in_array('unlisted', $tier['visibilities'], true),
            'visibility_private'   => in_array('private', $tier['visibilities'], true),
            'roadmap_gantt'        => $tier['roadmap_gantt'],
            'widget'               => $tier['widget'] ?? false,
            'chat_notifications'   => $tier['chat_notifications'] ?? false,
            'issue_tracker'        => $tier['issue_tracker'] ?? false,
            'anonymous_submission' => $tier['anonymous_submission'] ?? false,
            'agent_api'            => $tier['agent_api'],
            'team_members'         => ($tier['member_limit'] ?? PHP_INT_MAX) > 1,
            default                => in_array($feature, $tier['features'] ?? [], true),
        };
    }

    public function lowestPlanAllowing(string $feature): ?string
    {
        foreach ($this->ladder ?? array_keys($this->tiers) as $plan) {
            if ($this->tierAllowsFeature($plan, $feature)) {
                return $plan;
            }
        }

        return null;
    }

    public function allowedBrandingFields(ResolvedPlan $plan): array
    {
        if (!$this->isKnownPlan($plan->name())) {
            return [];
        }

        return $this->tiers[$plan->name()]['branding_fields'];
    }

    public function isBrandingFieldAllowed(ResolvedPlan $plan, string $field): bool
    {
        return in_array($field, $this->allowedBrandingFields($plan), true);
    }
}
