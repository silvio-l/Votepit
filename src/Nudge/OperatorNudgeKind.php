<?php

declare(strict_types=1);

namespace Votepit\Nudge;

/**
 * The operator-side nudge kinds (re-engagement, owner decisions 4/5/7).
 * The backing value is what the unsubscribe token and the sent-log store, so
 * it must stay stable once mails are out.
 */
enum OperatorNudgeKind: string
{
    /** First-14-days activation nudges (max. two mails: no public idea yet, no outside vote yet). */
    case Onboarding = 'onboarding';

    /** A board with earlier activity but none for 21 days. */
    case QuietBoard = 'quiet_board';

    /** An account reached 80 % of an enforced plan limit (Cloud plans only). */
    case PlanLimit = 'plan_limit';

    /** A newer Community release exists (self-host only). One mail per version: scope 'version:<x.y.z>'. */
    case UpdateAvailable = 'update_available';

    /**
     * The users column holding this kind's opt-out switch. A closed allow-list
     * of constants, so the column name is never built from input.
     */
    public function preferenceColumn(): string
    {
        return match ($this) {
            self::Onboarding => 'notify_onboarding_nudge_email',
            self::QuietBoard => 'notify_quiet_board_email',
            self::PlanLimit  => 'notify_plan_limit_email',
            self::UpdateAvailable => 'notify_update_available_email',
        };
    }

    /**
     * Whether recipients must have been active recently (decay, owner decision 6).
     * Update notices go to every owner: one who rarely logs in is exactly the one
     * who would otherwise miss a (security) release (owner decision 2026-10-29).
     */
    public function requiresRecentActivity(): bool
    {
        return $this !== self::UpdateAvailable;
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Onboarding, self::QuietBoard, self::PlanLimit, self::UpdateAvailable];
    }
}
