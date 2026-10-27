-- 0096_drop_account_brand_kits — removes the account brand kit (0094, P-32).
-- Owner decision 2026-10-25: branding is a per-board setting only; there is
-- no account-level branding. Boards keep their own colours and logo in the
-- boards table, so nothing a board shows is lost. Forward-only; nothing reads
-- account_brand_kits after this migration.
DROP TABLE IF EXISTS account_brand_kits;
