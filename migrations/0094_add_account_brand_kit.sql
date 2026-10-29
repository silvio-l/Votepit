-- 0094_add_account_brand_kit — account brand kit (P-32; owner matrix Q13=a,
-- gate `brand_kit` = Pro, Q19=a downgrade rule).
--
-- account_brand_kits: account-level defaults for the visual branding fields
-- (primary colour, secondary colour, logo URL). One row per account. Every
-- value is validated before it is written (BrandingValidator).
--
-- board_brand_overrides: the fields a board OWNS (keeps its own value). A
-- missing row means "inherit the account kit", so the kit applies to every
-- board of the account by default. Only "own" rows are stored.
--
-- A downgrade keeps both tables; the public read path (BrandResolver) only
-- applies the kit while the account's plan includes `brand_kit`.
-- Account and board deletion cascade both tables. Forward-only.
CREATE TABLE IF NOT EXISTS account_brand_kits (
    account_id      BIGINT UNSIGNED NOT NULL,
    primary_color   VARCHAR(7)      NULL,
    secondary_color VARCHAR(7)      NULL,
    logo_url        VARCHAR(512)    NULL,
    PRIMARY KEY (account_id),
    CONSTRAINT fk_account_brand_kits_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_brand_overrides (
    board_id BIGINT UNSIGNED NOT NULL,
    field    VARCHAR(32)     NOT NULL,
    PRIMARY KEY (board_id, field),
    CONSTRAINT fk_board_brand_overrides_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
