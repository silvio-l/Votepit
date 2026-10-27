-- 0063_add_boards_compliance_freeze — board-operator-compliance follow-up:
-- automatic enforcement of the legal-operator-name (migration 0058) and
-- board-operator-acknowledgment (cloud/migrations/0010) requirements for
-- ALREADY-EXISTING public/unlisted boards, not just at board-creation time.
--
-- boards.compliance_frozen_at: a THIRD, distinct freeze column — same
-- established pattern as frozen_at (migration 0016, plan-downgrade freeze)
-- vs. locked_at (migration 0013, operator kill-switch): each freeze reason
-- must stay independently settable/clearable without one silently masking
-- another. Unlike frozen_at (which stays PUBLICLY READABLE, only writes are
-- rejected), a compliance-frozen board is hidden from non-members entirely
-- (BoardRepository::findPublicBySlugForAccount() and every anon-facing
-- discovery query now also exclude it) — the missing legal-operator name/
-- acknowledgment means the board legally should not be shown to the public
-- at all, not merely "read-only". Writes are additionally rejected via the
-- existing FrozenBoardGuard (now checks both frozen_at and
-- compliance_frozen_at). Cloud-only in practice — self-host's
-- NullBoardOperatorAcknowledgmentGate never sets it, so self-host boards are
-- never affected (Cloud\Legal\BoardComplianceEnforcer is the only writer).
ALTER TABLE boards ADD COLUMN compliance_frozen_at DATETIME NULL AFTER frozen_at;

-- accounts.compliance_reminder_sent_at: guards the compliance-freeze
-- reminder mail against being resent on every subsequent owner login — same
-- rationale/pattern as accounts.deletion_reminder_sent_at (migration 0016).
-- Reset to NULL whenever an account's boards are fully compliant again
-- (Cloud\Legal\BoardComplianceEnforcer::enforceForAccount()), so a future
-- relapse sends a fresh reminder instead of staying silent forever.
ALTER TABLE accounts ADD COLUMN compliance_reminder_sent_at DATETIME NULL AFTER legal_operator_address;
