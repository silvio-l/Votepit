#!/usr/bin/env bash
#
# Votepit — aggregierter Quality-Gate fuer das gesamte Repository.
#
# Faehrt die drei Frontend-/Security-Werkzeuge in einem Lauf:
#   1. Biome  (Lint + Format-Check)   je app/, web/ und packages/ui
#   2. Knip   (toter Code / unused deps) je app/ und web/
#   3. Semgrep (Security-Invarianten)  repo-weit (PHP + TS)
#
# Der PHP-Backend-Gate (PHPStan/CS-Fixer/Rector/PHPUnit) lebt separat unter
# `composer qa` — bewusst getrennt, weil er PHP/Composer voraussetzt.
#
# Workspace-nativ: pnpm --filter <pkg> (das `cd <pkg> && pnpm run`-Muster bricht
# unter dem pnpm-Workspace am Pre-Run-Deps-Check, daher --filter vom Root).
#
# Exit != 0, sobald ein Gate fehlschlaegt.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "▶ app/ — Biome"
pnpm --filter votepit-app run --silent lint
echo "▶ app/ — Knip"
pnpm --filter votepit-app run --silent knip

echo "▶ web/ — Biome"
pnpm --filter votepit-web run --silent lint
echo "▶ web/ — Knip"
pnpm --filter votepit-web run --silent knip

echo "▶ packages/ui — Biome"
( cd "$ROOT/packages/ui" && npx --no-install biome check src )

echo "▶ Semgrep — repo-weit (PHP + TS)"
"$ROOT/tools/semgrep-scan.sh"

echo "✅ Alle Frontend-/Security-Quality-Gates gruen."
echo "   (PHP-Backend separat pruefen: composer qa)"
