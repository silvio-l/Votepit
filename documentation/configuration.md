# Configuration reference

All configuration lives in `config/config.php` (copy of `config/config.example.php`, which
is the authoritative template and always up to date with the code — check it directly if
this document and the template ever disagree). The file returns a plain PHP array; there
is no environment-variable layer for the main app config (the standalone CLI scripts
under `bin/` are the exception — see the end of this document).

## Core

| Key | Type | Default | Notes |
|---|---|---|---|
| `env` | `'prod'\|'dev'` | `'prod'` | `'dev'` shows error details, drops the HSTS `Secure` flag, disables template caching. Never use `'dev'` on a public install. |
| `app_url` | string | `'https://feedback.example.com'` | Public base URL, **no trailing slash**. |
| `app_key` | string | `''` (must be set) | Signs session cookies and magic links. Generate: `php -r "echo bin2hex(random_bytes(32));"`. |
| `identity_server_key` | string | `''` (must be set) | HMAC key for email pseudonymization (ADR 0002) — deliberately separate from `app_key` for independent rotation. Losing this key makes every existing identity unrecoverable. |
| `magic_link_ttl` | int (seconds) | `900` (15 min) | Validity window of a sign-in link. |
| `admin_emails` | string[] | `[]` | Whoever signs in with one of these addresses becomes `is_admin` on first login. |
| `session_lifetime` | int (seconds) | `2592000` (30 days) | Session cookie/token lifetime. |
| `session_cookie_domain` | string | `''` | Empty = host-only cookie (correct for self-host, where the install is the only origin). Cloud sets this explicitly to bind the cookie across all tenant paths on one shared origin. `Secure`/`HttpOnly`/`SameSite` are hardcoded, not configurable. |

## Database

```php
'db' => [
    'host' => 'localhost', 'port' => 3306, 'name' => 'votepit',
    'user' => '', 'pass' => '', 'charset' => 'utf8mb4',
],
```

MySQL/MariaDB, accessed exclusively through prepared statements (Doctrine DBAL) — no
string-concatenated SQL anywhere in the codebase.

## SMTP

```php
'smtp' => [
    'host' => '', 'port' => 587, 'user' => '', 'pass' => '',
    'encryption' => 'tls', // 'tls' | 'ssl'
    'from_email' => 'noreply@example.com', 'from_name' => 'Votepit',
    // Optional DKIM signing (RFC 6376) — both empty (default) disables it,
    // mail sends unsigned exactly as before. When set, every outgoing mail
    // is signed with d=<from_email's domain> (required for DMARC alignment)
    // and s=<dkim_selector>. Generate a keypair once, publish the public
    // half as a DNS TXT record at "<dkim_selector>._domainkey.<domain>",
    // then put only the private key here (PEM, gitignored like the rest of
    // config.php).
    'dkim_private_key' => '', 'dkim_selector' => '',
],
```

Used exclusively for magic-link/invite/notification mail. Verify a working setup with
`php bin/send-test-mail.php you@example.com` before going live (see
[`operations.md`](operations.md)) — there is no in-app fallback if mail delivery is broken,
sign-in will simply fail.

## Routing mode (tenancy)

| Key | Values | Default | Effect |
|---|---|---|---|
| `routing_mode` | `'self-host'\|'cloud'` | `'self-host'` | `'self-host'`: exactly one account, board paths are `/{board}/...`. `'cloud'`: multiple accounts, paths become `/{account}/{board}/...`. |

Self-hosters leave this at `'self-host'`. Setting `'cloud'` requires the built SPA to have
account-prefixed client routes — the boot-time check in `public/index.php`
(`Votepit\SpaCapabilities`) fails loudly with HTTP 500 rather than silently 404ing every
account-scoped page if the SPA build doesn't support it yet.

## Network trust

| Key | Type | Default | Effect |
|---|---|---|---|
| `trust_cloudflare_ip` | bool | `false` | `true` trusts the `CF-Connecting-IP` header for rate limiting/logging instead of `REMOTE_ADDR`. **Only enable this together with an origin lock** that rejects direct traffic not coming from Cloudflare's published IP ranges — otherwise any client can forge the header and bypass IP-based rate limits. |

## Error monitoring

| Key | Type | Default | Effect |
|---|---|---|---|
| `sentry_dsn` | string | `''` | Empty (default, recommended for self-host): `NullErrorReporter`, no outbound telemetry. Set to a real DSN to activate `Votepit\Monitoring\SentryErrorReporter` — uncaught exceptions are additionally reported to Sentry on top of the existing `error_log` logging. |

## Analytics

| Key | Type | Default | Effect |
|---|---|---|---|
| `matomo_url` | string | `''` | Empty (default): no analytics tracker is loaded, `/api/bootstrap` reports `matomo_url: ''`. Set together with `matomo_site_id` to load a cookieless Matomo tracker (`disableCookies`, no consent banner needed) in the SPA — see `core/app/src/lib/analytics.ts`. This is *your own* optional analytics, separate from the Community Edition product telemetry below. |
| `matomo_site_id` | string | `''` | The Matomo site ID paired with `matomo_url`. |

**Community Edition product-improvement telemetry** is a *separate*, non-config-driven mechanism — see `Votepit\Telemetry\CommunityTelemetry`. It sends aggregate, anonymous, cookieless usage signals (no PII, IP-anonymized) to Votepit's own Matomo instance to help prioritize development, and is **on by default** with an easy opt-out toggle under `/admin/account` (`accounts.telemetry_opted_in`). It is inert automatically in `routing_mode: cloud`.

## Rate limits

```php
'rate_limits' => [
    'global:ip'       => ['limit' => 300, 'window' => 60],
    'magiclink:email' => ['limit' => 3,   'window' => 3600],
    'magiclink:ip'    => ['limit' => 5,   'window' => 3600],
    'idea:submit'     => ['limit' => 5,   'window' => 3600],
    'idea:vote'       => ['limit' => 60,  'window' => 60],
    'comment:user'    => ['limit' => 10,  'window' => 3600],
    'dupsearch:user'  => ['limit' => 30,  'window' => 60],
    'smtp:test'       => ['limit' => 5,   'window' => 300],
    'invite:send'     => ['limit' => 20,  'window' => 3600],
    'apitoken:read'   => ['limit' => 120, 'window' => 60],
    'apitoken:write'  => ['limit' => 20,  'window' => 3600],
],
```

Fixed-window limiter, buckets keyed `<action>:<identity>` and stored in the `rate_limits`
MySQL table. `limit => 0` disables an action entirely (fails every request for that
bucket). The config key is the same string the code looks up (`$config->rateLimit('idea:submit')`
etc.) — don't rename keys without updating the corresponding call site.
`apitoken:read`/`apitoken:write` are also the buckets used by the MCP endpoint (a token's
MCP and REST usage share the same budget — see [`mcp-server.md`](mcp-server.md)).

To reset a bucket during manual verification (never for production traffic):

```sql
DELETE FROM rate_limits WHERE bucket LIKE '%magiclink%';
```

## Extensions (optional)

```php
'extensions' => [],
```

The Community Edition is complete on its own: every account is on an unlimited plan, no
plan or payment logic exists in this package. A hosted service built on top of it can
register additional code here — the classes listed must implement
`Votepit\Extension\AppExtension` and be autoloadable when `config.php` is evaluated (the
extension package `require`s its own autoloader from `config.php`). Self-host installs
leave the list empty; there is nothing to configure and nothing changes at runtime.

Everything an extension can influence is enumerated by that interface — anything not
listed is deliberately out of reach:

| Hook | What it may do |
|---|---|
| `register()` | Add its own routes (global or under the account prefix), with core's AuthZ/rate-limit middleware. `ExtensionContext` also hands it the shared `LoginSessionIssuer`, the one sanctioned way to sign a visitor in. |
| `planPolicy()` | Replace the unlimited Community plan policy (at most one extension). |
| `csrfExemptions()` | Exempt a header-authenticated machine endpoint (e.g. a payment webhook) from CSRF. |
| `accountDeletionPrecondition()` | Run a check before an owner-requested account deletion is scheduled. |
| `bootstrapFeatures()` | Add `features` flags to `GET /api/bootstrap` (e.g. legal footer links). |
| `responseHeaders()` | Add static headers to every response (e.g. `X-Robots-Tag`). Core's own security headers are reserved and cannot be overridden. |
| `routeMiddleware()` | Attach middleware to a short, fixed list of core-owned routes (`Votepit\Http\CoreRoute`: `robots.txt`, the mail-sending login/password-reset/invite/SMTP-test endpoints, and the rate-limited idea/vote/comment/duplicate-search endpoints). The middleware sits outermost on the route, so it can refuse the request before core runs or observe core's answer (e.g. a 429). Unknown names, or names whose route does not exist in the current `routing_mode`, abort the boot. |

The SPA has the matching seam (`core/app/src/extensions/types.ts`, resolved through the
`@votepit/app-extensions` alias): extra pages, admin-nav entries, i18n strings, and two
fixed mount points (`slots.appBanner` above every page, `slots.loginFooter` below the
sign-in forms).

Extensions are expected to be pure PHP on top of core's own `vendor/` tree (production
servers run only core's Composer autoloader). That is why `composer.json` carries a few
libraries core itself does not call — currently `dompdf/dompdf`, used by extensions that
render PDF documents — rather than every extension shipping its own dependency tree.

## CLI scripts using environment variables

Unlike the main app, `bin/send-test-mail.php` reads SMTP settings from environment
variables instead of `config.php` (so it can be run against a different mail
configuration without touching the live config):

```
SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_ENCRYPTION, SMTP_FROM_EMAIL, SMTP_FROM_NAME
```

Example values in `config/smtp-test.env.example`. See [`operations.md`](operations.md) for
usage and the other `bin/` scripts, several of which take CLI flags instead
(`--dry-run`, `--out=`, `--target-name=`, …).
