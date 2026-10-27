-- 0109_create_participant_new_ideas_state — per user and board: when the
-- opt-in "new ideas" digest last looked at this board (close-the-loop /
-- re-engagement F). The next digest only lists public ideas created after
-- this instant. Rows go with the user and the board.
CREATE TABLE IF NOT EXISTS participant_new_ideas_state (
    user_id    BIGINT UNSIGNED NOT NULL,
    board_id   BIGINT UNSIGNED NOT NULL,
    checked_at DATETIME        NOT NULL,
    PRIMARY KEY (user_id, board_id),
    CONSTRAINT fk_pnis_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_pnis_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
