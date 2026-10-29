-- 0116_add_board_brand_accent_and_logo_plate — per-board accent colour, per-mode
-- (dark) primary and accent values, and the round logo's plate and ring settings
-- (dogfood branding, v0.1.5, items 07-09).
--
-- All columns are NULL-able and default to "unset": an unset value renders exactly
-- as before (accent highlights fall back to the primary colour, the dark mode
-- derives its colours from the light values, the logo plate is the automatic one).
-- Lite and higher may set them; a downgrade keeps the stored values and they are
-- simply not applied (Q19). The names are prefixed `brand_` on purpose: the legacy
-- boards.accent_color column (baseline) is NOT NULL with a '#3b82f6' default that
-- nothing reads, so it must not be reused. Forward-only.
ALTER TABLE boards
    ADD COLUMN brand_accent_color VARCHAR(7) NULL AFTER secondary_color,
    ADD COLUMN brand_primary_color_dark VARCHAR(7) NULL AFTER brand_accent_color,
    ADD COLUMN brand_accent_color_dark VARCHAR(7) NULL AFTER brand_primary_color_dark,
    ADD COLUMN brand_logo_plate VARCHAR(16) NULL AFTER brand_accent_color_dark,
    ADD COLUMN brand_logo_ring_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER brand_logo_plate;
