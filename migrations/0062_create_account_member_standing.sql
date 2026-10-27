-- 0062_create_account_member_standing — board-operator-compliance issue
-- 10: repeat-offender escalation, one row per (account, member) tracking
-- how many reason-coded moderation actions (migrations/
-- 0059_create_content_moderation_actions.sql) have accumulated against
-- them and what that currently means for their submission privileges.
--
-- state: normal → flagged → warned → suspended, monotonic under the
-- automatic computation (Domain\ModerationStandingService) — a downgrade
-- only ever happens via a manual override (see
-- state_changed_by_user_id below). 'suspended' is enforced by
-- IdeaCreateAction/CommentCreateAction (403 `member_suspended`, checked
-- before any submission is persisted).
--
-- state_changed_by_user_id: NULL when the last change was the automatic
-- computation running after a new moderation action; set to the acting
-- admin/moderator's user_id when they used the manual override endpoint
-- (PATCH {accountPrefix}/admin/members/{id}/standing). This single
-- nullable column is the PRD's explicit choice over a separate boolean
-- "is_manual" flag — the actor identity doubles as that flag.
--
-- No FK on state_changed_by_user_id (ON DELETE SET NULL would silently
-- turn a manual override into an indistinguishable-from-automatic row if
-- the acting user is later deleted) — deliberately left unconstrained,
-- same reasoning already applied elsewhere in this schema wherever
-- "who did this" is secondary to "what happened" (e.g.
-- content_moderation_appeals.resolved_by_user_id chose ON DELETE SET
-- NULL instead since there the actor identity isn't load-bearing; here it
-- is, so no FK at all rather than risk losing the distinction).
CREATE TABLE account_member_standing (
    account_id               BIGINT UNSIGNED NOT NULL,
    user_id                  BIGINT UNSIGNED NOT NULL,
    violation_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    state                    VARCHAR(16)     NOT NULL DEFAULT 'normal',
    state_changed_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    state_changed_by_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (account_id, user_id),
    CONSTRAINT chk_ams_state CHECK (state IN ('normal', 'flagged', 'warned', 'suspended')),
    CONSTRAINT fk_ams_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ams_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
