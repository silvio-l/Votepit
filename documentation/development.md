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

## Frontend (`app/`)

```bash
pnpm dev          # Vite dev server, :5173, proxies /api → PHP on :8080
pnpm build        # tsc -b && vite build → dist/
pnpm lint         # oxlint
pnpm test         # vitest run (one-shot)
pnpm test:watch   # vitest, interactive
pnpm preview      # preview the production build
```

Run the PHP backend locally on `:8080` (e.g. `php -S localhost:8080 -t public`) alongside
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
i18n-agnostic — see below). This is the same code path whether the SPA runs self-hosted or
as a Cloud tenant.

- **`context.tsx`** — a React context providing `I18nProvider` and the `useI18n()` /
  `useT(namespace)` hooks. Two languages: `'de'` (default) and `'en'`. The active language
  persists in `localStorage` under the key `vp_lang`.
- **`dictionaries/<namespace>.de.ts` + `.en.ts`** — one file pair per page/component
  namespace, auto-aggregated via `import.meta.glob('./dictionaries/*.de.ts', { eager:
  true })` — there is no manually maintained index to keep in sync. The `.de.ts` file is
  the source of truth (a plain object); the `.en.ts` file does
  `import type de from './X.de'` and declares its export `satisfies typeof de`, so
  TypeScript rejects an incomplete translation at compile time.
- **`common.de.ts` / `common.en.ts`** — shared keys used by multiple pages (header, status
  labels, sort/pagination, common actions).
- **`voteMessages.ts`** — a special case for the vote-widget's toast/slogan strings: an
  array per language rather than a `t()`-style string.
- Each page owns its own namespace (e.g. `boardPage`, `ideaDetailPage`, `roadmapPage`,
  `adminPage`, …) and calls `useT('<namespace>')` in its components.
- **`@votepit/ui`** components (`Header`, `StatusBadge`, `Pagination`, `VoteWidget`, …) stay
  deliberately decoupled from this system: they take optional i18n **override props** with
  German defaults, and `app/` supplies translated strings through them (see
  `LocalizedHeader.tsx` for the wrapper pattern around `Header`).

Adding a new translated string: add the key to the relevant `.de.ts` dictionary first, add
the matching key+value to the `.en.ts` file (the `satisfies typeof de` check will fail the
build if you forget), then consume it via `useT(namespace)` in the component.

`web/` (the separate Astro marketing site) has its **own, independent** i18n setup
(`web/src/i18n/{de,en}.ts`) — a different, unrelated system, not shared with `app/`.

## Code quality gate (CI)

`.github/workflows/ci.yml` runs on every push to `dev` and PR against `dev`/`main`:

- `semgrep` — `p/typescript`, `p/secrets`, `p/nodejs`, `p/command-injection`, `p/php`,
  `p/security-audit`, plus the repo's own `.semgrep.yml`, across the whole repo.
- `core-qa` — PHP 8.2, `composer install`, `composer qa`, `composer audit`, plus
  `./tools/votepit-sync-check.sh` (verifies `core/` stays self-contained — its own
  `composer.json`, `.ossallowlist`, `.githooks/pre-commit`, `.githooks/pre-push` all exist
  — and that `core/README.md`'s "covered by N+ tests" claim stays within tolerance of the
  actual `phpunit` count).
- `core-app-qa` — Node 22, `pnpm install --frozen-lockfile` in `core/`, `pnpm audit
  --audit-level high`, Biome lint, Vitest, Knip, Vite build (including `tsc -b`).
- `quality` — same shape for `web/` (Node 22, audit, Biome, Astro typecheck, Knip, Astro
  build).

## Public export

This package is exported to a standalone public repository via
`git subtree split --prefix=core -b core-export` (`tools/export-core.sh`, run from the
private monorepo root — not something you run from within `core/` itself). It never pushes
automatically; it only prints the `git push --force` command, which requires explicit
confirmation before running. If you're reading this from the public export, you're looking
at exactly what that split produced — the private monorepo it comes from adds a commercial
Cloud control-plane on top, but never forks this code.
