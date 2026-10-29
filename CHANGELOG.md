# Changelog

All notable changes to the Votepit Community Edition core are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.8] — 2026-10-29

### Added

- Community self-update: `php bin/update.php` (`--check`, `--version`, `--yes`) installs a published
  release after verifying its checksum and its minisign signature against the two release keys built
  into the code. It asks for your own backup first, puts maintenance mode on during the code swap and the
  migrations, and restores the previous code if a migration fails. See [installation](documentation/installation.md#community-releases-php-binupdatephp).
- Release signing keys (minisign): primary `RWQsaiCVDRZOadLcM2iNQN1YATwk9hMbe/HyE1VqjokzaGAfjyJrgtU9`,
  emergency `RWQDrVX41M+OHZMrm7eEZXckCAxXKanUzwWGRebv3myprPFEdud2MVz6`. Verify a download yourself with
  `minisign -Vm votepit-community-<version>.zip -P <key>`.
- Daily update check (`update_check`, default on, self-host only): a newer stable release shows an operator
  banner and sends the account owner one e-mail per version (opt-out per user).
- Daily anonymous version report (`version_report`, default on, self-host only): installed version, PHP
  version and database engine/version under a random installation ID, sent to Votepit's Matomo. Both switches
  can be turned off in `config/config.php`; see [What your installation sends](documentation/installation.md#what-your-installation-sends).

### Security

- `symfony/mime` updated to 7.4.22 (CVE-2026-45070, e-mail header injection via non-token characters in
  MIME parameter names).

## [0.1.7] — 2026-10-28

### Added

- Agent API and MCP can manage a board's branding and images: a new `manage` token grant (above
  `moderate`, effective only while the issuing user is an owner or admin) allows reading and changing
  the branding (`GET`/`PATCH /api/v1/board/branding`, tools `get_board_branding` and
  `update_board_branding`) and uploading or removing the board images (`POST`/`DELETE
  /api/v1/board/assets/{kind}`, tools `upload_board_asset` and `delete_board_asset`). Validation, plan
  limits and the upload terms work exactly as in the app.
- Branding editor: a colour palette picker above the colour fields. Thirteen curated palettes write
  all five colours (primary, secondary, accent and the two dark-mode values) in one click, a random
  palette follows colour-theory harmonies, and locks keep the primary, secondary or accent colour
  while shuffling; Back restores the previous colours. Every palette is checked against the same
  contrast rules as a saved board, so none can produce an illegible or rejected colour. Nothing is
  saved until Save, and a plan only writes the colours it includes.

### Changed

- Hosted-edition plan badges on locked branding now name the Starter plan (the entry paid tier) instead of Lite; the Community Edition is unaffected.
- Board pages in light mode: a board with a cool brand colour (blue, teal or violet) now sits on a
  cool grey page instead of the warm porcelain tone, so the page, its cover and its accents match.
  Warm, grey and unset boards look as before. Nothing to configure; the dark theme is unchanged.

### Fixed

- Embed widget: when the server asks for a bot check, the widget now says so and offers to continue on the board instead of a generic error.
- When an account's plan drops, every change of the downgrade now lands in one place: a board whose
  visibility the new plan does not allow is frozen read-only and keeps its visibility (it is still
  readable by its link, and an upgrade that allows the visibility again lifts the freeze); team members
  beyond the new seat limit are suspended (the owner always keeps access) and are restored
  automatically when the plan allows the seat again. Plan changes from billing, manual grants and
  revokes, and the grant-expiry cron all apply it.
- Agent API tokens lose moderator power as soon as their issuing user stops moderating the account:
  a moderate token falls back to write (new ideas wait for approval again, the pending queue is
  refused), and a manage token falls back to moderate (branding is refused).
- Board icon: a square image from 180 × 180 px is accepted (before, anything under 200 px was
  refused with a generic error). The upload also yields a 180 px home-screen icon, so board pages
  now carry the board's own `apple-touch-icon` instead of the Votepit one; icons uploaded earlier
  stand in until they are uploaded again. A too-small image names the minimum size.

## [0.1.6] — 2026-10-27

### Added

- One-line installer `install.sh` for a fresh Ubuntu/Debian VPS: checks PHP, extensions and the
  database version, downloads the latest release archive (or builds from the repository when no release
  exists), writes `config/config.php` with generated secrets and runs the migrations. Idempotent; prompts
  come from the terminal, and `VOTEPIT_*` variables allow non-interactive use.
- Release archive builder `bin/build-release.sh`: produces `votepit-community-<version>.zip` (production
  `vendor/`, built web app in `public/`, no dev or test files) and a `.sha256` file for upload or a release.
- Root `.htaccess` for hosts whose document root is the repository root: every request is routed into
  `public/`, nothing outside it is served. Pinned by a guard test that also runs against a real local httpd.

### Changed

- Board image uploads (logo, wordmarks, cover, browser-tab icon, share image): the whole upload
  area is now one click and drop target, and a picked or dropped image is saved at once. There is
  no separate upload button anymore; the area shows the current image, a progress state while
  saving, and a remove action.
- Machine translation is a plan feature (`translation`) on top of the installation key and
  the per-board opt-in. The Community Edition allows it on every installation, so nothing
  changes for self-hosters; a tiered extension decides per account.
- README: the feature list now names participant e-mail notifications, guest ideas, machine
  translation, Slack/Discord and Linear/GitHub, which were already part of 0.1.1.
- Product-improvement telemetry is opt-in and off by default. A fresh installation sends nothing
  to any third party. The target is set in `config/config.php` (`telemetry_matomo_url` and
  `telemetry_matomo_site_id`, both empty by default), and an account admin must explicitly accept
  before anything is sent. Installations that ran on the former default were treated as undecided
  and are not opted in anymore. The third-party tracker origin is allowed in the Content Security
  Policy only when a Matomo target is configured.
- The "Powered by" badge, the auth-page return link and the public API/MCP docs links point to the
  public project in the Community Edition. The Cloud edition keeps its marketing-site links.
- Configuration docs and the example config no longer describe the hosted Cloud service. Setup
  wording is neutral, and the operator scripts and migration help name no hosted-service tooling.

### Fixed

- `composer install` requirements are complete: `ext-dom`, `ext-zip`, `ext-iconv` and `pdo_mysql` (needed
  by dompdf and the database driver) are documented next to the existing ones.
- Migration failures print which migrations were applied, which were not attempted, and how to recover
  (restore the backup, or fix the cause and re-run). `bin/migrate.php` exits cleanly with status 1 instead
  of an uncaught exception. The new "Fresh install failed" troubleshooting section explains it.
- Documentation: database and user creation (`utf8mb4`, minimal grants, shared-hosting panels), the
  web-app build step with its target folder (`public/`), the `--yes` flag with its warning, the
  translation rate limits (`translation:idea` 90, `translation:comment` 30 per 10 min, as in the template),
  the migration number gaps (`migrations/README.md`), and a dead link in `db/README.md`.
- The development guide no longer refers to Cloud-only tests, CI workflows or tools that are not part of
  this package. The spacing guard runs in the Community export as well (it scans `cloud/app` only when that
  folder exists).
- Fresh installs on MariaDB no longer stop at migration 0028: migrations drop CHECK
  constraints with `DROP CONSTRAINT`, which MySQL 8.0.19+ and MariaDB both accept.
- The roadmap no longer offers a Gantt view on installations without the Roadmap Gantt
  extension. Without it nobody can set start and target dates, so the view stayed empty.
- Extension suggestions in the submit form (tags) and the merge dialog (duplicate confirmation)
  are shown only when an extension reports them through the bootstrap features
  `ai_tag_suggestions` and `ai_duplicate_confirmation`; a plain install shows no such hints.
- A cron job that recovers is no longer reported to the error tracker as an exception; the recovery
  notice still goes out by mail.

## [0.1.5] — 2026-10-27

### Added

- Board branding: an optional accent colour for highlights (vote counts, badges, pinned ideas and
  the cover pattern), optional dark-mode values for the primary and accent colours, and a logo plate
  behind the round logo (automatic, brand, white or none) with a hideable edge. The accent, per-mode
  and logo plate options are Lite and higher; the Community Edition has them unrestricted.
  Migration `0116` adds the new board columns.

### Changed

- Board colours have fixed roles: the primary colour drives the action colour (the main button,
  links and focus), the accent drives highlights, and the secondary colour is a surface only.
  Titles use the neutral text colour in both themes, so a dark secondary colour no longer turns
  the titles lavender in dark mode. Boards without an accent keep today's highlight colours.
- The masthead button and other accent buttons take the board's primary colour instead of the
  fixed coral, on boards that set a primary colour. Boards without one keep the coral.
- The generated cover (waves and the other patterns) is painted on the board's secondary colour
  and follows the dark theme.
- Plan-gated features now accept only the account's resolved plan, so a stored plan value can no longer be checked directly by mistake. No change in behaviour.

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
