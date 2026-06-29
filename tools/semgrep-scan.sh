#!/usr/bin/env bash
#
# Votepit — Semgrep-Security-Scan (lokaler Regelsatz, offline, ohne Account/Telemetrie).
#
# Setzt die in CLAUDE.md dokumentierten Sicherheits-Invarianten durch
# (Grep-Gate verbotener PHP-Funktionen, Prepared-Statements-only,
# dangerouslySetInnerHTML-Review). Exit != 0 bei jedem Finding.
#
# Voraussetzung: Semgrep CE installiert (`brew install semgrep`). Kein Login,
# kein Quota — passend zur Free-Tier-Disziplin.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

exec semgrep scan \
  --config "$ROOT/.semgrep/votepit.yml" \
  --metrics=off \
  --error \
  --exclude vendor --exclude node_modules --exclude dist \
  "$ROOT/src" "$ROOT/app/src"
