<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Whether machine translation may run for an account, on top of the
 * installation switch (Config::translationConfigured()) and the per-board
 * opt-in (`boards.translation_enabled`).
 *
 * The plan feature `translation` is the gate: the Community Edition allows it
 * on every plan (UnrestrictedPlanPolicy), a tiered policy decides per account.
 * Resolved against the account that owns the content, never the viewer's own
 * accounts — the account pays for the provider calls.
 */
final readonly class TranslationGate
{
    public const FEATURE = 'translation';

    public function __construct(
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
    ) {}

    public function accountAllows(int $accountId, mixed $user): bool
    {
        return self::planAllows($this->planPolicy, $this->plans->forAccount($accountId, $user));
    }

    /**
     * The board's opt-in as the app should see it: stored flag AND plan.
     * Fail-secure — an unset flag is off.
     *
     * @param array<string, mixed> $board
     */
    public function boardEnabled(array $board, int $accountId, mixed $user): bool
    {
        return (bool) ($board['translation_enabled'] ?? false) && $this->accountAllows($accountId, $user);
    }

    /** For callers that already resolved the plan. */
    public static function planAllows(PlanPolicy $planPolicy, ResolvedPlan $plan): bool
    {
        return $planPolicy->featureAllowed($plan, self::FEATURE);
    }
}
