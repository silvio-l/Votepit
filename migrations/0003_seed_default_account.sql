-- 0003_seed_default_account — Sprint 1.2: Default-Account + Backfill bestehender Boards.
--
-- Self-Host-Bestandsszenario: Boards, die vor dieser Migration existierten, haben
-- account_id = NULL (aus 0002_add_boards_account_id). Diese Migration legt EINEN
-- Default-Account an (idempotent — nur falls noch keiner mit is_default = 1
-- existiert), hängt alle verwaisten Boards daran und macht account_id danach
-- NOT NULL + Teil eines account-gescopten UNIQUE-Index.
--
-- uq_boards_slug ist der reale, bestehende Index-Name aus db/schema.sql
-- (Zeile 51: `UNIQUE KEY uq_boards_slug (slug)`).
--
-- WICHTIG — NICHT atomar über alle Statements hinweg: DDL in MySQL committet
-- implizit pro Statement (kein mehrstufiges Rollback über ALTER/UPDATE/INSERT
-- hinweg). Schlägt ein späteres Statement fehl (z. B. DROP INDEX), bleiben
-- frühere Statements (INSERT/UPDATE) bereits committet. Das ist eine bewusst
-- akzeptierte Grenze dieses Migrations-Runners (siehe migrations/README.md),
-- kein Bug, der hier gelöst werden muss — Pflicht-Backup unmittelbar vor jeder
-- Migration bleibt der Schutzmechanismus (CLAUDE.md).
INSERT INTO accounts (slug, name, plan, is_default, created_at)
SELECT 'default', 'Default Account', 'self-host', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE is_default = 1);

UPDATE boards SET account_id = (SELECT id FROM accounts WHERE is_default = 1 LIMIT 1)
WHERE account_id IS NULL;

ALTER TABLE boards MODIFY account_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE boards DROP INDEX uq_boards_slug;
ALTER TABLE boards ADD UNIQUE KEY uq_boards_account_slug (account_id, slug);
ALTER TABLE boards ADD CONSTRAINT fk_boards_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE;
