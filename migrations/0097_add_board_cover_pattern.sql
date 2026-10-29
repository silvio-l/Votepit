-- 0097_add_board_cover_pattern — selectable cover pattern (P-28c, owner
-- request 2026-10-25). NULL = the default pattern (the seed-based shapes).
-- The value is one of the server-side enum in BoardCoverPattern; the column
-- stays a plain string so a new pattern needs no schema change. Lite and
-- higher may set it; a downgrade keeps the stored value and it is simply not
-- applied (Q19). Forward-only.
ALTER TABLE boards ADD COLUMN cover_pattern VARCHAR(16) NULL;
