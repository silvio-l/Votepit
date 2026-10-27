-- 0095_drop_board_brand_overrides — removes the per-board override table
-- introduced by 0094 (P-32). The precedence is now value-based: a board's own
-- colour or logo (non-empty) always wins, an empty board field inherits the
-- account brand kit, and "apply to all boards" clears the board fields
-- explicitly. The override table would only duplicate that state.
-- Forward-only; nothing reads board_brand_overrides after this migration.
DROP TABLE IF EXISTS board_brand_overrides;
