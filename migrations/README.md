# Migrations

Versioned, forward-only migration runner (`Votepit\Migrations\MigrationRunner`,
`bin/migrate.php`). Replaces `db/schema.sql` as the source for schema changes going forward —
`db/schema.sql` remains as a convenience for fresh installs (`mysql < db/schema.sql`),
but is no longer edited itself.

## File naming

`NNNN_description.sql` or `NNNN_description.php`, a 4-digit, ascending
numeric prefix (ensures correct ordering via string sort).

- `.sql`: a few related DDL statements, separated by `;\n`. No
  string literals containing embedded `;\n` (see `SqlFileMigration`).
- `.php`: must end with `return new SomeMigrationClass();`; the class implements
  `Migration` (optionally `ConfigAwareMigration`, if config/secrets are needed).

## Usage

```
php bin/migrate.php            # shows pending migrations, asks for a backup, applies them
php bin/migrate.php --dry-run  # only shows what would be pending
```

## Principle

`0000_baseline.sql` (and `db/schema.sql`) are **never edited retroactively again**.
Every future schema change is a new file in this directory.

## Number gaps (0015, 0085–0087)

The sequence is not contiguous on purpose. Numbers are never renumbered, because installed databases
record applied versions by name. The gaps have two different causes:

- **`0015`: not part of the Community Edition.** It held a billing schema that belongs to a separate,
  non-public layer. Fresh installs never run `0015`. Installations that applied it keep the row in
  `schema_migrations`; the runner ignores applied versions that have no file. Do not reuse the number.
- **`0085`, `0086`, `0087`: never shipped.** The numbers were skipped during development, so there is
  nothing to remove or migrate. A new migration takes the next free number after the highest one, i.e.
  `0117` onward.

The runner needs no gap handling. It sorts the existing files and applies every version that is not
yet tracked.
