#!/usr/bin/env bash
#
# Votepit — aggregated quality gate for the whole repository.
#
# Runs the three frontend/security tools in one pass:
#   1. Biome  (lint + format check)   for app/ and packages/ui
#   2. Knip   (dead code / unused deps) for app/
#   3. Semgrep (security invariants)  repo-wide (PHP + TS)
#
# The PHP backend gate (PHPStan/CS-Fixer/Rector/PHPUnit) lives separately
# under `composer qa` — deliberately split off because it requires PHP/Composer.
#
# Workspace-native: pnpm --filter <pkg> (the `cd <pkg> && pnpm run` pattern
# breaks under the pnpm workspace's pre-run deps check, hence --filter from root).
#
# Exit != 0 as soon as one gate fails.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "▶ app/ — Biome"
pnpm --filter votepit-app run --silent lint
echo "▶ app/ — Knip"
pnpm --filter votepit-app run --silent knip

echo "▶ packages/ui — Biome"
( cd "$ROOT/packages/ui" && npx --no-install biome check src )

echo "▶ Semgrep — repo-wide (PHP + TS)"
"$ROOT/tools/semgrep-scan.sh"

echo "✅ All frontend/security quality gates green."
echo "   (check PHP backend separately: composer qa)"
