-- 0102_add_board_theme_mode — board display mode for the colour scheme (P-26,
-- dark mode). NULL = system (follow the viewer). Values: system | light | dark,
-- validated by BoardThemeMode. Available on every plan (owner answer 45a,
-- 2026-10-25). Forward-only.
ALTER TABLE boards ADD COLUMN theme_mode VARCHAR(8) NULL;
