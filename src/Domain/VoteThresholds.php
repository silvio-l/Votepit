<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Vote-count thresholds of the P-02 "enough votes" rule. Mirrors the
 * constants of the same name in core/packages/ui/src/ds/voteSummary.ts: the
 * SPA marks a result as "few votes" below these numbers, and the operator
 * worklist (P-06) treats an idea as decision due at or above them. Change
 * both files together.
 */
final class VoteThresholds
{
    /** Fewer votes than this: the result is marked as resting on few votes. */
    public const FEW_VOTES_THRESHOLD = 10;

    /** Fewer distinct voters than this: the result is marked as resting on few voters. */
    public const FEW_VOTERS_THRESHOLD = 5;

    private function __construct() {}
}
