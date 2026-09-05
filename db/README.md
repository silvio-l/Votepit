# Database schema & migrations

`db/schema.sql` is a **baseline snapshot only** (the schema as of the initial,
pre-tenancy release — `0000_baseline.sql`). It does **not** contain the account/tenancy
tables (`accounts`, `account_members`, `invites`, `api_tokens`, `blocked_users`, …) or any column added since. It is kept only as a fast path for
a from-scratch dev database, never edited again.

The versioned, forward-only migration runner in `../migrations/` is the source of truth
for the current schema — see `../migrations/README.md`. A correct fresh install needs
`db/schema.sql` **plus every migration in `../migrations/`** applied in order (or,
equivalently, running the migration runner against an empty database, which applies
`0000_baseline.sql` first). See `../docs/installation.md` for the exact steps.

`db/seed-first-board.sql` is an optional convenience seed that creates a first board in
the default account (idempotent) — the admin UI can create boards as well, so a normal
install does not need it.

The `ideas.title` column carries an InnoDB FULLTEXT index used for duplicate-idea
detection (`DuplicateDetectionService`, MySQL FULLTEXT recall + Jaro–Winkler reranking); a
PHP fallback without FULLTEXT is used automatically when unavailable.
