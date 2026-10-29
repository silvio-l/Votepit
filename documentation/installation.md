# Installation

Two ways in, same application code:

- **VPS with shell access (Ubuntu/Debian): the one-line installer.** Section 0.
- **Shared hosting or anything without a shell: the release archive and an upload.**
  Sections 1 to 6.

## Requirements

- PHP **8.2+** with these extensions (`composer.json` plus what its dependencies need at runtime;
  dompdf needs `dom`, `zip` and `gd`):
  `curl`, `gd`, `intl`, `mbstring`, `pdo`, `pdo_mysql`, `sodium`, `dom`, `zip`, `iconv`.
  Check with `php -m`. Example packages for Ubuntu/Debian (replace `php-` with `php8.x-` to pin a
  version): `sudo apt-get install -y php-cli php-curl php-gd php-intl php-mbstring php-mysql php-xml php-zip`
  (`pdo` and `iconv` are normally built in; `php -m` shows them).
- MySQL **8.0.19+** or MariaDB **10.6+** — InnoDB FULLTEXT is used for duplicate-idea
  detection; a PHP fallback without FULLTEXT is used automatically if unavailable
- Apache 2.4 with `mod_rewrite` and `mod_headers`, `AllowOverride All` (or an equivalent nginx setup; the bundled rules are Apache `.htaccess`)
- SMTP access (magic-link sign-in has no password fallback — mail delivery is required)
- Installer and release build only: `unzip`, `curl`; building from source additionally needs `git`,
  Composer 2, Node 22+, pnpm 11 and `zip`
- No Docker

## 0. One-line installer (VPS with shell access)

On a fresh Ubuntu/Debian server, as a user with `sudo` (or root):

```bash
curl -fsSL https://raw.githubusercontent.com/silvio-l/votepit/main/install.sh | bash
```

or, after a manual download: `bash install.sh`. The installer:

1. checks PHP, its extensions and the database server version; if something is missing it prints
   the exact `apt-get` line and stops. It only runs apt for you with `--install-deps`
   (`curl -fsSL …/install.sh | bash -s -- --install-deps`).
2. downloads the latest release archive (or clones the repository and builds the archive when no
   release exists yet) and checks its `.sha256` when one is published;
3. asks for the install directory, the public base URL, and the database host, name, user and
   password (input hidden), and the admin e-mail addresses. It writes `config/config.php` from the
   template with freshly generated `app_key` and `identity_server_key`, file mode `600`. Secrets are
   never printed;
4. runs the migrations (you confirm the backup prompt) and prints the remaining manual steps:
   web root, HTTPS, writable folders, cron, mail.

It is safe to re-run: an existing `config/config.php` is kept, the code is refreshed, and only
pending migrations are applied. The database and its user must exist before you start (section 3).

Non-interactive use (automation, provisioning): set `VOTEPIT_NONINTERACTIVE=1` and
`VOTEPIT_INSTALL_DIR`, `VOTEPIT_APP_URL`, `VOTEPIT_DB_HOST`, `VOTEPIT_DB_NAME`, `VOTEPIT_DB_USER`,
`VOTEPIT_DB_PASS`, `VOTEPIT_ADMIN_EMAILS`; a missing value aborts. For pending migrations also set
`VOTEPIT_MIGRATE_YES=1`, which skips the backup prompt (only for a new, empty database).
Optional: `VOTEPIT_VERSION` (release tag), `VOTEPIT_ARCHIVE` (a local `votepit-community-*.zip`).

The installer needs a shell and `sudo` for packages. On shared hosting without SSH it cannot run;
use the archive path below.

## 1. Get the release archive

**Release archive (recommended):** download `votepit-community-<version>.zip` and its `.sha256` file
from the project's Releases page and check the checksum (`sha256sum -c votepit-community-<version>.zip.sha256`
on Linux, `shasum -a 256 -c …` on macOS). Unzip it on your computer. The result is one folder `votepit/`
containing the complete installation: production `vendor/`, the built web app in `public/`, the runtime
code, the migrations and the configuration **template** `config/config.example.php`. It contains no
secrets and no development or test files.

**Build it yourself** (any machine with PHP 8.2+, Composer 2, Node 22+, pnpm 11 and `zip`), from a
checkout of this repository's `core/` folder:

```bash
bash bin/build-release.sh          # → build/votepit-community-<version>.zip (+ .sha256)
```

The script installs the Node dependencies, builds the web app into `app/dist/`, stages only the
runtime files, runs `composer install --no-dev --optimize-autoloader` in the staging copy (your working
tree keeps its dev dependencies), verifies that no development files made it in, and packs the archive.
`SKIP_SPA=1 bash bin/build-release.sh` reuses an existing `app/dist/`.

**Build only the web app (manual upload without the script):** from `core/`, with Node 22+ and pnpm 11
(the versions the release build uses):

```bash
pnpm install --frozen-lockfile
pnpm --filter votepit-app run build      # → app/dist/ (index.html, assets/)
```

Copy the **contents** of `app/dist/` into `public/` of your installation. Without `public/index.html`
the site has no user interface: the PHP front controller answers browser page requests with the SPA
shell from that file, and nothing else can serve it.

## 2. Create the configuration

```bash
cp config/config.example.php config/config.php
```

Fill in at minimum: `db.*` (MySQL/MariaDB access), `smtp.*` (magic-link delivery),
`app_url` (public base URL, no trailing slash), `admin_emails` (who becomes admin on first
login), and two secrets:

```bash
php -r "echo bin2hex(random_bytes(32));"   # app_key
php -r "echo bin2hex(random_bytes(32));"   # identity_server_key (run again — separate key)
```

`app_key` signs sessions and magic links. `identity_server_key` is the HMAC key used to
pseudonymize email addresses (see [`architecture.md`](architecture.md#identity--pseudonymization)
and ADR 0002) — deliberately a **separate** secret so the two can be rotated independently.
Neither may ever be empty in production; leaving them blank is a misconfiguration, not a
supported "disabled" state. See [`configuration.md`](configuration.md) for every other key.

`config/config.php` is gitignored — never commit it, it holds live secrets. Keep it readable by the
web server only (`chmod 600` where your host allows it).

## 3. Create the database and its user

Create an empty database with `utf8mb4` and a dedicated user. In a MySQL or MariaDB shell as an
administrator (`sudo mysql` on Ubuntu, using the socket):

```sql
CREATE DATABASE votepit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'votepit'@'localhost' IDENTIFIED BY 'CHOOSE-A-LONG-RANDOM-PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
  ON votepit.* TO 'votepit'@'localhost';
FLUSH PRIVILEGES;
```

- Use the same names in `db.name`, `db.user`, `db.pass` and, with `db.host = 'localhost'`, the
  socket connection. If the application runs on another machine, create the user `'votepit'@'<app-host-ip>'`
  and set `db.host` to the server's address (and `ssl_ca`, see `configuration.md`).
- The grant covers the database only, not the server. The migration runner needs the DDL rights
  (`CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES`); nothing else is required. Do not use the
  `root` account for the application.
- **Shared hosting with a control panel:** create the database and the user in the panel's
  database wizard. The panel usually adds a prefix (e.g. `u123_votepit`); use the exact names and
  the host the panel shows, and grant the user full rights on that one database only.

The schema is then created with the versioned migration runner (section 4). `db/schema.sql` alone is
**not sufficient** — it is only the pre-tenancy baseline.

## 4. Create the database schema

```bash
php bin/migrate.php --dry-run   # review what would run
php bin/migrate.php             # prompts for a backup confirmation, then applies
```

Against a brand-new, empty database this applies `0000_baseline.sql` (the schema also
captured in `db/schema.sql`) followed by every `NNNN_*` migration under `migrations/` —
end result: the full current schema, including account/tenancy tables, invites, API
tokens, blocked users, etc. See [`operations.md`](operations.md#migrations) for the runner's
mechanics and safety guarantees.

**`--yes` skips the backup prompt.** `php bin/migrate.php --yes` applies everything without asking.
Use it only for automated runs on a new, empty database or throwaway/staging data. Never use it on
a database with real data: no backup is taken and migrations cannot be rolled back. The installer
uses it only when you set `VOTEPIT_MIGRATE_YES=1`.

If a migration fails, the runner stops, prints which migrations were applied and what to do, and
exits with status 1. See [`troubleshooting.md`](troubleshooting.md#fresh-install-failed).

## 5. Point the web root at `public/`

Configure your webserver's document root to `public/`. This is the recommended setup: only
`public/` is reachable, and everything else (config, source, vendor, migrations) is outside the web.

If your hosting does not let you choose a document root below the repo root (the common case on
shared hosting, where the uploaded folder *is* the document root), the bundled `.htaccess` at the
top of the folder routes every request into `public/` and serves nothing else directly. It needs
Apache with `AllowOverride All` and `mod_rewrite`. Verify it on your host before going live:

```bash
curl -s https://your-domain.example/config/config.php | head -c 200
```

The answer must be the application's page or an error, **never** PHP source code or any secret.
If you see PHP source, the `.htaccess` files are not being read (`AllowOverride` is `None`): stop
and fix that before you continue.

## 6. Deploy (shared hosting)

Upload the **contents of the unzipped `votepit/` folder** — `public/`, `src/`, `vendor/`, `bin/`,
`migrations/`, `db/`, `resources/`, `config/` (with your `config.php`) and the root `.htaccess` — over
FTPES (or your host's transfer method). Place them so that the web server's document root is either
`public/` (preferred) or the folder itself (see section 5). Create `storage/`, `logs/` and `var/` and
make them writable by the web server user; the application writes uploads and logs there.

Then, once per database (section 4): run the migrations. This needs a way to run the PHP command
line. Options, in order of preference:

1. SSH on the host: `php bin/migrate.php` in the installation folder.
2. The hosting panel's scheduled task, run once manually, with the command
   `php /path/to/votepit/bin/migrate.php --yes` on an empty database (see the `--yes` warning above).
3. Run the migrations **from your computer** against the remote database: unzip the archive
   locally, put your `config/config.php` with the remote `db.host` in it (the host must allow remote
   MySQL access from your IP), then run `php bin/migrate.php` locally. Only the database is touched.

If none of these is possible, the bundled tooling cannot create the schema on that host.

Cron (section 0, step 4 of the installer) is a scheduled task in the panel with the command
`php /path/to/votepit/bin/votepit-cron.php` and schedule "every minute".

There is no CI/CD pipeline bundled with this package; deploys are a file sync of a locally
built artifact.

## Upgrading an existing installation

1. **Back up first** — using your own `mysqldump`/hosting-provider backup tooling (see
   [`operations.md`](operations.md#backuprestore) — this package ships no backup tooling of
   its own). Every schema migration requires a fresh backup immediately before running it.
2. Pull/upload the new code (`src/`, `public/`, updated `vendor/` if `composer.lock`
   changed, updated SPA files in `public/` if the frontend changed). Keep your `config/config.php`.
   With the installer, re-running it does the same (it keeps the config and migrates).
3. Run `php bin/migrate.php --dry-run` to see what's pending, then `php bin/migrate.php`
   to apply. Migrations are forward-only — there is no automated rollback; restore from
   the pre-migration backup if something goes wrong.
4. Never hand-edit `0000_baseline.sql` or already-applied migration files — every schema
   change is a new, additively-numbered migration file.

### Community releases: `php bin/update.php`

Installations of a **published Community release** can update in one command instead of steps 2 and 3:

```bash
php bin/update.php --check      # is a newer release available?
php bin/update.php              # update to the latest release
php bin/update.php --version 0.1.8   # or a specific release
```

What it does, in order: it downloads the archive, its `.sha256` and its `.minisig` from the
[GitHub release](https://github.com/silvio-l/Votepit/releases); it refuses unless the checksum matches
**and** the minisign signature verifies against one of the two release keys built into the code
(`src/Update/TrustedReleaseKeys.php`); it refuses downgrades; it asks you to type `yes` once you have
made a backup yourself (`--yes` skips the question for scripted runs). Then it turns maintenance mode on
(the site answers 503), swaps only the code (`bin/`, `src/`, `public/`, `vendor/`, `migrations/`, …; `config/`,
`storage/` and `logs/` are never touched), runs the migrations and turns maintenance mode off.

If a migration fails, the previous code is restored automatically and maintenance mode **stays on**,
because the database may be partly migrated. Check the database against your backup, then remove
`storage/maintenance.flag` to bring the site back. Votepit ships no backup tooling of its own: the backup
is your responsibility, as in step 1.

Exit codes: `0` done or nothing to do, `1` refused or failed, `2` usage error.

## Product telemetry

**Off by default.** A fresh installation sends nothing anywhere: no tracker target is built
in, no consent step is shown and no third-party origin is allowed in the Content Security
Policy. Nothing leaves your installation unless you configure it yourself.

To offer product-improvement telemetry, set `telemetry_matomo_url` and
`telemetry_matomo_site_id` in `config/config.php` to a Matomo site **you** operate (see
[`configuration.md`](configuration.md#analytics)). Once both are set:

- Account admins see an opt-in toggle in the account settings. Nothing is sent until an admin
  switches it on; switching it off stops it again.
- What is sent: page views with dynamic path segments masked (`/boards/:id`, never the slug or
  ID), query strings and hashes dropped, and a small fixed set of goal events. No board or user
  content, no account names, no e-mail addresses.
- The tracker is cookieless. Whether IP addresses are stored is decided by your Matomo site's
  settings; enable IP anonymization there.
- Your privacy notice must reflect any third party that receives this data.

**Upgrading from an earlier release:** earlier releases had telemetry on by default. Since this
change, nothing is sent unless the target is configured **and** an admin has explicitly opted
in. Existing "undecided" accounts are treated as not opted in.

**Content Security Policy:** when a Matomo target is configured, PHP allows its origin in
`script-src` and `connect-src` automatically. The static Apache policy in `public/.htaccess`
replaces or intersects with that, so add the same origin there as well. Otherwise the browser
blocks the tracker.

Apart from the update check and version report described in
[What your installation sends](#what-your-installation-sends), the server core sends no telemetry of
its own. The `Votepit\Telemetry` event-tracker classes are not wired into any request path in this package.

## What your installation sends

Self-host installations of the Community edition make **two** outbound requests per day, and only
when you have not switched them off in `config/config.php` (see
[`configuration.md`](configuration.md#update-check-and-version-report)). Both are **on by default**. Both are
skipped on `routing_mode: cloud`, which is Votepit's hosted service, and on Votepit's public demo.

**1. Update check** (`update_check`, default `true`). A `GET` to the public GitHub REST API for the latest
stable release. The only thing sent is the request itself, which carries your server's IP address and the
User-Agent `Votepit-Community/<installed version>`. The answer is stored locally, shows the operator banner
when a newer version exists, and sends the account owner one e-mail per new version (switchable per user).

**2. Version report** (`version_report`, default `true`). At most one set of events per UTC day, sent to
Votepit's own Matomo instance (`matomo.silvio-und-maik.de`, Matomo site 11, fixed in the code). Three <!-- export-ok: own-matomo-host; export-ok: operator-account -->
events are sent, each with the same random installation ID as visitor ID:

| Event | Example value | Content |
|---|---|---|
| `install` / `version` | `0.1.8` | the installed Community version |
| `install` / `php` | `8.3.12` | the PHP version |
| `install` / `database` | `mariadb 10.11.6` | the database engine and version |

The installation ID is 16 random hex characters, generated once and stored in your `app_settings` table.
Each request also carries the fixed page URL `https://github.com/silvio-l/Votepit`, your server's IP address
and the same User-Agent as above.

**Not sent, ever:** e-mail addresses, account, board, idea, vote or user data, names, domains of your
installation, configuration or secrets.

Votepit's Matomo removes the last two octets of the IP address before it stores anything. Sending nothing:

```php
// config/config.php
'update_check'   => false,
'version_report' => false,
```

With both off, nothing leaves your installation. The release downloads themselves (step "Community
releases" in [Upgrading](#upgrading-an-existing-installation)) are a separate request to GitHub that you
start yourself.

## Multiple boards

One installation serves many boards, addressed by path (`/mobile-app`, `/website`, …).
Boards are created in the admin area; branding (name, slug, accent color, intro) is
configurable per board. This is **not** multi-tenancy — self-host installs run as a single
seeded account, and all boards belong to it (see
[`architecture.md`](architecture.md#tenancy-model)).
