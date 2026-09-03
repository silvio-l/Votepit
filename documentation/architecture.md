# Architecture

## Components

| Component | What it is | Path |
|---|---|---|
| Backend | PHP 8.2+, Slim 4 (PSR-15 middleware pipeline), Doctrine DBAL (prepared statements only), selected Symfony components (Validator, Mailer) | `src/`, `public/index.php` |
| Frontend | React 19 SPA, Vite, TypeScript, Tailwind | `app/` |
| Shared UI | `@votepit/ui` — component library consumed by both `app/` (self-host/Cloud SPA) and the marketing site | `packages/ui/` |
| Database | MySQL 5.7+/MariaDB 10.0+, one schema per installation | `db/`, `migrations/` |

Deliberately **not** a full-stack framework — chosen to stay shared-hosting-friendly
(no Docker, no server-side `composer install`) while building on actively maintained,
audited components rather than hand-rolled infrastructure.

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
   `ApiTokenAuthenticator`, completely separate from sessions. A token is bound to exactly
   one board and can never read or write another.

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

## Identity & pseudonymization

Email addresses are **never stored in plaintext** as the identity key. Sign-in computes
`HMAC-SHA256(normalized_email, identity_server_key)` and looks up/creates a user by that
hash; the plaintext address is used transiently to send the magic link and then discarded.
`admin_emails` matching in `config.php` is compared the same way. This holds for self-host
installs too — it's not a Cloud-only measure (ADR 0002). Practical consequence: there is no
way to proactively email your voters as a group, since no plaintext address is retained
anywhere the app controls. `identity_server_key` is therefore operationally critical — losing
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
- `api_tokens` — Agent API/MCP credentials, board-scoped, stored as SHA-256 hashes only.
- `rate_limits` — fixed-window rate-limit buckets shared by every limited action.

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
