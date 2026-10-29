<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * A plan that has been resolved for one gating decision. Every gate method of
 * PlanPolicy takes this type instead of a plain string, so a raw stored plan
 * value cannot reach a gate: PHPStan rejects it at compile time.
 *
 * The only producer is AccountPlanResolver (forAccount() for the enforced plan,
 * storedPlan() for billing/admin decisions). tests/Architecture/CentralGatingGuardTest.php
 * fails if anything else constructs one.
 */
final readonly class ResolvedPlan
{
    private function __construct(private string $name) {}

    /**
     * @internal Only AccountPlanResolver may call this (guarded). Use the resolver instead.
     */
    public static function issue(string $name): self
    {
        return new self($name);
    }

    /** The plan value (`accounts.plan` vocabulary) for display, logging and payloads. */
    public function name(): string
    {
        return $this->name;
    }
}
