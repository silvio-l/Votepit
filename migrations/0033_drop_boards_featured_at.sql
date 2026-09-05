-- 0033_drop_boards_featured_at — removes manual board curation.
--
-- Reverts 0032_add_boards_featured_at.sql: the /discover "Spotlight" band
-- is now fully algorithmic (BoardRepository::spotlightBoards(), weighted
-- random sampling without replacement over recent vote activity) — no
-- operator/admin override exists any more, so the column it drove is dead.
ALTER TABLE boards DROP COLUMN featured_at;
