-- 0017_add_account_onboarding — first-run Setup-Wizard (onboarding).
--
-- accounts.onboarding_completed_at: NULL until the account has been through
-- (or explicitly skipped) the Setup Wizard (BoardsAdminPage.tsx). Drives
-- whether BoardsAdminPage shows the wizard or the normal board list — a
-- single server-side flag rather than deriving "first run" purely from
-- board count, so an account that deletes its only board later is never
-- forced back through the wizard. Existing accounts (self-host installs
-- upgrading into this migration, and the seeded default account) are
-- backfilled to "already onboarded" so the wizard never interrupts an
-- established install.
ALTER TABLE accounts ADD COLUMN onboarding_completed_at DATETIME NULL AFTER is_default;

UPDATE accounts SET onboarding_completed_at = created_at WHERE onboarding_completed_at IS NULL;
