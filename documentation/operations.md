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
before running a migration against a database that matters** (using your own `mysqldump`
or hosting provider's tooling — this package does not ship backup/restore tooling, see
below).

## Backup/restore

This package does **not** ship any backup, restore, or per-tenant-extraction tooling.
Back up and restore the configured MySQL database using standard tooling (`mysqldump`,
your hosting provider's snapshot/backup feature, or a managed-database backup product) —
whatever fits your own operational setup. There is no Votepit-specific mechanism to test
or automate this.

### Removing expired accounts and self-deleted users

```bash
php bin/cleanup-expired-accounts.php
```

Deletes accounts past their 30-day deletion grace period
(`accounts.deletion_scheduled_at`), cascading via `ON DELETE CASCADE` foreign keys. This job
is dispatched by the cron manager (daily, see [Scheduled jobs](#scheduled-jobs-cron-manager));
the command above is for a manual run. Extensions that keep their own account-referencing tables clean up through
`AppExtension::accountDeletionPrecondition()` before the delete runs.

The same run then erases users whose own 48h self-deletion grace period has passed
(`users.deletion_scheduled_at`, requested under Profile → "Delete my account"). Their
ideas and comments stay, attributed to a shared "Deleted user" placeholder; their votes stay
(scores unchanged) but are no longer linked to anyone; everything else (profile, avatar,
notification email, sign-in methods, memberships, reactions, notifications) is removed.
Account owners and operators are skipped until they no longer hold that role.

## Scheduled jobs (cron manager)

Install exactly one scheduled task, every minute:

```
* * * * * php <root>/bin/votepit-cron.php
```

Each tick reaps expired job leases, then starts every job whose latest scheduled slot has not
run yet. Schedules are UTC wall-clock cron expressions (`cron_jobs` table, one lease row per
job). A job runs at most once per slot, never concurrently with itself, and at most three run
at once; the rest wait for a later tick. Each job runs in its own worker process, so a hung
job does not delay the others.

**Dispatch mode** (`cron_dispatch` in `config.php`):

- `'inline'` (default): the tick waits for its workers, bounded to about 50 seconds, and ends
  before the next minute. Anything still running at that point is terminated and recorded as
  a failure on a later tick.
- `'detached'`: the tick returns at once and the workers keep running. Use it only after the
  host survival test has confirmed that your hosting keeps child processes alive after the
  scheduled task has ended. Until then, keep the default.

Unknown values stop the manager at startup with an error.

**Alerts.** A failed run is a non-zero exit, a timeout, a job that could not be started, or an
uncaught error. When a job has failed three times in a row (one time for jobs that run hourly
or less often), every address in `admin_emails` gets one mail per tick that lists the job
name, exit reason, time and consecutive count. The mail never contains job output. While the
job keeps failing, there is at most one reminder every six hours. A recovery mail is sent once
when the job succeeds again. Sentry is informed only when an alert opens and when it recovers.
If a mail cannot be sent, the attempt is written to the audit log and STDERR and retried on
the next tick. Job logs are in `logs/cron/<job>.log` (rotated above 1 MB).

**Watchdog.** `php bin/check-cron-heartbeats.php` reports jobs whose heartbeat is overdue
(including the manager itself, if it has not started a tick for 15 minutes) and jobs whose
last run recorded a failure. Run it from its own scheduled task.

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

## Health & readiness probes

Two unauthenticated endpoints for uptime monitors and load balancers. Both answer a minimal
JSON body and never reveal versions, paths or error text.

- **`GET /health`** — liveness only: `200 {"ok":true}` whenever PHP and the request
  pipeline are up. Never touches the database.
- **`GET /ready`** — readiness: `200 {"ok":true}` only when **all** of these hold, otherwise
  `503 {"ok":false}`:
  1. the database answers `SELECT 1`;
  2. no migration is pending — core's `migrations/` (`schema_migrations`) and every
     extension stream (e.g. Votepit Cloud's `billing_schema_migrations`);
  3. the cron manager (`bin/votepit-cron.php`) started a tick within
     `readiness_cron_max_age_minutes` (default 5 — see
     [`configuration.md`](configuration.md#error-monitoring) and
     [Scheduled jobs](#scheduled-jobs-cron-manager)).

  The probe is read-only, sent with `Cache-Control: no-store`, and subject to the global per-IP rate limit. Which check failed is
  never in the response; it is written to the audit log as `readiness.not_ready` with
  `failed` = `database`, `migrations` and/or `cron`. Diagnose with
  `php bin/migrate.php --dry-run` and `php bin/check-cron-heartbeats.php`.

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://votes.example.com/ready   # 200 = ready
```

Point a load balancer's *readiness* check (or a "deploy done" gate) at `/ready` and the
*liveness*/restart check at `/health` — restarting PHP does not fix a pending migration or a
missing cron entry.

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

### Mail design previews

`php bin/send-mail-previews.php <recipient> [--only=kind,kind] [--brand]` sends ONE sample of
every transactional mail kind (`Votepit\Mail\MailPreviews`) through the installation's own SMTP,
with fake content ("Demo Board", dummy tokens) and a `[Preview]` subject prefix. `--brand` adds the
board-branded variants. It refuses to run unless `env` is `dev` or the first label of the `app_url` host marks a test installation (`staging`, `dev`, `test`, `qa`, `preview`, optionally `-suffix`, e.g. `staging-app.example.com`).
On a test installation over SSH (uses the server's own config, prints no secrets):
`ssh <user>@<host> "cd <app-dir> && php bin/send-mail-previews.php you@example.com --brand"`.
