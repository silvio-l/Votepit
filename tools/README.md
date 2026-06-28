# Quality-Tooling (Frontend + Security)

Drei Werkzeuge sichern die Codequalität des Repositories. Alle sind kostenlos,
aktuell gepflegt und laufen lokal ohne Account.

| Tool | Scope | Zweck | Lauf |
|------|-------|-------|------|
| **Biome** 2.5.1 | `app/`, `web/` | Lint + Format (JS/TS/JSON), ersetzt oxlint | `pnpm lint` · `pnpm format` |
| **Knip** 6.23.0 | `app/`, `web/` | toter Code, ungenutzte Exports/Dependencies | `pnpm knip` |
| **Semgrep** CE | repo-weit (PHP + TS) | Security-Invarianten (siehe unten) | `tools/semgrep-scan.sh` |

**Alles in einem Lauf:** `tools/quality.sh` (Biome + Knip je app/web + Semgrep).
Der PHP-Backend-Gate bleibt separat: `composer qa`.

## Biome

Config je Projekt: `app/biome.json`, `web/biome.json`. Stil: Single-Quote, keine
Semikolons, 2 Spaces, `lineWidth` 100 — passend zum Bestandscode. `preset:
recommended` plus `useHookAtTopLevel`.

Zwei Regeln sind **bewusst** deaktiviert (valide Projekt-Patterns, keine Bugs):

- `complexity/noImportantStyles` — der vorgeschriebene `prefers-reduced-motion`-
  Reset (CONTEXT.md, Designsprache Prinzip 9) braucht `!important`, um
  Komponenten-Animationen zu übersteuern.
- `a11y/useSemanticElements` — `role="list|listitem|group"` auf `div` ist gewählt,
  um die exakte Figma-Optik ohne Listen-/Fieldset-Default-Styling zu halten
  (Design-Parität = HARD RULE). Semantik bleibt über ARIA erhalten.

`*.css` ist ausgenommen (Tailwind v4 `@theme` ist nicht Biome-parsebar);
`app/src/**/*.figma.ts` (Code-Connect-Templates) sind vom Linter ausgenommen.

## Knip

Config: `app/knip.json`, `web/knip.json`. In `app/` sind die Code-Connect-
`*.figma.ts` ignoriert und Dev-Deps ohne Import-Referenz (`@figma/code-connect`,
`@fontsource/*`, `tailwindcss`) als genutzt deklariert; Entry-Points sind die
Komponenten-Bibliothek (`components/index.ts`) und die API-Client-Public-Surface
(`lib/api.ts`).

## Semgrep

`.semgrep/votepit.yml` — lokaler, offline, account-/telemetrie-freier Regelsatz
(Free-Tier-Disziplin). Formalisiert die Sicherheits-Invarianten aus CLAUDE.md:

- **Grep-Gate** — verbotene PHP-Funktionen (`exec`, `shell_exec`, `system`,
  `passthru`, `eval`, `unserialize`, `proc_open`, `popen`, `create_function`).
- **Prepared-Statements-only** — keine SQL-String-Konkatenation in DB-Calls.
- **Plaintext-Invariante** — `dangerouslySetInnerHTML` nur nach Review.

Voraussetzung: `brew install semgrep`. Für tiefere Scans lassen sich ad hoc
Registry-Packs ergänzen (`--config p/php p/typescript p/react`).
