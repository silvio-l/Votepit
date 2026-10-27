<?php

declare(strict_types=1);

namespace Votepit\Extension;

/**
 * Extension hook for Cloud-only ongoing board-operator-compliance enforcement
 * (board-operator-compliance follow-up, 2026-09-09) — re-evaluates an
 * account's public-facing boards against the legal-operator-name +
 * board-operator-acknowledgment requirements and freezes/unfreezes
 * `boards.compliance_frozen_at` accordingly
 * (migrations/0063_add_boards_compliance_freeze.sql).
 *
 * Core knows nothing about WHY these requirements exist (the Cloud
 * Auftragsverarbeiter contract) — only that, if an extension provides this
 * hook, it gets called at every point the account's compliance status could
 * have changed: AccountRenameAction (legal_operator_name set/cleared),
 * BoardOperatorAcknowledgmentAction::acknowledge() (retroactive
 * acknowledgment), BoardBrandingAction (visibility change to a public-facing
 * value), and the periodic cron backstop `cloud/bin/enforce-board-compliance.php`.
 *
 * The Community default is NullBoardComplianceEnforcer: enforceForAccount()
 * is a no-op and always returns an empty list — self-host has no Cloud
 * contract to enforce, and `compliance_frozen_at` stays NULL forever there.
 */
interface BoardComplianceEnforcer
{
    /**
     * Re-evaluates every public-facing board of $accountId, freezing
     * non-compliant ones and unfreezing compliant-but-still-frozen ones.
     *
     * @return list<int> IDs of boards newly frozen by this call (empty if nothing changed).
     */
    public function enforceForAccount(int $accountId): array;
}
