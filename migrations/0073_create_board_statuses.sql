-- 0073_create_board_statuses — status categories + owner-defined statuses
-- (broaden-use-cases issue 03, supersedes competitor-parity issue 13).
--
-- Every idea status belongs to one of six fixed CATEGORIES, stored as the
-- canonical slug of today's six statuses: open (inbox), planned,
-- in_progress (active), done (completed), declined, archived. All product
-- behaviour (roadmap columns, default-list archive filter, "ideas shipped",
-- badge colour, transition graph) hangs on the category, never on a label.
-- The public contract (REST/Agent API, MCP, webhooks, audit, export) keeps
-- reporting `status` = the category's canonical slug.
--
-- No rows for a board = the six system defaults (nothing materialised for
-- existing boards, zero data migration of ideas). The first admin edit
-- materialises the six system rows (status_key = canonical slug,
-- is_system = 1) in one transaction and sets boards.statuses_customized.
-- Custom statuses get a generated key `s<base36 id>` (fits
-- ideas.status VARCHAR(16)); ideas.status keeps storing the status KEY.
--
-- boards.statuses_customized is a denormalised "has rows" flag on the board
-- row every request already loads: a board with defaults never pays a
-- query for its status registry (query budgets unchanged).
--
-- Labels: plaintext only (validated in Domain\StatusLabel, max 40 chars),
-- NULL = default translation. No account_id column — same chokepoint
-- reasoning as idea_tags (0051): board_id is only ever reached through an
-- account-scoped board lookup.
CREATE TABLE IF NOT EXISTS board_statuses (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id       BIGINT UNSIGNED NOT NULL,
    status_key     VARCHAR(16) NOT NULL,
    category       VARCHAR(16) NOT NULL,
    label_de       VARCHAR(40) NULL,
    label_en       VARCHAR(40) NULL,
    position       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_hidden      TINYINT(1) NOT NULL DEFAULT 0,
    allow_votes    TINYINT(1) NOT NULL DEFAULT 1,
    allow_comments TINYINT(1) NOT NULL DEFAULT 1,
    is_system      TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_board_statuses_board_key (board_id, status_key),
    CONSTRAINT fk_board_statuses_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE boards ADD COLUMN statuses_customized TINYINT(1) NOT NULL DEFAULT 0 AFTER anonymous_voting_enabled;
