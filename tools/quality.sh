#!/usr/bin/env bash
#
# Votepit — aggregierter Quality-Gate fuer das gesamte Repository.
#
# Faehrt die drei Frontend-/Security-Werkzeuge in einem Lauf:
#   1. Biome  (Lint + Format-Check)   je app/ und web/
#   2. Knip   (toter Code / unused deps) je app/ und web/
#   3. Semgrep (Security-Invarianten)  repo-weit (PHP + TS)
#
# Der PHP-Backend-Gate (PHPStan/CS-Fixer/Rector/PHPUnit) lebt separat unter
# `composer qa` — bewusst getrennt, weil er PHP/Composer voraussetzt.
#
# Exit != 0, sobald ein Gate fehlschlaegt.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "▶ app/ — Biome"
( cd "$ROOT/app" && pnpm run --silent lint )
echo "▶ app/ — Knip"
( cd "$ROOT/app" && pnpm run --silent knip )

echo "▶ web/ — Biome"
( cd "$ROOT/web" && pnpm run --silent lint )
echo "▶ web/ — Knip"
( cd "$ROOT/web" && pnpm run --silent knip )

echo "▶ Semgrep — repo-weit (PHP + TS)"
"$ROOT/tools/semgrep-scan.sh"

echo "✅ Alle Frontend-/Security-Quality-Gates gruen."
echo "   (PHP-Backend separat pruefen: composer qa)"
