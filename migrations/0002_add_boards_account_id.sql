-- 0002_add_boards_account_id — Sprint 1.2: boards bekommt Account-Zuordnung + Status.
--
-- account_id ist hier bewusst NULLable (Backfill folgt in 0003_seed_default_account,
-- die auch NOT NULL erzwingt + den finalen UNIQUE(account_id, slug)-Index setzt).
-- Kein PHP liest/schreibt diese Spalten in diesem Sprint — reine Schema-Vorbereitung.
--
-- status: freier VARCHAR statt CHECK-Constraint — analog zu ideas.status
-- (db/schema.sql), das im selben Schema ebenfalls ohne CHECK auskommt; die
-- projektweite CHECK-Nutzung ist uneinheitlich (votes.value hat eine, ideas.status
-- nicht). Erlaubte Werte (applikationsseitig durchgesetzt, Sprint 1.3+):
-- 'active' | 'archived' | 'locked'.
ALTER TABLE boards ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE boards ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active' AFTER account_id;
