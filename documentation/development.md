# Development

## Backend (PHP)

```bash
composer install               # with dev dependencies
composer qa                     # cs (dry-run) → stan → rector (dry-run) → test — full gate
composer test                   # PHPUnit only
composer stan                   # PHPStan (512M memory limit)
composer cs                     # PHP CS Fixer, dry-run
composer cs:fix                 # PHP CS Fixer, apply
composer rector                 # Rector, dry-run
composer rector:fix             # Rector, apply
```

Requires PHP 8.2+ with `ext-intl`, `ext-mbstring`, `ext-pdo`. Key dependencies:
`doctrine/dbal ^4.0`, `slim/slim ^4.13` + `slim/psr7`, `symfony/mailer` +
`symfony/validator ^7.0`, `sentry/sentry ^4.31`. `composer qa` is the gate that must pass
before any change lands — run it locally before pushing.

**Query budgets.** `tests/Performance/QueryBudgetTest.php` counts the SQL statements of
key journeys (board home, idea detail, roadmap, vote, submit idea, appeal inbox,
`/api/v1/ideas`, discovery, admin board list) via a test-only DBAL middleware
(`tests/Support/QueryCounting/`). Each journey must issue the same number of queries for
1 and 25 rows (no N+1) and stay within its `BUDGET_*` constant. If a change legitimately
adds a query, raise the constant in the same commit; if the count grows with rows, batch
the lookup instead.

### Where routes live

`src/Http/AppFactory.php` only wires shared services, the global PSR-15 pipeline and the
**order** in which route modules are registered. The routes themselves live in one small
class per area under `src/Http/Routes/` (`AuthRoutes`, `BoardRoutes`, `IdeaRoutes`,
`CommentRoutes`, `MemberRoutes`, `OperatorRoutes`, `ProfileRoutes`, …), each with a static
`register(App $app, …dependencies)`. To add a route:

- Put it into the module of its area (or a new `*Routes` class called from
  `AppFactory::create()`). Every route carries its own `AuthZMiddleware` (deny by default),
  plus a per-action `RateLimitMiddleware` where it writes or sends mail.
- **Unprefixed (global) routes** (`/login`, `/operator/*`, `/api/v1/*`, …) must be
  registered before the first `{account}`-prefixed route — with `routing_mode: 'cloud'` FastRoute
  rejects a static route registered after a same-shape variable route (see the routing comment in
  `AppFactory::create()`). Account-scoped routes use
  `$accountPrefix . '/…'`, and fixed paths must come before the `/{board}` catch-all.
- The complete route table (method, pattern, name, handler, route middleware incl. AuthZ
  level and rate-limit bucket) is pinned by `tests/Http/RouteTableSnapshotTest.php` (both
  routing modes). After an intended change regenerate the golden files with
  `UPDATE_ROUTE_SNAPSHOTS=1 vendor/bin/phpunit --filter RouteTableSnapshotTest` and review
  the diff. `tests/Support/RouteTable.php` is the reusable route-introspection helper.
- **AuthZ coverage is enforced.** `tests/Http/RouteAuthzCoverageTest.php` (both routing modes)
  walks every route and fails unless it is in one of three classes:
  1. **Guarded** — `AuthZMiddleware` with any level except `anon`. The default; nothing to
     list.
  2. **Public** — `AuthZMiddleware::anon()` *and* an entry in `CORE_ANON_ROUTES`
     (`tests/Support/RouteAuthzCoverage.php`) with a one-line reason why it is genuinely
     public (login, magic link, public board reads, …).
  3. **No AuthZ (explicit exception)** — an entry in `CORE_NO_AUTHZ_ROUTES` naming the
     **substitute guard** the route must carry instead plus a reason. Today: `/api/v1/*`
     (Bearer `ApiTokenAuthMiddleware`, no session) and the public read-only widget feeds
     (`CorsAllowlistMiddleware`, no session).

  Keys are `METHOD /pattern` exactly as registered, with a leading `/{account}` stripped
  (one entry covers self-host and cloud mode). Entries that match no route fail the
  "no stale entries" test, so remove them together with the route. Adding a public route
  is a reviewed decision: add the entry in the same commit and justify it.

The SPA's API client mirrors this: `app/src/lib/api.ts` is a barrel; endpoints live in
per-domain modules under `app/src/lib/api/` (`client.ts` holds `request()`/`ApiError` and the
CSRF token).

## Frontend (`app/`)

```bash
pnpm dev          # Vite dev server, :5173, proxies /api → PHP on :8080
pnpm build        # tsc -b && vite build → dist/
pnpm lint         # Biome (lint + format check)
pnpm test         # vitest run (one-shot)
pnpm test:watch   # vitest, interactive
pnpm preview      # preview the production build
```

Run the PHP backend locally on `:8080` with `bin/dev-server.sh` (see the README section "Local development") alongside
`pnpm dev` for the SPA to have something to proxy to.

### Testing conventions

Stack: Vitest + React Testing Library (jsdom). What gets a unit test: **stateful
interactive widgets** whose logic can break silently — e.g. the vote widget's optimistic
UI state, sort-tab selection, submit-form validation feedback — tested by user-visible
behavior (what renders, what happens on click), never React internals. What does **not**
get a unit test: pure display/layout components (covered by a separate screenshot-based
design gate, not part of this test suite) and page-level routing/data-fetching
(integration concern). Test files are co-located (`*.test.tsx`) or under
`src/__tests__/`; setup lives in `src/test-setup.ts`. Keep the test count intentional —
every test should check behavior a user actually cares about.

## Frontend i18n architecture

Translations live entirely under `app/src/lib/i18n/` (not in `packages/ui`, which stays
i18n-agnostic — see below). 

- **`context.tsx`** — a React context providing `I18nProvider` and the `useI18n()` /
  `useT(namespace)` hooks. Six languages (`LANGUAGES`): `de`, `en`, `fr`, `es`, `nl`, `it`.
  The initial language is the stored choice (`localStorage` key `vp_lang`), else the first
  browser language (`navigator.languages`, matched by primary subtag, so `fr-CA` → `fr`)
  that is supported, else English. The `LanguagePicker` component (app bar + auth shell)
  lists every language by its own name and persists the choice.
- **`dictionaries/<namespace>.<lang>.ts`** — one file per page/component namespace and
  language. The `.de.ts` file is the source of truth (a plain object); every other language
  does `import type de from './X.de'` and declares its export `satisfies typeof de`, so
  TypeScript rejects a missing or extra key at compile time, and `src/__tests__/i18n.test.tsx`
  fails if a namespace lacks a file for any language.
- **Bundling** — `de`/`en` are aggregated eagerly via `import.meta.glob(..., { eager: true })`
  into the main bundle; `fr`/`es`/`nl`/`it` are aggregated by `lazy/<lang>.ts` and loaded as
  one chunk per language on first use (until it lands, `t()` serves English). There is no
  manually maintained index to keep in sync.
- **Fallback** — a key missing at runtime resolves `<current language>` → `en` → `de`.
  SPA extensions (`extensions/types.ts`) must ship `de` + `en` (`Localized<T>`) and may ship
  the other languages; anything left out falls back to English (`pickLanguage()` for labels).
- **`common.<lang>.ts`** — shared keys used by multiple pages (header, status labels,
  sort/pagination, common actions).
- Each page owns its own namespace (e.g. `boardPage`, `ideaDetailPage`, `roadmapPage`,
  `adminPage`, …) and calls `useT('<namespace>')` in its components.
- **`@votepit/ui`** components (`Header`, `StatusBadge`, `Pagination`, …) stay
  deliberately decoupled from this system: they take optional i18n **override props** with
  German defaults, and `app/` supplies translated strings through them (see
  `LocalizedHeader.tsx` for the wrapper pattern around `Header`).

Adding a new translated string: add the key to the relevant `.de.ts` dictionary first, then
add the matching key+value to **all five other files** of that namespace (`.en.ts`, `.fr.ts`,
`.es.ts`, `.nl.ts`, `.it.ts`) — the `satisfies typeof de` check fails the build if one is
missing — then consume it via `useT(namespace)` in the component. Adding a new namespace
means creating all six files. The `fr`/`es`/`nl`/`it` dictionaries were machine-drafted and
carry a `// machine-drafted, needs native review` header; keep that header on files whose new
strings have not been reviewed by a native speaker. Tone: informal address where the language
convention allows it (de "du", es "tú", nl "je", it "tu"), French uses "vous".

Server-generated text (transactional e-mails, in-app notification titles/bodies) is English
only today — there is no per-user language on the server. In-app notifications, comments and
ideas can be machine-translated on demand through the translation feature instead.


## Quality gates

- **Backend:** `composer qa` (CS Fixer, PHPStan, Rector, PHPUnit). Run it before every change you
  propose.
- **Frontend (from `core/`):** `pnpm --filter votepit-app run lint`, `… run knip`, `… test`,
  `… run build`. `tools/quality.sh` runs the frontend lint, Knip, the design-token check and
  Semgrep in one pass (Semgrep must be installed: `brew install semgrep`).
- **Local tests against a real Apache** (`.htaccess` behaviour) run inside `composer test` when `httpd`
  with the needed modules is installed; they are skipped otherwise.

Release archive (maintainers): `bash bin/build-release.sh` produces the upload-ready
`build/votepit-community-<version>.zip` and its `.sha256`. Publish them as a GitHub release asset
by hand, from the repository that holds this `core/` tree, after bumping `version` in `composer.json`
and `app/package.json` and the CHANGELOG:

```bash
gh release create v<version> build/votepit-community-<version>.zip build/votepit-community-<version>.zip.sha256 \
  --repo silvio-l/votepit --title "Votepit Community v<version>" --notes "Changes: see CHANGELOG.md"
```

The one-line installer (`install.sh`) picks up the latest release automatically; without a release it
builds from the repository. Nothing here runs in CI.
