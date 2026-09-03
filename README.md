<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="public/assets/brand/votepit-lockup-community-dark.png">
  <img alt="Votepit Community" src="public/assets/brand/votepit-lockup-community-light.png" width="360">
</picture>

**The self-hosted feature-voting board with real up- _and_ down-votes.**

[votepit.com](https://votepit.com) · [Documentation](https://votepit.com/docs) · [MIT License](LICENSE)

</div>

---

Votepit Community Edition is a self-hosted **feature-voting board** with genuine **up- _and_ down-votes**. It runs on classic PHP/MySQL shared hosting, with no Docker or extra runtime. One installation serves many boards (one board per project). Sign in without a password via magic link, or optionally use a password and TOTP-based two-factor authentication.

> **Status: functional, tenancy-aware core**, covered by 1210+ tests (`composer qa`). Full PSR-15 middleware pipeline (security headers, rate limiting, session, auth, CSRF, authorization), magic-link auth, board-scoped voting/ideas/admin, account-scoped data access (self-host runs as a single seeded account — nothing changes for you), pseudonymized identity (emails are never stored in plaintext, only as an HMAC), roles & invitations for multi-user accounts (owner/moderator), self-service data export and account deletion (GDPR Art. 15/17/20), and a token-secured Agent API/MCP endpoint. This package is complete and free-standing on its own — every account runs on an unlimited plan, with no payment or plan logic anywhere in the code. The same tenancy layer also lets a separate, privately hosted package plug in through a documented extension point (see [`documentation/configuration.md`](documentation/configuration.md#extensions-optional)) without touching this codebase; self-host installs never load one.

## Why

Votepit gives you genuine up- _and_ down-votes on classic PHP/MySQL shared hosting — no Docker, no extra runtime. It runs on the webspace you already have, deploys over FTPES, and is free and MIT-licensed.

## Features

- Public boards per project, sorted by **Top** (score) and **Newest**, filterable by status, with a dedicated **roadmap view**
- **Up/down voting**, exactly one vote per idea per user — enforced server-side via a `UNIQUE` constraint
- Passwordless **magic-link auth** (email), persistent session, rate limiting — with an optional password and TOTP-based 2FA any user can self-enable in their profile
- Submit, edit, and withdraw ideas; comments
- **Duplicate detection as you type**: MySQL FULLTEXT recall + Jaro–Winkler reranking, no LLM or external service
- Admin moderation: set status, pin, moderate others' posts, block users, create boards
- **In-app notification inbox**: per-account notifications plus platform-wide broadcasts, no email digest involved
- **User-submitted support requests and an admin-managed FAQ**, both board-account-scoped
- **Multi-board** from a single installation (`/{board-slug}/…`)
- **Roles & invitations**: owners can invite moderators by email, manage members and roles
- **Profile management**: avatar, display name, and up to four fixed social links per user
- **Self-service account data**: export your data (JSON/CSV) or delete your account with a 48h undo window — no admin needed
- Per-board SMTP override for installations that want a dedicated sender per project, on top of the global mailer
- **Agent API & MCP**: board-scoped, rate-limited REST API and an MCP endpoint for AI agents, secured by admin-issued API tokens

Not included: an embeddable widget, OAuth login, email notifications (the notification inbox above is in-app only), or realtime updates.

## Requirements

- PHP **8.2+** with `pdo`, `mbstring`, `intl`
- MySQL **5.7+** / MariaDB **10.0+** (InnoDB FULLTEXT for duplicate search; a PHP fallback without FULLTEXT is included)
- SMTP access for sending magic links
- No Docker, no mandatory SSH, no `composer install` on the server

## Tech

Votepit uses a lean stack of established components: [Slim 4](https://www.slimframework.com/) (PSR-15 middleware pipeline, JSON API), selected [Symfony components](https://symfony.com/components) (Validator, Mailer), and [Doctrine DBAL](https://www.doctrine-project.org/projects/doctrine-dbal.html) (prepared statements only). The frontend is a separate React SPA (`app/`). It deliberately avoids a full-stack framework to remain shared-hosting-friendly while building on actively maintained, audited components.

## Installation

1. Clone the repo and build dependencies **locally**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
   `vendor/` is uploaded with the build artifact over FTPES (it is not committed to the repo).
2. Create the configuration:
   ```bash
   cp config/config.example.php config/config.php
   ```
   In `config/config.php`, set the database access, SMTP, `app_url`, `admin_emails`, and an `app_key` (`php -r "echo bin2hex(random_bytes(32));"`).
3. Create the schema with the versioned migration runner (`db/schema.sql` alone is only a
   pre-tenancy baseline and is not sufficient on its own — see `db/README.md`):
   ```bash
   php bin/migrate.php
   ```
4. Point the web root at `public/`. If the web root is not configurable, the bundled `.htaccess` protects the paths outside `public/`.

Full step-by-step reference, including upgrades: [`documentation/installation.md`](documentation/installation.md).
See [`documentation/`](documentation/README.md) for the complete technical documentation
(architecture, configuration reference, REST API, MCP server, operations, development,
troubleshooting).

## Deployment (shared hosting)

Build locally including `vendor/`, then upload the finished folder over **FTPES** into the docroot. No Docker, no server-side Composer.

## Multiple boards

One installation serves many boards, each addressed by a path (`/mobile-app`, `/website`, `/api`, …). Create boards in the admin area and configure their branding (name, slug, accent color, intro) individually.

## Security

Found a vulnerability? Please see [`SECURITY.md`](SECURITY.md) for coordinated disclosure.

## License

[MIT](LICENSE) — © 2026 Silvio Lindstedt.
