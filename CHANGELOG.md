# Changelog

All notable changes to the Votepit Community Edition core are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.4] — 2026-10-27

### Fixed

- The hourly participant digest failed on every run when an extension was configured, so no
  digest mails went out.
- `logs/cron/<job>.log` now records each run's exit code and the job's output; until now the
  files stayed empty.

## [0.1.2] — 2026-10-26

### Fixed

- Plan-gated features of an account that has the platform operator as a member now resolve the same on every path, so uploaded board images are served publicly again (a free-plan account returned 404 for them).

## [0.1.1] — 2026-10-26

### Added

- Operator worklist (`/admin/worklist`): one list across all boards of the ideas that wait
  for a team reply, for moderation or for a decision, with filters, keyset pagination and a
  navigation badge. Each row offers *Answer*, *Decide*, *Merge* and *Hide only*. *Decide*
  sets one of the statuses the idea's board allows next (with the board's own labels);
  *Hide only* removes the idea from the list without notifying anyone and keeps voting
  open, until its status changes or a newer comment arrives (migration `0113`). Hiding is
  refused on frozen boards.
- Per-board setting *Require a reason for status changes* (off by default). When on, the
  app, the Agent API and MCP refuse a status change without a reason with `422`
  (`status_reason_required`); the issue-tracker status sync is exempt (migration `0115`).
- One cron entry per installation (`bin/votepit-cron.php`, every minute) drives every
  periodic job.
- Installation kill switches `board_uploads`, `participant_mails` and `vote_reasons`
  (default on), see [`configuration.md`](documentation/configuration.md).
- Participant mails: digest, mail preferences, following ideas and RFC 8058 one-click
  unsubscribe; participant, new-idea and comment mails in all six languages.
- Double-opt-in product-news consent (off unless enabled by the operator).
- A redesigned design system across the app.

### Changed

- Ideas in a status of the categories done, declined or archived are closed for votes by
  default, per idea; board admins can re-open voting per status. Migration `0114` applies
  this to the system statuses of boards that customised their statuses; custom statuses
  keep their setting, and new custom statuses in these categories start closed.
- The weekly owner digest is rebuilt around one task per week with calmer board sections.
- The app shell answers unknown accounts, boards and ideas with HTTP `404`, marks app pages
  `noindex` and gives public board pages a canonical link.

### Fixed

- Mail links carry the account prefix in cloud mode; CLI sends announce the sender domain
  in EHLO.
- Upload previews render under the strict CSP.
- Vote fields no longer clip with enlarged text (200 %): the count grows with its field,
  and on phones the For/Against label gives way to arrow and count when its field is too
  narrow (also on 320 px phones at normal size). Status filters wrap instead of scrolling
  past the screen edge. Board rows on wide screens keep room for the idea title when
  their column is narrow: summary and comments move below title and votes. On phones the
  idea page's result sheet runs exactly edge to edge at every width and text size instead
  of spilling past the screen and scrolling sideways.

### Added (earlier work since 0.1.0)

- Board deadlines: optional time windows for submissions and for voting, on every plan.
  Board admins set them under *Deadlines* in the board settings (dates in their own time
  zone, stored as UTC). The board shows the current phase; outside a window the app and
  the API refuse with `423` (`submissions_closed`, `submissions_not_open_yet`,
  `voting_closed`, `voting_not_open_yet`). Voting additionally follows the status' vote
  flag; comments and reading are never affected. Owners and admins can still add ideas
  in the app while submissions are closed; Agent-API tokens follow the window. Changes
  are audit-logged. The embeddable widget follows the windows like the app; the admin
  import and moderation (approving, merging duplicates) are not bound by them. Boards
  without deadlines are unchanged (migration `0082`).
- Merge duplicate ideas: moderators fold a duplicate into a canonical idea on the same board
  ("Merge into…" on the idea page, with deterministic similar-title suggestions and a title
  search). The duplicate disappears from lists, search and counts, takes no new votes or
  comments, and its old link points to the canonical idea. The canonical idea's score counts
  each voter once (their vote on the canonical idea wins); votes are never copied, so
  "Undo merge" restores both ideas exactly. Voters, commenters and the author of the
  duplicate are notified; the `idea.merged` webhook event fires. Migration `0076`.
- Per-user GDPR self-service on `/profile`, for every user on every plan: "Download my data"
  (JSON across all accounts) and "Delete my account" (email-confirmed, 48h undo window,
  purged by the daily `cleanup-expired-accounts.php` run). Ideas and comments of a deleted
  user stay up as "Deleted user"; their votes are kept but anonymised. Account owners must
  delete their account first. Migration `0074`.
- Comment reactions: small, fixed-set emoji reactions on comments.
- Idea tags: board-scoped tags, assignable to ideas and filterable on the board view.
- OAuth login: sign in with Google or GitHub, additive to magic-link/password/TOTP —
  opt-in via `oauth_providers` config, see [`configuration.md`](documentation/configuration.md).
  Linked accounts are manageable (view/unlink) from `/profile`.
- Board webhooks: per-board outbound HMAC-signed HTTP notifications on idea creation and
  status changes, configurable from the board admin settings, with a delivery log.
- Docker packaging for self-hosting (`Dockerfile`, `docker-compose.yml`).
- Board statuses: every status belongs to one of six fixed categories (open, planned,
  in progress, done, declined, archived). Board admins can rename statuses, hide them,
  close a status for votes or comments, and add custom statuses within a category
  (migration `0073`). APIs, MCP and webhooks keep reporting the category as `status`
  and add `status_key`/`status_label`; boards that keep the defaults are unchanged.
- Weekly owner digest: a weekly e-mail per account owner (admins opt in) with each board's
  new, top, most rejected and most polarising ideas plus the average consensus. Sent by the
  hourly `send-weekly-digest` cron job to the confirmed notification e-mail only, skips empty
  weeks, stays well below the outbound mail-volume alert thresholds, and carries a one-click
  unsubscribe link (`/digest/unsubscribe`). Toggle in `/profile` (migration `0075`).
- Board search: a search field on the board filters ideas by title (`?q=`), combinable
  with the status filter, sort and pagination. Uses the existing `ideas.title` FULLTEXT
  index on MySQL, with a substring fallback for very short words.
- Board import: bring ideas in from a CSV file or a Canny/Featurebase export, with a
  dry-run check and validation report before anything is written (migration `0077`).
  Vote counts are imported as a per-idea baseline; authors are not imported.

### Changed

- Idea vote/comment counts are now stored on the idea (migration `0072`, backfilled on
  migrate) instead of being counted per row on every board list, detail, and roadmap read.

### Fixed

- Duplicate suggestions no longer offer ideas that were removed by moderation.

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
