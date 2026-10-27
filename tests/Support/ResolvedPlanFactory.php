<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Votepit\Domain\ResolvedPlan;

/**
 * Test-only way to make a ResolvedPlan from a plan name, for unit tests of
 * PlanPolicy implementations. Production code must obtain one from
 * AccountPlanResolver; CentralGatingGuardTest allow-lists this file and nothing
 * else under tests/ may construct a ResolvedPlan.
 */
final class ResolvedPlanFactory
{
    public static function of(string $plan): ResolvedPlan
    {
        return ResolvedPlan::issue($plan);
    }
}
