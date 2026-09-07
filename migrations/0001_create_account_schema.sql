-- 0001_create_account_schema — Sprint 1.2: Account-Schema (Fundament für Multi-Tenant).
--
-- Legt NUR das Schema an — keine PHP-Code-Änderungen begleiten diese Migration
-- (AppFactory/BoardRepository/etc. bleiben unverändert).
-- Self-Host bleibt dadurch unverändert nutzbar: die neuen Tabellen sind für
-- den bestehenden Code unsichtbar, bis Sprint 1.3 sie anfasst.
--
-- accounts: ein Account = ein Mandant. Self-Host betreibt genau einen
-- Account (is_default = 1, siehe 0003_seed_default_account.sql).
CREATE TABLE IF NOT EXISTS accounts (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug                  VARCHAR(64)     NOT NULL,
    name                  VARCHAR(128)    NOT NULL,
    plan                  VARCHAR(16)     NOT NULL DEFAULT 'self-host',
    board_limit           INT UNSIGNED    NULL,
    member_limit          INT UNSIGNED    NULL,
    is_default            TINYINT(1)      NOT NULL DEFAULT 0,
    deletion_scheduled_at DATETIME        NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_accounts_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- account_members: Mitgliedschaft + Rolle eines (globalen) Users in einem Account.
-- Identität bleibt global (eine E-Mail = ein User über votepit.com, siehe ADR-1
-- §2c) — Mitgliedschaft/Rolle laufen ausschließlich über diese Zuordnungstabelle,
-- nicht über duplizierte User-Zeilen.
CREATE TABLE IF NOT EXISTS account_members (
    account_id BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    role       VARCHAR(16)     NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id, user_id),
    CONSTRAINT fk_account_members_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_account_members_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT chk_account_members_role   CHECK (role IN ('owner', 'moderator'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
