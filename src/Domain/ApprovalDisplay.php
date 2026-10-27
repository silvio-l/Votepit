<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Whole-percent approval for display and export (owner decision L-03,
 * 2026-10-22). Mirrors the shared rule in @votepit/ui ds/voteSummary
 * (displayApproval): plain rounding, except that 100 is shown as 99 while
 * any down vote exists and 0 as 1 while any up vote exists. Zero votes are
 * handled by the caller (no percentage).
 */
final class ApprovalDisplay
{
    public static function percent(float $approval, bool $hasUp, bool $hasDown): int
    {
        $rounded = (int) round($approval);
        if ($hasDown && $rounded >= 100) {
            $rounded = 99;
        }
        if ($hasUp && $rounded <= 0) {
            $rounded = 1;
        }

        return $rounded;
    }
}
