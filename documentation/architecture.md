# Architecture

## Components

| Component | What it is | Path |
|---|---|---|
| Backend | PHP, Slim 4 (PSR-15 middleware pipeline), Doctrine DBAL (prepared statements only), selected Symfony components (Validator, Mailer) | `src/`, `public/index.php` |
| Frontend | React SPA, Vite, TypeScript, Tailwind | `app/` |
| Shared UI | `@votepit/ui` — component library consumed by both `app/` (self-host/Cloud SPA) and the marketing site | `packages/ui/` |
| Database | MySQL/MariaDB, one schema per installation | `db/`, `migrations/` |

Deliberately **not** a full-stack framework — chosen to stay shared-hosting-friendly
(no Docker, no server-side `composer install`) while building on actively maintained,
audited components rather than hand-rolled infrastructure.

Minimum supported versions are a **requirement for installers**, not an architecture
detail, and are tracked in one place: [`installation.md#requirements`](installation.md#requirements)
(mirrored in the repo root [`README.md`](../README.md#requirements)).

## Request pipeline

Every HTTP request passes through this middleware chain, outermost first
(`src/Http/AppFactory.php`):

```
Error handling
  → Body parsing
    → Security headers (HSTS, CSP, X-Frame-Options, Permissions-Policy, COOP/CORP, …)
      → Rate limiting (per-IP)
        → Routing
          → Account context (resolves the account from the path in cloud mode)
            → Session
              → Authentication
                → Block check (blocked users)
                  → CSRF (mutating verbs, session-authenticated routes only)
                    → [Route: per-route authorization → handler]
```

Two **independent trust boundaries** exist side by side — a request authenticates
through exactly one of them, there is no fallback between them:

1. **Session cookie** — the normal UI path. Authorization levels, weakest to strongest:
   anonymous → signed-in user → account admin (owner or moderator) → account owner →
   installation-wide admin → **operator** (platform-wide, strictly above account admin,
   settable only via direct database access — no HTTP path grants it).
2. **Bearer token** (`Authorization: Bearer <token>`) — the Agent API under `/api/v1/*`
   (including the MCP endpoint). Verified by `ApiTokenAuthMiddleware`/
   `ApiTokenAuthenticator`, completely separate from sessions. A token grants a set of
   the account's boards (each `read`, `write` or `moderate`) and can never read or write
   another. Its idea write endpoints reuse the session UI's action classes, so
   notifications, webhooks and the chat fan-out behave identically.

Extensions registered via `config.php`'s `extensions` key (see
[`configuration.md`](configuration.md#extensions-optional)) may add further routes with
their own authentication (e.g. a signed webhook from a payment provider); they run
through the same middleware pipeline and can only exempt themselves from CSRF explicitly.

## Tenancy model

The database schema is **tenancy-aware**: every board belongs to an `account`, and
`account_members(account_id, user_id, role)` maps users to accounts with `role ∈ {owner,
moderator}`. A self-hosted install runs with exactly one seeded account — nothing in the
UI or API changes for a self-hoster, the extra layer is simply invisible at
`routing_mode: self-host`. `config.php`'s `routing_mode` key switches between:

- `'self-host'` — one account, paths are `/{board-slug}/...`.
- `'cloud'` — many accounts, paths are `/{account-slug}/{board-slug}/...` (for hosted,
  multi-account deployments; not relevant to a self-host install).

"Multiple boards" (one installation, many boards under different slugs) is a **separate,
older** feature from multi-tenancy — self-host installs have always supported many boards
under one account; tenancy is what makes multiple *accounts* possible in the same schema.

## Plan, operator and role gating

- **Plan:** resolve the enforced plan only with `AccountPlanResolver::forAccount()` (pass an
  already-loaded accounts row as the third argument to save a read). It returns a `ResolvedPlan`,
  and every `PlanPolicy` gate takes one, so PHPStan rejects a raw stored plan string at a gate.
  Billing and admin decisions on the stored plan use `AccountPlanResolver::storedPlan()` (no
  operator bypass, allow-listed to billing, admin and new-account signup code). Never read `accounts.plan` for a
  gating decision, never call `EffectivePlan::resolve()` directly, and build a `ResolvedPlan`
  in tests only through `Votepit\Tests\Support\ResolvedPlanFactory`.
- **Operator:** read the platform operator flag only with `PlatformRoles::isOperator()`.
- **Roles:** compare membership roles only with `AccountRoles` (`canAdminister`, `canModerate`,
  `isOwner`).
- These rules are enforced by `tests/Architecture/CentralGatingGuardTest.php`, which runs in
  `composer qa`. Each allow-listed exception there states why it is legitimate.

## Identity & pseudonymization

Email addresses are **never stored in plaintext** as the identity key. Sign-in computes
`HMAC-SHA256(normalized_email, identity_server_key)` and looks up/creates a user by that
hash; the plaintext address is used transiently to send the magic link and then discarded.
`admin_emails` matching in `config.php` is compared the same way. This holds for self-host
installs too — it's not a Cloud-only measure (ADR 0002). Practical consequence: there is no
way to proactively email your voters as a group through the identity key.

Two optional, separate fields hold a plaintext address, each only after the user opted in
and proved control of the mailbox: `users.notification_email` (e-mail notifications the
user switched on, double opt-in) and `users.marketing_email` (product news, only when the
installation sets `marketing_consent => true`; off by default, so self-host installs never
fill it). Neither is used for sign-in. The notification address has its own remove action,
the product-news address is cleared on withdrawal; both are part of the data export and
account deletion. `identity_server_key` is therefore operationally critical — losing
it invalidates every existing identity; it should be backed up separately from (and with at
least the same rigor as) the database itself.

## Data model (selected entities)

- `accounts` / `account_members` — tenancy + roles (`owner`, `moderator`).
- `boards` — one per project/feedback channel, `account_id`-scoped, slug-addressed,
  branding (name, accent color, intro, visibility — fields gated per `PlanPolicy::ALL_BRANDING_FIELDS`,
  unrestricted by Community's own default policy).
- `users` — pseudonymized identity (`email_hmac`, not plaintext email), `is_admin`,
  `is_operator`.
- `ideas` / `votes` / `comments` — one vote per idea per user, enforced by a database
  `UNIQUE` constraint, not just application logic.
- `invites` / `blocked_users` — role invitations and per-account-or-board user blocking.
- `api_tokens` / `api_token_boards` — Agent API/MCP credentials, account-scoped with a
  read/write/moderate grant per board, granting a settable set of the account's boards (stored as SHA-256
  hashes only).
- `rate_limits` — fixed-window rate-limit buckets shared by every limited action.
- `idea_imports` — one row per applied board import (CSV, Canny or Featurebase export),
  with a per-board idempotency key. Imported ideas carry `import_id` and an
  `imported_vote_count` baseline (it seeds `score_cache`; no `votes` rows or users are
  created for it, and author names/emails from the file are never stored).

Extensions ship their own migrations for their own tables; nothing under `migrations/`
belongs to anything but the Community Edition.

Full column-level detail is versioned as migrations under `migrations/`, not restated
here — see [`operations.md`](operations.md#migrations) for how to read the current schema
and why `db/schema.sql` alone is insufficient.

## Frontend

`app/` is a React 19 SPA (Vite, TypeScript, Tailwind) that talks to the backend over the
same-origin JSON API consumed by the browser session (not the Bearer-token Agent API,
which is for external integrations — see [`api-reference.md`](api-reference.md)). It shares
a component library, `@votepit/ui` (`packages/ui/`), with the marketing site. See
[`development.md`](development.md) for the local dev workflow, testing conventions, and the
i18n architecture.

## Duplicate detection

Submitting an idea title triggers a duplicate search combining a MySQL FULLTEXT recall
pass with Jaro–Winkler string-similarity reranking (`DuplicateDetectionService`,
`JaroWinklerSimilarity`) — entirely local, no LLM or external service involved. A PHP
fallback without FULLTEXT is used automatically if the MySQL/MariaDB version or table
engine doesn't support it.

## Board import

Board admins can import ideas from a CSV file (columns `title`, `body`, `status`,
`created_at`, `votes`, `tags`; only `title` is required) or from a Canny or Featurebase
posts export (`POST /admin/boards/{slug}/import`, in the board's admin settings). The
upload is untrusted input: at most 2 MiB and 1,000 rows, UTF-8 only, strictly parsed
(`Votepit\Import\CsvTable`), and reduced to plaintext — Featurebase's HTML content is
converted to text, nothing is stored as markup. Each source format is a pure parser
(`Votepit\Import\ImportAdapter`) into the same internal row model, which has no author
field: imported ideas are attributed to the importing admin, and imported vote counts
become a per-idea baseline rather than votes. Every request is a dry run with a
validation report unless it explicitly sets `dry_run: false`; an applied import runs in
one transaction and is idempotent per board and client-chosen key. Rows whose title
already exists on the board are skipped. Import is available on every plan.
