-- 0082_add_board_phase_windows — optional per-board time windows for
-- submitting ideas and for voting (broaden-use-cases issue 04).
--
-- All four columns are nullable UTC instants. NULL = no limit on that side,
-- so every existing board (all NULL) behaves exactly as before. The server
-- computes the phase (open / upcoming / closed) from Votepit\Domain\BoardPhases
-- with an injectable clock; the SPA never gates on the client clock.
--
-- Effective rule for a write (see BoardPhases):
--   submit idea : submissions window (owner/admin exempt)
--   vote        : voting window AND the idea status' allow_votes flag (0073)
--   comment     : status flag only — windows never govern comments
-- Validation (opens < closes when both are set) lives in the admin action.
ALTER TABLE boards ADD COLUMN submissions_opens_at DATETIME NULL AFTER statuses_customized;
ALTER TABLE boards ADD COLUMN submissions_closes_at DATETIME NULL AFTER submissions_opens_at;
ALTER TABLE boards ADD COLUMN voting_opens_at DATETIME NULL AFTER submissions_closes_at;
ALTER TABLE boards ADD COLUMN voting_closes_at DATETIME NULL AFTER voting_opens_at;
