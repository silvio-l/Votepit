-- 0113_create_worklist_dismissals — "Erledigt / ausblenden" of the operator
-- worklist (P-06, owner decision 9a+c, part B).
--
-- One row per (account, idea) that a moderator or owner hid from the
-- worklist because it needs no reaction. The row does NOT carry a user id:
-- a dismissal is account workflow state, so user erasure needs no change.
--
-- The row stops hiding the idea as soon as the idea gets activity after the
-- dismissal (see IdeaRepository::WAITING_PREDICATE / DISMISSED_IN_FORCE):
--   - a comment (not soft-deleted) created after dismissed_at, or
--   - a status change: ideas.status differs from status_at_dismissal.
-- Votes do not reactivate an idea (a popular decision-due idea would
-- otherwise return with every vote).
--
-- Deletion: account_id cascades with the account; idea_id cascades with the
-- idea row (hard delete). Soft-deleted or merged ideas keep their row, which
-- is harmless because the waiting and decision-due predicates exclude them.
CREATE TABLE IF NOT EXISTS worklist_dismissals (
    account_id          BIGINT UNSIGNED NOT NULL,
    idea_id             BIGINT UNSIGNED NOT NULL,
    dismissed_at        DATETIME        NOT NULL,
    status_at_dismissal VARCHAR(16)     NOT NULL,
    PRIMARY KEY (account_id, idea_id),
    KEY idx_worklist_dismissals_idea (idea_id),
    CONSTRAINT fk_worklist_dismissals_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_worklist_dismissals_idea    FOREIGN KEY (idea_id)    REFERENCES ideas(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
