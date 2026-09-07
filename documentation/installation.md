# Installation

## Requirements

- PHP **8.2+** with extensions `pdo`, `mbstring`, `intl` (see `composer.json`)
- MySQL **5.7+** or MariaDB **10.0+** — InnoDB FULLTEXT is used for duplicate-idea
  detection; a PHP fallback without FULLTEXT is used automatically if unavailable
- SMTP access (magic-link sign-in has no password fallback — mail delivery is required)
- A webserver that can point its document root at `public/`, or serve the whole tree with
  Apache `mod_rewrite`/`.htaccess` support (the bundled `.htaccess` blocks access to
  everything outside `public/` if the document root can't be reconfigured)
- No Docker, no SSH requirement, no `composer install` on the server

## 1. Build locally

Dependencies are installed **locally** and the built `vendor/` directory is uploaded —
never run `composer install` on the shared-hosting server itself:

```bash
composer install --no-dev --optimize-autoloader
```

If you also build the bundled React SPA yourself (see [`development.md`](development.md)):

```bash
pnpm --filter votepit-app run build   # → app/dist/, copy into your deploy artifact
```

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

`config/config.php` is gitignored — never commit it, it holds live secrets.

## 3. Create the database schema

`db/schema.sql` alone is **not sufficient** — it is only the pre-tenancy baseline. Use the
versioned migration runner instead, which applies the baseline and every schema change on
top of it in order:

```bash
php bin/migrate.php --dry-run   # review what would run
php bin/migrate.php             # prompts for a backup confirmation, then applies
```

Against a brand-new, empty database this applies `0000_baseline.sql` (the schema also
captured in `db/schema.sql`) followed by every `NNNN_*` migration under `migrations/` —
end result: the full current schema, including account/tenancy tables, invites, API
tokens, blocked users, etc. See [`operations.md`](operations.md#migrations) for the runner's
mechanics and safety guarantees.

## 4. Point the web root at `public/`

Configure your webserver's document root to `public/`. If your hosting doesn't let you
choose a document root below the repo root, the bundled `public/.htaccess` +
`.htaccess` at the repo root block direct access to everything outside `public/` instead
(source, config, migrations, vendor) — verify this works for your host before going live,
since `.htaccess` behavior depends on the webserver having `AllowOverride` enabled for the
relevant directives.

## 5. Deploy (shared hosting)

Upload the built folder — `public/`, `src/`, `vendor/`, `config/config.php`,
`migrations/` — over FTPES (or your host's transfer method) into the docroot. There is no
CI/CD pipeline bundled with this package; deploys are a file sync of a locally-built
artifact.

## Upgrading an existing installation

1. **Back up first** — using your own `mysqldump`/hosting-provider backup tooling (see
   [`operations.md`](operations.md#backuprestore) — this package ships no backup tooling of
   its own). Every schema migration requires a fresh backup immediately before running it.
2. Pull/upload the new code (`src/`, `public/`, updated `vendor/` if `composer.lock`
   changed, updated SPA `dist/` if the frontend changed).
3. Run `php bin/migrate.php --dry-run` to see what's pending, then `php bin/migrate.php`
   to apply. Migrations are forward-only — there is no automated rollback; restore from
   the pre-migration backup if something goes wrong.
4. Never hand-edit `0000_baseline.sql` or already-applied migration files — every schema
   change is a new, additively-numbered migration file.

## Product telemetry

This installation sends aggregate, anonymous, cookieless usage signals (e.g. "a board was
created") to Votepit's own Matomo instance, to help prioritize development — no page content,
board/account slugs, IPs (anonymized before storage), or other PII is ever included. This is
**on by default** and can be turned off at any time under **Account → Product telemetry**
(a plain toggle, no re-install/config-file change needed). If your own privacy policy needs
to reflect this, see `Votepit\Telemetry\CommunityTelemetry` for exactly what is sent.

If you additionally configure your **own** `matomo_url`/`matomo_site_id` (see
[`configuration.md`](configuration.md#analytics)) or point the product telemetry at a
different host, review `public/.htaccess`'s `Content-Security-Policy` — it currently only
allow-lists `matomo.silvio-und-maik.de`.

## Multiple boards

One installation serves many boards, addressed by path (`/mobile-app`, `/website`, …).
Boards are created in the admin area; branding (name, slug, accent color, intro) is
configurable per board. This is **not** multi-tenancy — self-host installs run as a single
seeded account, and all boards belong to it (see
[`architecture.md`](architecture.md#tenancy-model)).
