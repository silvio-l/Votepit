-- 0091_create_idea_status_decisions — reasoned status decisions (P-01).
--
-- Every REAL idea status change (from !== to, valid transition) appends one
-- row here, written in the same transaction as the status update
-- (Domain\IdeaStatusChanger). A change without a reason still gets a row, so
-- the history is complete; reason/next_step/responsible_role are optional and
-- only ever set by a moderator.
--
-- Public vs. internal: reason, next_step and responsible_role are PUBLIC
-- plaintext (rendered escaped in the SPA, never Markdown/HTML). responsible_role
-- is a fixed label key ('team' | 'moderation'), never a person: the acting user
-- is stored in actor_user_id for internal accountability only and is never
-- returned by a public read (docs/adr/0002, PII/HMAC identity model).
--
-- No own account_id column: account/board scoping is transitive via board_id,
-- same chokepoint reasoning as idea_tags (0051) and comment_reactions (0050) —
-- only a board/idea pair confirmed by the account-scoped board lookup and
-- IdeaRepository::findInBoard() ever reaches this table.
CREATE TABLE IF NOT EXISTS idea_status_decisions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id         BIGINT UNSIGNED NOT NULL,
    idea_id          BIGINT UNSIGNED NOT NULL,
    status_from      VARCHAR(16)     NOT NULL,
    status_to        VARCHAR(16)     NOT NULL,
    reason           VARCHAR(1000)   NULL,
    next_step        VARCHAR(280)    NULL,
    responsible_role VARCHAR(16)     NULL,
    actor_user_id    BIGINT UNSIGNED NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_idea_status_decisions_idea (idea_id, id),
    CONSTRAINT fk_idea_status_decisions_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_idea_status_decisions_idea  FOREIGN KEY (idea_id)  REFERENCES ideas(id)  ON DELETE CASCADE,
    CONSTRAINT fk_idea_status_decisions_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_idea_status_decisions_role CHECK (responsible_role IS NULL OR responsible_role IN ('team', 'moderation'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
