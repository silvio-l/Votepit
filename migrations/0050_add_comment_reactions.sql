-- 0050_add_comment_reactions — small, fixed-set emoji reactions on comments
-- (Fider-comparison low-priority recommendation: "reactions on comments").
--
-- Deliberately modeled on `votes` (see VoteRepository), not on a GitHub-style
-- "many reaction types per user" scheme: exactly ONE active reaction per
-- (comment, user), UNIQUE(comment_id, user_id) as the DB backstop — the same
-- "insert / switch-in-place / retract" toggle CommentReactionRepository::
-- toggle() implements, kept small and simple on purpose.
--
-- `reaction` is a fixed, small predefined set (CHECK constraint), never
-- free text — the Geteilte-Origin-Invariante (docs/adr/0001, CLAUDE.md §Sicherheit)
-- forbids any user-controlled active content on the shared app.votepit.com
-- origin, emoji reactions included.
--
-- No own account_id/board_id column: account/board scoping is transitive via
-- comment_id -> comments -> ideas -> boards, same pattern as `votes` and
-- `comments` themselves (see CommentRepository class doc) — the action loads
-- the comment idea-/board-scoped first (CommentRepository::findForIdea()),
-- only a confirmed-scoped comment_id ever reaches this table.
CREATE TABLE IF NOT EXISTS comment_reactions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comment_id BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    reaction   VARCHAR(8)      NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comment_reactions_comment_user (comment_id, user_id),
    CONSTRAINT fk_comment_reactions_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_comment_reactions_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT chk_comment_reactions_value CHECK (reaction IN ('👍', '👎', '❤️', '😄', '🎉'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
