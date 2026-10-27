-- 0007_backfill_admin_account_membership — Sprint 1.3: bestehende Plattform-Admins
-- werden Owner des Default-Accounts.
--
-- Self-Host-Bestandsszenario: users.is_admin=1 aus einer VOR Sprint 1.3 laufenden
-- Installation hat kein account_members-Pendant (die Tabelle gab es noch nicht).
-- AuthZMiddleware::accountAdmin() prüft ausschließlich account_members — ohne
-- diesen Backfill würde ein bereits eingeloggter Bestands-Admin (gültige Session,
-- also kein erneuter /login/verify-Durchlauf, der das sonst per Self-Host-
-- Bootstrap-Kontinuität nachzieht) bis zum nächsten Login von /admin/boards/*
-- ausgesperrt. Idempotent (INSERT IGNORE über den PRIMARY KEY).
INSERT IGNORE INTO account_members (account_id, user_id, role, created_at)
SELECT (SELECT id FROM accounts WHERE is_default = 1 LIMIT 1), id, 'owner', NOW()
FROM users
WHERE is_admin = 1;
