-- Votepit — first board seed
--
-- Inserts a first board if none with the slug 'feedback' exists yet.
-- Board CRUD via the admin UI comes later; until then this is provisioned via SQL.
--
-- Adjust: slug, name and, if needed, intro to your own project name.
-- Run AFTER the migrations (mysql < db/schema.sql resp. php bin/migrate.php):
--   mysql -u <user> -p <db> < db/seed-first-board.sql
--
-- Idempotent via INSERT IGNORE (running it twice has no effect thanks to the UNIQUE key).
-- account_id has come from the default account since migrations/0003_seed_default_account.sql —
-- boards.account_id became NOT NULL there, so an INSERT without account_id would be
-- swallowed by INSERT IGNORE without error, but with no effect.

INSERT IGNORE INTO boards (account_id, slug, name, intro, is_default, created_at)
SELECT id, 'feedback', 'Feedback', 'Schreib uns, was du dir für dieses Produkt wünschst.', 1, NOW()
FROM accounts
WHERE is_default = 1
LIMIT 1;
