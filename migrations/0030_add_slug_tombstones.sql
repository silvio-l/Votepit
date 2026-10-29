-- 0030_add_slug_tombstones — review-2026-09-04-fixes item 3.
--
-- Account/board deletion used to free the slug back into UNIQUE(slug)
-- immediately (hard DELETE), so a new tenant could register the exact same
-- slug a departed tenant used and inherit their old links/bookmarks/QR
-- codes (link/trust hijack — ADR 0001 §2c point 5, §5b: "kein sofortiges
-- Slug-Recycling (Tombstone)"). This table records a tombstone at deletion
-- time; AccountRepository/BoardRepository reject re-registration of a
-- tombstoned, still-cooling-down slug (their own COOLDOWN_DAYS constant —
-- expires_at here is just the resulting deadline, computed at write time so
-- a later constant change doesn't retroactively shift already-written
-- tombstones).
--
-- scope: 'account' (global slug namespace, accounts.slug) or 'board'
-- (per-account namespace, boards.slug — account_id NOT NULL for this scope,
-- since two different accounts may legitimately tombstone the same board
-- slug independently). No FK to accounts(id): a scope='account' tombstone
-- deliberately outlives the account row it describes (that's the whole
-- point), and a scope='board' tombstone must survive the owning account
-- being deleted later (ON DELETE CASCADE would defeat it).
CREATE TABLE IF NOT EXISTS slug_tombstones (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope         VARCHAR(16)     NOT NULL,
    account_id    BIGINT UNSIGNED NULL,
    slug          VARCHAR(64)     NOT NULL,
    tombstoned_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_slug_tombstones_lookup (scope, account_id, slug, expires_at),
    CONSTRAINT chk_slug_tombstones_scope CHECK (scope IN ('account', 'board'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
