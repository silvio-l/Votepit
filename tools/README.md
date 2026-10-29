# Quality Tooling (Frontend + Security)

Three tools secure the codebase quality of the repository. All are free,
actively maintained, and run locally without an account.

| Tool | Scope | Purpose | Run |
|------|-------|---------|-----|
| **Biome** 2.5.1 | `app/`, `packages/ui` | Lint + format (JS/TS/JSON) | `pnpm lint` · `pnpm format` |
| **Knip** 6.23.0 | `app/` | dead code, unused exports/dependencies | `pnpm knip` |
| **Semgrep** CE | repo-wide (PHP + TS) | security invariants (see below) | `tools/semgrep-scan.sh` |

**Everything in one run:** `tools/quality.sh` (Biome + Knip for app/ and packages/ui, design-token check, Semgrep).
The PHP backend gate stays separate: `composer qa`.

## Biome

Config: `app/biome.json`. Style: single quotes, no
semicolons, 2 spaces, `lineWidth` 100 — matching the existing codebase. `preset:
recommended` plus `useHookAtTopLevel`.

Two rules are **deliberately** disabled (valid project patterns, not bugs):

- `complexity/noImportantStyles` — the mandated `prefers-reduced-motion`
  reset (CONTEXT.md, design language principle 9) needs `!important` to
  override component animations.
- `a11y/useSemanticElements` — `role="list|listitem|group"` on `div` is chosen
  to keep the exact design look without list/fieldset default styling
  (design parity = HARD RULE). Semantics are preserved via ARIA.

`*.css` is excluded (Tailwind v4 `@theme` is not parseable by Biome).

## Knip

Config: `app/knip.json`. Dev deps without an import reference
(`@fontsource/*`, `tailwindcss`) are declared as used; entry points are the
component library (`components/index.ts`) and the API client's public surface
(`lib/api.ts`).

## Semgrep

`.semgrep/votepit.yml` — a local, offline, account-/telemetry-free rule set,
in keeping with free-tier discipline. Formalizes the security invariants from
CLAUDE.md:

- **Grep gate** — forbidden PHP functions (`exec`, `shell_exec`, `system`,
  `passthru`, `eval`, `unserialize`, `proc_open`, `popen`, `create_function`).
  Single exception: the cron runner (`src/Cron/NativeProcessRunner.php`,
  `src/Cron/ProcOpenChildProcessSpawner.php`) may call `proc_open` to isolate each
  job in its own process, and only with an argv array — a companion rule rejects
  any string (shell) form there.
- **Prepared-statements-only** — no SQL string concatenation in DB calls.
- **Plaintext invariant** — `dangerouslySetInnerHTML` only after review.

Prerequisite: `brew install semgrep`. For deeper scans, registry packs can be
added ad hoc (`--config p/php p/typescript p/react`).
