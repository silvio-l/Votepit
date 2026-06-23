<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="public/assets/brand/votepit-lockup-dark.png">
  <img alt="Votepit" src="public/assets/brand/votepit-lockup-light.png" width="320">
</picture>

**The self-hosted feature-voting board with real up- _and_ down-votes.**

[votepit.com](https://votepit.com) · [Documentation](https://votepit.com/docs) · [MIT License](LICENSE)

</div>

---

Votepit is a self-hosted **feature-voting board** with genuine **up- _and_ down-votes** — for classic PHP/MySQL shared hosting, no Docker, no extra runtime. One installation serves many boards (one board per project). Passwordless sign-in via magic link.

> **Status: early initialization.** Implementation starts with the security-foundation sprint (skeleton, routing, config, schema, protection layers).

## Why

Commercial tools (Canny, Featurebase, Nolt, …) usually have **no down-votes** or cap their free tier; the maintained self-hosted OSS options are Docker-based and not suited to shared hosting. Votepit closes the gap: it runs on the webspace you already have, deploys over FTPES, is free, and MIT-licensed.

## Features (MVP goal)

- Public boards per project, sorted by **Top** (score) and **Newest**, filterable by status
- **Up/down voting**, exactly one vote per idea per user — enforced server-side via a `UNIQUE` constraint
- Passwordless **magic-link auth** (email), persistent session, rate limiting
- Submit, edit, and withdraw ideas; comments
- **Duplicate detection as you type**: MySQL FULLTEXT recall + Jaro–Winkler reranking, no LLM or external service
- Admin moderation: set status, pin, moderate others' posts, block users, create boards
- **Multi-board** from a single installation (`/{board-slug}/…`)

Deliberately **not** in the MVP: embed widget, OAuth, email notifications, public API, realtime. See "Out of scope" in the PRD.

## Requirements

- PHP **8.2+** with `pdo`, `mbstring`, `intl`
- MySQL **5.7+** / MariaDB **10.0+** (InnoDB FULLTEXT for duplicate search; a PHP fallback without FULLTEXT is included)
- SMTP access for sending magic links
- No Docker, no mandatory SSH, no `composer install` on the server

## Tech

Built on a lean stack of established components: [Slim 4](https://www.slimframework.com/) (PSR-15 middleware pipeline), [Twig 3](https://twig.symfony.com/) (auto-escaping templates), selected [Symfony components](https://symfony.com/components) (Validator, Mailer), and [Doctrine DBAL](https://www.doctrine-project.org/projects/doctrine-dbal.html) (prepared statements only). Deliberately not a full-stack framework — it stays shared-hosting-friendly and builds on actively maintained, audited components.

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
3. Import the database schema (idempotent setup script — lands in the security-foundation sprint, see `db/`).
4. Point the web root at `public/`. If the web root is not configurable, the bundled `.htaccess` protects the paths outside `public/` (details in the deploy docs).

## Deployment (shared hosting)

Build locally including `vendor/`, then upload the finished folder over **FTPES** into the docroot. No Docker, no server-side Composer.

## Multiple boards

One installation serves many boards, addressed by path (`/mobile-app`, `/website`, `/api`, …). Boards are created in the admin area; branding (name, slug, accent color, intro) is configurable per board.

## Security

Found a vulnerability? Please see [`SECURITY.md`](SECURITY.md) for coordinated disclosure.

## License

[MIT](LICENSE) — © 2026 Silvio Lindstedt.
