-- 0008_create_blocked_users — Sprint 15, Issue 02: Datenmodell für gezielten
-- User-Block (trägt sowohl den accountweiten als auch den später folgenden
-- board-scoped Fall — ein gemeinsames Modell).
--
-- board_id IS NULL  => accountweiter Block (gilt für alle Boards des Accounts).
--                      Durchsetzung dieses Issues (BlockCheckMiddleware).
-- board_id gesetzt  => board-scoped Block (nur das referenzierte Board).
--                      Durchsetzung folgt in einem separaten Schritt —
--                      hier nur das Schema.
--
-- UNIQUE(account_id, user_id, board_id) schützt den board-scoped Fall; MySQL
-- behandelt NULL in UNIQUE-Indizes als paarweise verschieden, deckt den
-- accountweiten Fall also NICHT ab — BlockRepository verhindert Duplikate dort
-- zusätzlich auf Anwendungsebene (Action prüft den Zielzustand vor jedem Write).
CREATE TABLE IF NOT EXISTS blocked_users (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    board_id   BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blocked_users_target (account_id, user_id, board_id),
    CONSTRAINT fk_blocked_users_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_blocked_users_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_blocked_users_board   FOREIGN KEY (board_id)   REFERENCES boards(id)   ON DELETE CASCADE,
    CONSTRAINT fk_blocked_users_actor   FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
