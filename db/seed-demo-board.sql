-- Votepit — Demo-Board-Seed (Sprint 3, Sprint-3-Pragmatik)
--
-- Fügt ein erstes Board ein, falls noch keins mit dem Slug 'demo' existiert.
-- Board-CRUD via Admin-UI folgt in Sprint 8; bis dahin wird per SQL provisioniert.
--
-- Anpassen: slug, name und ggf. intro auf den eigenen Projekt-Namen.
-- Ausführen: mysql -u <user> -p <db> < db/seed-demo-board.sql
--
-- Idempotent via INSERT IGNORE (doppeltes Ausführen ohne Effekt dank UNIQUE-Schlüssel).

INSERT IGNORE INTO boards (slug, name, intro, is_default, created_at)
VALUES (
    'demo',
    'Demo Board',
    'Schreib uns, was du dir für dieses Produkt wünschst.',
    1,
    NOW()
);
