<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Pure computation for repeat-offender escalation (board-operator-
 * compliance issue 10, migrations/0062_create_account_member_standing.sql).
 *
 * No DB access here on purpose — given a member's CURRENT
 * account_member_standing row (or none yet) and the configured thresholds
 * (Config::moderationEscalation()), computeNextState() returns the state
 * the automatic computation moves to after one more moderation action.
 * Callers (CommentModerationAction/IdeaModerationAction, via
 * Persistence\AccountMemberStandingRepository) own the actual read/write —
 * this class only ever sees plain arrays, which keeps it unit-testable
 * without a database.
 *
 * Monotonic by construction: violation_count only ever increases here, and
 * state is derived from it fresh on every call rather than incremented
 * relative to the previous state — so a state can never regress through
 * this path. The ONLY way state moves backwards (e.g. suspended → normal)
 * is the manual override endpoint
 * (PATCH {accountPrefix}/admin/members/{id}/standing,
 * Http\Action\AccountMemberStandingAction), which bypasses this service
 * entirely and writes the target state directly.
 */
final class ModerationStandingService
{
    /**
     * @param array{violation_count?: int, state?: string}|null $currentStanding
     *        null when the member has no account_member_standing row yet
     *        (first-ever violation for them in this account).
     * @param array{flagged_at: int, warned_at: int, suspended_at: int} $config
     *        Config::moderationEscalation() shape.
     *
     * @return array{violation_count: int, state: string}
     */
    public function computeNextState(?array $currentStanding, array $config): array
    {
        $violationCount = ($currentStanding['violation_count'] ?? 0) + 1;

        $state = 'normal';
        if ($violationCount >= $config['suspended_at']) {
            $state = 'suspended';
        } elseif ($violationCount >= $config['warned_at']) {
            $state = 'warned';
        } elseif ($violationCount >= $config['flagged_at']) {
            $state = 'flagged';
        }

        return ['violation_count' => $violationCount, 'state' => $state];
    }
}
