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
