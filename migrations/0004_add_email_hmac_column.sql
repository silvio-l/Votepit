-- 0004_add_email_hmac_column — Sprint 1.2b (ADR 0002): fügt die Zielspalte für
-- die HMAC-Pseudonymisierung additiv hinzu. NULLable, damit die Migration ohne
-- Downtime läuft — der Backfill (0005) füllt sie, die Finalisierung (0006)
-- macht sie NOT NULL + UNIQUE und entfernt die alte email-Spalte.
ALTER TABLE users ADD COLUMN email_hmac CHAR(64) NULL AFTER email;
