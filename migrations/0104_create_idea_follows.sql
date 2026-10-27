-- 0104_create_idea_follows — "follow" an idea without voting (close-the-loop
-- slice 01). Registered users only; guest follow is out of scope (legal
-- trigger, docs/legal.md §5). Followers receive the same status updates as
-- voters (participant digest, see 0105/0106).
--
-- board_id is denormalised so board-scoped reads and the board cascade do
-- not need a join through ideas. Rows go with the idea, the board and the
-- user (ON DELETE CASCADE), so unfollowing or deleting any of them removes
-- the follow.
CREATE TABLE IF NOT EXISTS idea_follows (
    idea_id    BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    board_id   BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idea_id, user_id),
    KEY idx_idea_follows_user (user_id, board_id),
    KEY idx_idea_follows_board (board_id),
    CONSTRAINT fk_idea_follows_idea  FOREIGN KEY (idea_id)  REFERENCES ideas(id)  ON DELETE CASCADE,
    CONSTRAINT fk_idea_follows_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_idea_follows_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
