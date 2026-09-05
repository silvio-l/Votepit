-- 0006_finalize_email_hmac_column — Sprint 1.2b (ADR 0002): schließt die
-- HMAC-Pseudonymisierung ab. Macht email_hmac zur neuen Identitäts-Spalte
-- (NOT NULL + UNIQUE) und entfernt die Klartext-E-Mail-Spalte vollständig.
--
-- uq_users_email ist der reale, bestehende Unique-Index-Name aus db/schema.sql
-- (Zeile 65: `UNIQUE KEY uq_users_email (email)`).
--
-- WICHTIG — fail-secure by design: schlägt die MODIFY-Zeile fehl (NOT-NULL-
-- Constraint-Verletzung), war 0005_backfill_email_hmac.php unvollständig.
-- Das ist gewollt und wird NICHT abgefangen — kein stiller Datenverlust, siehe
-- migrations/README.md + CLAUDE.md (Pflicht-Backup unmittelbar vor jeder
-- Migration bleibt der Schutzmechanismus). Ebenso NICHT atomar über alle
-- Statements hinweg (DDL committet implizit pro Statement, siehe
-- 0003_seed_default_account.sql für dieselbe bewusst akzeptierte Grenze).
ALTER TABLE users MODIFY email_hmac CHAR(64) NOT NULL;
ALTER TABLE users ADD UNIQUE KEY uq_users_email_hmac (email_hmac);
ALTER TABLE users DROP INDEX uq_users_email;
ALTER TABLE users DROP COLUMN email;
