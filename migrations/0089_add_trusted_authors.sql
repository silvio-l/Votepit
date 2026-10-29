-- 0089_add_trusted_authors — trusted authors bypass pre-moderation
-- (broaden-use-cases issue 11). Both columns are nullable with no
-- backfill: nobody is trusted until a moderator says so, so every existing
-- account/board behaves exactly as before.
--
-- Trust lives on account_member_standing (0062) because it has the same
-- scope as the moderation history: one row per (account, user), never
-- across accounts. Setting trust on someone without a row inserts one
-- ('normal', violation_count 0).
--
-- trusted_at: when a moderator marked the author as trusted (NULL = not
-- trusted). Cleared again on revoke and automatically when the standing
-- drops to 'warned' or 'suspended'; going back to 'normal' does not
-- restore it.
--
-- trusted_by_user_id: the moderator who granted it. ON DELETE SET NULL
-- (and set to NULL explicitly by UserErasureRepository::erase()) — unlike
-- state_changed_by_user_id the actor here is secondary information, the
-- trust itself stays valid after the moderator's account is erased.
ALTER TABLE account_member_standing
    ADD COLUMN trusted_at DATETIME NULL AFTER state_changed_by_user_id,
    ADD COLUMN trusted_by_user_id BIGINT UNSIGNED NULL AFTER trusted_at;

ALTER TABLE account_member_standing
    ADD CONSTRAINT fk_ams_trusted_by FOREIGN KEY (trusted_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
