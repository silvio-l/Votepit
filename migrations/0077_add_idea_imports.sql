-- 0077_add_idea_imports — board import (CSV, Canny and Featurebase exports).
--
-- idea_imports records one applied import per board. idempotency_key is
-- chosen by the client (the SPA generates one per selected file); the
-- UNIQUE(board_id, idempotency_key) key is the backstop that makes a
-- retried or double-submitted import a no-op instead of a second copy of
-- every idea. content_sha256 lets the server tell "same request replayed"
-- (answer with the stored summary) apart from "key reused for a different
-- file" (409). actor_user_id is SET NULL when the importing admin's user row
-- is deleted — the import record itself is board data, not personal data.
--
-- ideas.imported_vote_count is the vote-count baseline carried over from the
-- source tool. It is NOT a set of votes: no votes rows and no users are
-- created for it (no fake identities, ADR 0002). score_cache starts at this
-- baseline, so the documented invariant becomes
--   score_cache == imported_vote_count + SUM(votes.value)
-- and VoteRepository's incremental maintenance (score_cache += delta) keeps
-- it without any change. up_count/down_count stay real-vote counters only.
-- ideas.import_id links an imported idea back to its import (provenance);
-- SET NULL keeps the idea if the import record is ever removed.
CREATE TABLE idea_imports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id        BIGINT UNSIGNED NOT NULL,
    actor_user_id   BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(64)     NOT NULL,
    format          VARCHAR(16)     NOT NULL,
    content_sha256  CHAR(64)        NOT NULL,
    imported_count  INT             NOT NULL DEFAULT 0,
    skipped_count   INT             NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idea_imports_board_key (board_id, idempotency_key),
    KEY idx_idea_imports_actor (actor_user_id),
    CONSTRAINT fk_idea_imports_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_idea_imports_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ideas
    ADD COLUMN imported_vote_count INT NOT NULL DEFAULT 0 AFTER comment_count,
    ADD COLUMN import_id BIGINT UNSIGNED NULL AFTER imported_vote_count,
    ADD KEY idx_ideas_import (import_id),
    ADD CONSTRAINT fk_ideas_import FOREIGN KEY (import_id) REFERENCES idea_imports(id) ON DELETE SET NULL;
