-- 0051_create_idea_tags — board-scoped tags for ideas (competitive-parity
-- feature, see Fider's entity/tag.go). idea_tags carries no account_id
-- column, only board_id — same chokepoint reasoning as `ideas`, `votes`,
-- `comments` and `board_blocklist`: a board_id belongs to exactly one
-- account, and every board lookup upstream of these tables already goes
-- through BoardRepository::findBySlugForAccount()/findPublicBySlugForAccount()
-- (account-scoped) before a board_id ever reaches this table, so a foreign
-- account's tags are structurally unreachable.
--
-- UNIQUE(board_id, name) — tag names are unique per board (case-sensitive;
-- application layer trims/limits length, no case-folding here, mirrors
-- board_blocklist's word uniqueness).
--
-- idea_tag_assignments is the idea<->tag join table, PK (idea_id, tag_id)
-- (no surrogate id needed — never queried by its own id). Both FKs cascade:
-- deleting an idea or a tag cleans up its assignments, no orphan rows.
CREATE TABLE IF NOT EXISTS idea_tags (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id   BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(40) NOT NULL,
    color      VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idea_tags_board_name (board_id, name),
    CONSTRAINT fk_idea_tags_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idea_tag_assignments (
    idea_id    BIGINT UNSIGNED NOT NULL,
    tag_id     BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idea_id, tag_id),
    CONSTRAINT fk_idea_tag_assignments_idea FOREIGN KEY (idea_id) REFERENCES ideas(id)    ON DELETE CASCADE,
    CONSTRAINT fk_idea_tag_assignments_tag  FOREIGN KEY (tag_id)  REFERENCES idea_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
