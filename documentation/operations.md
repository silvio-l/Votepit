# Operations

## Migrations

Versioned, forward-only migration runner (`Votepit\Migrations\MigrationRunner`), invoked
via `bin/migrate.php`:

```bash
php bin/migrate.php            # shows pending migrations, asks for a backup confirmation, applies
php bin/migrate.php --dry-run  # shows what would run, applies nothing
```

File naming: `NNNN_description.sql` or `NNNN_description.php`, a 4-digit ascending prefix
(sorted lexically, so numbering order = apply order). `.sql` files hold a handful of
related DDL statements separated by `;\n` — no string literals containing an embedded
`;\n`. `.php` files end with `return new SomeMigrationClass();`; the class implements the
`Migration` interface (optionally `ConfigAwareMigration` if it needs config/secrets, e.g.
to backfill a value derived from `identity_server_key`).

`0000_baseline.sql` and `db/schema.sql` are never edited again once created — every future
schema change is a new file in `migrations/`. **Always take a fresh backup immediately
before running a migration against a database that matters** (see below).

## Backup/restore

### Taking a backup

```bash
php bin/backup-database.php               # → backups/votepit-<db>-<timestamp>.sql
php bin/backup-database.php --out=/path   # custom output path
```

Uses `mysqldump` under the hood (shell-out) rather than reimplementing dump logic, to get
correct FK ordering, consistency, and stored-routine handling for free. This script writes
a local file only — moving it off-site (e.g. into a nightly off-site cron) is a separate,
deployment-specific step not automated by this package.

### Rehearsing a restore

```bash
php bin/verify-backup-restore.php --dump=<path> --target-name=<db> \
  [--target-host=] [--target-port=] [--target-user=] [--target-pass=]
```

Dumps (or uses an existing dump), restores it into a **distinct, disposable** database,
then runs the migration runner's `pending()` check against it as a dry run (never calls
`migrate()`). Hard-guarded: refuses to run if `--target-name` matches the production
database name from `config.php`, with no bypass flag — this cannot accidentally restore
over production.

### Extracting a single tenant (Cloud)

```bash
php bin/restore-tenant.php --account-slug=<slug> --out=<path.json>
# or: --account-id=<id>
```

Extracts one account's data from an already-restored database copy into a re-importable
JSON file, reusing `AccountExportService` (the same code path as a customer's own GDPR
Art. 20 export) rather than a second table-graph implementation. Verifies board
`account_id` ownership against the database before extracting. Not relevant to a
single-account self-host install.

### Removing expired accounts (multi-account deployments)

```bash
php bin/cleanup-expired-accounts.php
```

Deletes accounts past their 30-day deletion grace period
(`accounts.deletion_scheduled_at`), cascading via `ON DELETE CASCADE` foreign keys. Not
scheduled automatically — must be added to cron manually if you run a multi-account
deployment. Extensions that keep their own account-referencing tables clean up through
`AppExtension::accountDeletionPrecondition()` before the delete runs.

## Logging & error monitoring

- **Audit log** (`Votepit\Logging\AuditLogger`) — pseudonymized security-relevant events,
  written to `logs/audit.log` by default (outside the web root), falling back to PHP's
  `error_log` if that path isn't writable. Emails in log context are masked
  (`foo@bar.tld` → `f**@b**.tld#<12-char SHA-256 suffix>` — readable and correlatable
  without being reversible). Secrets (`app_key`, passwords, plaintext tokens) must never
  reach the log context — this is enforced by convention in the codebase, not by a filter.
- **Error monitoring** — `ErrorReporter` interface with two implementations selected by
  `config.php`'s `sentry_dsn`: `NullErrorReporter` (default, no-op — the correct choice for
  most self-host installs) and `SentryErrorReporter` (active once a DSN is set; uncaught
  exceptions are reported to Sentry in addition to the existing `error_log` output).

## Rate limiting

See [`configuration.md`](configuration.md#rate-limits) for the full bucket table. Buckets
live in the `rate_limits` MySQL table, keyed `<bucket>:<identity>`; resetting one during
manual testing:

```sql
DELETE FROM rate_limits WHERE bucket LIKE '%magiclink%';
```

Never do this to route around limits on anything resembling real traffic.

## Mail testing

```bash
SMTP_HOST=... SMTP_PORT=... SMTP_USER=... SMTP_PASS=... SMTP_ENCRYPTION=tls \
SMTP_FROM_EMAIL=... SMTP_FROM_NAME=... php bin/send-test-mail.php you@example.com
```

Sends through the exact same mailer code path as magic links
(`SymfonyMailerAdapter`) — a clean run means the production SMTP config will work for
sign-in, not just "SMTP is reachable". See `config/smtp-test.env.example`.
