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
| `session_lifetime` | int (seconds) | `2592000` (30 days) | Session cookie/token absolute lifetime (`exp` claim, never extended). |
| `session_inactivity_window` | int (seconds) | `1209600` (14 days) | Rolling inactivity timeout (`lact` claim) — a session unused for longer is rejected even if `session_lifetime` hasn't elapsed. Refreshed automatically on active use. |
| `session_cookie_domain` | string | `''` | Empty = host-only cookie (correct for self-host, where the install is the only origin). A multi-tenant deployment sets this explicitly to its app host, so the cookie is valid across all tenant paths on that one origin. `Secure`/`HttpOnly`/`SameSite` are hardcoded, not configurable. |
| `cors_badge_origins` | string[] | `[]` | Exact `https://host[:port]` origins of a separate web origin that may read `GET /api/v1/session/badge` **with credentials** (the badge shows the visitor's sign-in state, no PII). Empty = CORS off for every origin (default). No paths, wildcards or trailing slashes; an invalid entry fails boot. Example for a separate marketing site: `['https://www.example.com']`. |

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

## OAuth login (Google/GitHub)

```php
'oauth_providers' => [
    'google' => ['client_id' => '', 'client_secret' => ''],
    'github' => ['client_id' => '', 'client_secret' => ''],
],
```

Additive to magic-link/password/TOTP login, never a replacement. Both empty/missing (the
default) means no provider button is shown anywhere and `GET /login/oauth/{provider}/start`
fail-secure 404s — a self-host installation with no configuration here simply never exposes
OAuth login at all. Only the `google`/`github` keys are recognized; a provider is only
considered configured once **both** `client_id` and `client_secret` are non-empty.

To enable a provider:

1. **Google** — [Google Cloud Console](https://console.cloud.google.com/) → APIs & Services →
   Credentials → Create Credentials → OAuth client ID (type: Web application).
2. **GitHub** — [github.com/settings/developers](https://github.com/settings/developers) → OAuth
   Apps → New OAuth App.
3. In either console, register the **authorization callback / redirect URI** exactly as:
   ```
   {app_url}/login/oauth/google/callback
   {app_url}/login/oauth/github/callback
   ```
   (`{app_url}` is this installation's `app_url` config value, e.g. `https://boards.example.com`
   — an exact mismatch is rejected by the provider before Votepit ever sees the request.)
4. Copy the generated client ID and client secret into `oauth_providers.<provider>` above.
   Like every other secret in this file, `config.php` is gitignored — never commit real
   credentials.

No further scope configuration is needed — Votepit only ever requests each provider's minimal
"identify me" scope (OpenID/email-equivalent), never write access to the linked account. A
signed-in user can see which providers are linked to their account and unlink them from
`/profile` at any time; unlinking never risks a lockout, since magic-link sign-in
(`POST /login`) always works for every user regardless of OAuth linkage.

## Machine translation (Google Cloud Translation)

```php
'translation' => [
    'provider' => '',   // '' (default) | 'google'
    'google' => [
        'project_id'          => '',
        'service_account_key' => '',   // absolute path to the service account's JSON key file
        'region'              => 'eu',
    ],
],
```

Lets a signed-in voter view an idea's/comment's content auto-translated into their current
UI language, with an unobtrusive "show original" toggle — never a manual "Translate"
button. **Off by default and fail-secure**: `provider` empty/missing, or a `'google'`
config missing `project_id`/`service_account_key`, disables the feature entirely
(`NullTranslationProvider`) — the translate endpoints then respond `503
translation_unavailable` rather than ever throwing, and every idea/comment stays fully
readable in its original language regardless.

The only provider currently implemented calls **Google Cloud Translation Advanced (v3)**
against its **EU multi-regional endpoint** (`translate-eu.googleapis.com`) — not the
global endpoint, not Basic (v2), for EU data-residency. This means the idea/comment text
being translated is sent to Google as a third-party processor; if you enable this, you
are the data controller for your own installation and take on that same subprocessor
relationship with Google that this decision implies — evaluate it against your own
privacy policy/DPA obligations before turning it on.

To set it up (self-host, own GCP project — this is unrelated to and independent from any
other Votepit installation):

1. Create a GCP project (or reuse an existing one you control) and enable the **Cloud
   Translation API** on it.
2. Create a dedicated service account and grant it exactly `roles/cloudtranslate.user`
   — the least-privilege role for calling Translate; never `roles/owner`/`roles/editor`.
3. Generate a JSON key for that service account and store it **outside the repo/webroot**,
   like every other secret referenced from `config.php`.
4. Set `translation.provider = 'google'` and fill in `project_id` (the GCP project ID),
   `service_account_key` (absolute path to the key file from step 3), and `region`
   (leave as `'eu'` unless you have a specific reason to change it — this is what selects
   the EU-resident endpoint).

```bash
PROJECT_ID="your-project-id"
gcloud services enable translate.googleapis.com --project="$PROJECT_ID"
gcloud iam service-accounts create votepit-translate \
  --project="$PROJECT_ID" --display-name="Votepit Translation"
gcloud projects add-iam-policy-binding "$PROJECT_ID" \
  --member="serviceAccount:votepit-translate@${PROJECT_ID}.iam.gserviceaccount.com" \
  --role="roles/cloudtranslate.user"
gcloud iam service-accounts keys create votepit-translate-key.json \
  --iam-account="votepit-translate@${PROJECT_ID}.iam.gserviceaccount.com" \
  --project="$PROJECT_ID"
```

Google Cloud Translation is a paid API past its free monthly quota — **set a budget alert
(and ideally a hard billing cap/auto-stop) on your GCP project before enabling this in
production**; Votepit itself enforces no spending limit on your behalf, only the request-
level rate limits below. See the `translation:*` buckets (`idea`, `comment`, `board_intro`,
`notification`, `support_message`) under [Rate limits](#rate-limits) to bound worst-case
request volume.

## Anonymous voting

```php
'turnstile' => [
    'site_key'   => '',   // Cloudflare Turnstile site key
    'secret_key' => '',   // Cloudflare Turnstile secret key
],
```

Board owners can allow voting without an account on a per-board basis (off by default —
each board opts in individually in its board settings, `boards.anonymous_voting_enabled`).
An anonymous voter is deduplicated per idea via a purpose-bound, `HttpOnly` cookie (no
device fingerprinting); voting still goes through the same per-IP rate limits as
authenticated voting (`idea:vote`, see [Rate limits](#rate-limits)), and every new
anonymous voter cookie is challenged once via [Cloudflare
Turnstile](https://developers.cloudflare.com/turnstile/) before its first vote counts.
Anonymous voters may optionally leave an email address to be notified when an idea's
status changes — stored encrypted (never in plaintext or as an unsalted hash) and used
for nothing else.

**Set up Turnstile before enabling anonymous voting on a public board.** Exactly like
[Machine translation](#machine-translation-google-cloud-translation) above, this is
**fail-secure-by-absence in the permissive direction**: without `site_key`/`secret_key`
configured, `turnstileConfigured()` is `false` and the challenge step is skipped entirely
rather than blocking voting — anonymous voting still works, but without its bot-abuse
gate. Get a free site/secret key pair from the [Cloudflare Turnstile
dashboard](https://dash.cloudflare.com/?to=/:account/turnstile) and set both values
before opting any board into anonymous voting in production.

## Status change reasons

Board owners and admins can require a reason for every status change of an idea (off by
default, board settings → general, `boards.status_reason_required`). When it is on, a status
change from the web UI, the REST Agent API or MCP without a non-blank reason is refused with
`422 status_reason_required`. Automated issue-tracker sync is exempt, since it has no
moderator to write a reason.

## Ideas without an account

Board owners can also let visitors **submit ideas without an account** (off by default,
board settings → moderation, `boards.anonymous_submission_enabled`; independent of
anonymous voting). Unlike anonymous voting this is **fail-closed**: the switch can only be
turned on while the `turnstile` keys above are configured, and every guest submission is
verified by Turnstile — without a working Turnstile no guest idea is accepted. The
plan policy can gate it too (`PlanPolicy::anonymousSubmissionAllowed()`; always allowed in
the Community Edition).

Turning the switch on raises the board's approval mode from `off` to `anonymous` (only
guest ideas wait for approval); switching guest ideas to "publish without approval" needs
an explicit confirmation. Guest ideas appear as "Guest", may not contain links, run
through the word filter regardless of the board's filter setting, and are capped at 50
waiting guest ideas per board. A purpose-bound, `HttpOnly` cookie (`vp_anon_author`,
180 days, set only after a successful submission) lets the submitting browser list its own
ideas and withdraw them while they wait; the database stores only an account-bound HMAC
of it, which the daily `bin/cleanup-expired-accounts.php` run nulls after 180 days.

The guest buckets in [Rate limits](#rate-limits) (`idea:submit:anon:*`,
`idea:dupes:anon:ip`, `idea:guest-withdraw:token`) are mandatory: they can be tuned, but
`limit => 0` does not switch them off — an unset, zero or invalid value falls back to the
default.

## Installation kill switches

Three optional, installation-wide switches turn a released area off everywhere at once,
without touching any board. Each is a boolean that defaults to `true` (omitted = unchanged
behaviour); set it to `false` in `config/config.php` to switch the area off. Nothing is
deleted, and turning a switch back on restores the area.

| Key | Default | While `false` |
|---|---|---|
| `board_uploads` | `true` | Board logo and cover uploads answer `403 board_uploads_unavailable`. The public image endpoint answers 404 for every stored image (rows and files are kept). Removing an image stays allowed. Generated cover patterns are not uploads and are unaffected. |
| `participant_mails` | `true` | No participant mail is sent or queued: instant follow/comment/status mails, the digests, the new-ideas mail, the operator nudges and, where an extension supplies a plan policy, the plan-limit nudges. The cron jobs still run and record success, with nothing to send. Auth and magic-link mail are not affected. Items queued before the switch went off are not sent until it is back on. |
| `vote_reasons` | `true` | The per-board "reasons for votes" toggle cannot be switched on (`422`, field `vote_reasons_enabled`); reasons are refused like a board with the flag off (`409 vote_reasons_disabled`). The SPA hides the toggle and the reason prompt (bootstrap feature `vote_reasons`). Switching a board off stays possible. |

Any other value than `true`/`false` fails at startup.

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
| `cron_dispatch` | string | `'inline'` | How `bin/votepit-cron.php` runs job workers: `inline` waits for them inside the tick (bounded to ~50 s); `detached` returns at once and lets them outlive the tick. Use `detached` only after the host survival test confirmed it works on your hosting. Unknown values fail at startup. See [`operations.md`](operations.md#scheduled-jobs-cron-manager). |
| `readiness_cron_max_age_minutes` | int | `5` | Maximum age of the cron manager's start heartbeat (`bin/votepit-cron.php`, written at the start of every tick) before `GET /ready` answers 503 — see [`operations.md`](operations.md#health--readiness-probes). Must be a positive integer. |

## Analytics

| Key | Type | Default | Effect |
|---|---|---|---|
| `matomo_url` | string | `''` | Empty (default): no analytics tracker is loaded, `/api/bootstrap` reports `matomo_url: ''`. Set together with `matomo_site_id` to load a cookieless Matomo tracker (`disableCookies`, no consent banner needed) in the SPA, for a Matomo instance you operate. The origin is then allowed in the CSP automatically; `public/.htaccess` must list it too (see [installation](installation.md#product-telemetry)). Your own analytics are separate from the product telemetry below. |
| `matomo_site_id` | string | `''` | The Matomo site ID paired with `matomo_url`. |
| `telemetry_matomo_url` | string | `''` | Target of the optional product-improvement telemetry. Empty (default): telemetry is off, nothing is sent and no consent step is shown. Set together with `telemetry_matomo_site_id` to offer it. Ignored in `routing_mode: cloud`. |
| `telemetry_matomo_site_id` | string | `''` | The Matomo site ID paired with `telemetry_matomo_url`. |

**Product-improvement telemetry** is off unless you configure a target. When configured, it sends only aggregate, anonymous, cookieless usage counters (no PII, IP-anonymized) to that Matomo site, and only after an explicit opt-in by an account admin under `/admin/account` (`accounts.telemetry_opted_in`). Nothing is sent before that decision. Details: [installation](installation.md#product-telemetry).

## Update check and version report

Self-host installations check for a newer Community release once a day and report their version to
Votepit's Matomo once a day. Both are on by default. Both are never active on `routing_mode: cloud`.

| Key | Type | Default | Effect |
|---|---|---|---|
| `update_check` | bool | `true` | Daily lookup of the latest stable GitHub release. A newer version shows the operator banner and, once per version, an e-mail to each account owner (switchable per user under the notification settings). `false`: no lookup, no banner, no e-mail. |
| `version_report` | bool | `true` | Daily anonymous version report (installed version, PHP version, database engine and version) to Votepit's Matomo, under a random installation ID. `false`: nothing is sent. |

Exactly what is sent, and what is never sent: [installation](installation.md#what-your-installation-sends).

## Rate limits

```php
'rate_limits' => [
    'global:ip'       => ['limit' => 300, 'window' => 60],
    'magiclink:email' => ['limit' => 3,   'window' => 3600],
    'magiclink:ip'    => ['limit' => 5,   'window' => 3600],
    'idea:submit'     => ['limit' => 5,   'window' => 3600],
    'idea:vote'       => ['limit' => 60,  'window' => 60],
    'comment:user'    => ['limit' => 10,  'window' => 3600],
    'comment:react'   => ['limit' => 60,  'window' => 60],
    'widget:identify' => ['limit' => 30,  'window' => 60],
    'widget:read'     => ['limit' => 120, 'window' => 60],
    'dupsearch:user'  => ['limit' => 30,  'window' => 60],
    'smtp:test'       => ['limit' => 5,   'window' => 300],
    'invite:send'     => ['limit' => 20,  'window' => 3600],
    'apitoken:read'   => ['limit' => 120, 'window' => 60],
    'apitoken:write'  => ['limit' => 20,  'window' => 3600],
    'translation:idea'    => ['limit' => 90, 'window' => 600],
    'translation:comment' => ['limit' => 30, 'window' => 600],
    'translation:board_intro'     => ['limit' => 30, 'window' => 600],
    'translation:notification'    => ['limit' => 30, 'window' => 600],
    'translation:support_message' => ['limit' => 30, 'window' => 600],
    'board:import'        => ['limit' => 20, 'window' => 3600],
    'issue-tracker:connect' => ['limit' => 10, 'window' => 3600],
    'issue-tracker:create'  => ['limit' => 30, 'window' => 3600],
    'issue-tracker:webhook' => ['limit' => 120, 'window' => 60],
    'oauth:register'        => ['limit' => 10,  'window' => 3600],
    'oauth:register:global' => ['limit' => 200, 'window' => 3600],
    'oauth:token'           => ['limit' => 60,  'window' => 60],
    'oauth:revoke'          => ['limit' => 30,  'window' => 60],
    'oauth:consent'         => ['limit' => 30,  'window' => 3600],
    // Ideas without an account (mandatory, see "Ideas without an account")
    'idea:submit:anon:ip'       => ['limit' => 10,  'window' => 3600], // per IP (IPv6 per /64)
    'idea:submit:anon:token'    => ['limit' => 3,   'window' => 3600], // per guest device cookie
    'idea:submit:anon:board'    => ['limit' => 30,  'window' => 3600], // per board
    'idea:dupes:anon:ip'        => ['limit' => 30,  'window' => 3600], // guest duplicate hints
    'idea:guest-withdraw:token' => ['limit' => 10,  'window' => 3600], // guest withdrawals
],
```

Fixed-window limiter, buckets keyed `<action>:<identity>` and stored in the `rate_limits`
MySQL table. `limit => 0` disables an action entirely (fails every request for that
bucket). The config key is the same string the code looks up (`$config->rateLimit('idea:submit')`
etc.) — don't rename keys without updating the corresponding call site.
`apitoken:read`/`apitoken:write` are also the buckets used by the MCP endpoint (a token's
MCP and REST usage share the same budget — see [`mcp-server.md`](mcp-server.md)).
`widget:identify` (per IP) and `widget:read` (per user) belong to the embeddable widget;
its writes deliberately share `idea:submit`/`idea:vote`/`comment:user` with the board
itself, so embedding a board never raises a visitor's budget — see [`widget.md`](widget.md).
The `translation:*` buckets count per user; `translation:comment`, `translation:notification`
and `translation:support_message` take one hit per request regardless of how many items it
translates. An unlisted bucket falls back to 30 requests per 60 seconds.
`issue-tracker:connect` (per admin) and `issue-tracker:create` (per moderator) cap the
outbound Linear/GitHub API calls; `issue-tracker:webhook` (per IP) guards the inbound
status-sync webhooks before their signature is checked.
`oauth:register` (per IP) and `oauth:register:global` (one bucket for the whole
installation) guard anonymous MCP OAuth client registration; `oauth:token` and
`oauth:revoke` (per IP) the token and revocation endpoints; `oauth:consent` (per user)
the consent decision — see [`mcp-server.md`](mcp-server.md#oauth-sign-in).

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
| `migrationStreams()` | Declare the extension's own migration streams (tracking table => migrations directory) so `GET /ready` reports 503 while one of them has a pending migration. Core's `schema_migrations` or a table declared twice aborts the boot. |
| `reassignUserReferences()` | Re-point the extension's own rows that reference a user to the shared "deleted user" placeholder when a self-deleted user is finally erased (e.g. evidence that must outlive the person). Runs inside the erasure transaction, before the user row is deleted; throwing rolls the erasure back and the user stays (retried on the next cleanup run). Do nothing if no own table references `users`. |

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
