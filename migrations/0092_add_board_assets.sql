-- 0092_add_board_assets — per-board imagery and the back link / footer note
-- (P-28 imagery, P-31 Lite part; owner matrix Q13=a, gates Q19=a).
--
-- board_assets: one row per rendered variant of a board image. One cover
-- upload yields two variants (cover_wide 3:1 desktop, cover_phone 2:1 phone),
-- a favicon and a social share image (og) are one variant each. The bytes
-- live on disk under storage/board-assets/ (outside the webroot); the row
-- carries the opaque public id used in the public URL and the server-chosen
-- file name. Only server re-encoded output is ever stored, never the upload.
--
-- Board deletion cascades these rows, so the files are removed beforehand by
-- Votepit\Cleanup\BoardAssetCleanup (board delete, operator delete, GDPR
-- account purge). Downgrades keep the rows and files (kept, not applied).
--
-- Forward-only.
CREATE TABLE IF NOT EXISTS board_assets (
    board_id     BIGINT UNSIGNED NOT NULL,
    variant      VARCHAR(16) NOT NULL,
    public_id    CHAR(32) NOT NULL,
    file_name    VARCHAR(40) NOT NULL,
    content_type VARCHAR(16) NOT NULL,
    width        SMALLINT UNSIGNED NOT NULL,
    height       SMALLINT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (board_id, variant),
    UNIQUE KEY uq_board_assets_public_id (public_id),
    CONSTRAINT fk_board_assets_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Back link to the customer's site (https only, validated on write) and a
-- plaintext footer note (<= 280 characters). Both NULL = not set.
ALTER TABLE boards ADD COLUMN back_link_url VARCHAR(2048) NULL;
ALTER TABLE boards ADD COLUMN back_link_label VARCHAR(40) NULL;
ALTER TABLE boards ADD COLUMN footer_note VARCHAR(280) NULL;
