-- 0099_add_vote_reasons — P-03 "concerns with context": an optional reason
-- for a DOWN vote (Dagegen), visible to operators only, in aggregate.
--
-- boards.vote_reasons_enabled: per-board opt-in, default OFF (same NULL-free
-- TINYINT(1) pattern as 0055/0068). An existing board's behaviour is unchanged
-- until its owner switches it on; the follow-up is never shown while it is off.
ALTER TABLE boards ADD COLUMN vote_reasons_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER anonymous_voting_enabled;

-- vote_reasons: one row per DOWN vote at most. The vote row is the only link
-- (no user_id, no e-mail, no IP): the identity stays where it already is, in
-- votes. ON DELETE CASCADE from votes removes the reason with a withdrawn vote,
-- with an idea, a board or an account. A switch to For is handled in
-- VoteRepository (the row is deleted in the same transaction).
--
-- reason: fixed choices only (VoteReason::CHOICES). note: optional plain text,
-- at most 200 characters (checked in the action with mb_strlen; VARCHAR(200) is
-- counted in characters on utf8mb4). Never rendered as HTML.
CREATE TABLE IF NOT EXISTS vote_reasons (
    vote_id    BIGINT UNSIGNED NOT NULL,
    reason     ENUM('not_needed', 'too_expensive', 'worsens_workflow', 'other') NOT NULL,
    note       VARCHAR(200) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (vote_id),
    CONSTRAINT fk_vote_reasons_vote FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
