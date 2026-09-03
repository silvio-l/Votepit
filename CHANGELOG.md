# Changelog

All notable changes to the Votepit Community Edition core are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] — 2026-08-31

Initial tenancy-aware Community Edition release. Self-host runs as a single seeded
account — nothing in this list requires multi-tenancy to use.

### Added

- Multi-board feature-voting: submit, upvote/downvote, and withdraw ideas.
- Magic-link (passwordless) authentication with pseudonymized identity — emails are
  never stored in plaintext, only as an HMAC (ADR 0002).
- Full PSR-15 middleware pipeline: security headers, rate limiting, session, auth,
  CSRF, and authorization on every route.
- Board-scoped admin tooling: board creation and editing, idea status/pin toggles,
  per-board and account-wide user blocking.
- Comment CRUD on ideas: create, read, and admin moderation (delete), plaintext-only
  per the shared-origin invariant.
- As-you-type duplicate-idea detection on submission (MySQL FULLTEXT recall +
  Jaro–Winkler rerank, fully local — no external service).
- Roles & invitations: owners can invite members by email (hashed, expiring
  invite tokens), manage pending invites, and remove members.
- Agent API: board-scoped, hashed, revocable API tokens; rate-limited REST endpoints
  (`/api/v1/board`, `/api/v1/ideas`) for programmatic board/idea access; an MCP
  (Model Context Protocol) JSON-RPC resource wrapper (`/api/v1/mcp`) exposing the
  same capability set for AI-agent consumption.
- Public board roadmap view.
- Versioned, forward-only database migrations runner.
- Tenancy-aware account layer (`accounts`, `account_members`, `boards.account_id`)
  with structurally enforced account-scoping — the foundation that lets this same
  codebase run both self-hosted (one account) and as a hosted, multi-account
  deployment.
