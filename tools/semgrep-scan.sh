#!/usr/bin/env bash
#
# Votepit — Semgrep security scan (local rule set, offline, no account/telemetry).
#
# Enforces the security invariants documented in CLAUDE.md
# (grep gate for forbidden PHP functions, prepared-statements-only,
# dangerouslySetInnerHTML review). Exit != 0 on any finding.
#
# Prerequisite: Semgrep CE installed (`brew install semgrep`). No login,
# no quota — in keeping with free-tier discipline.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

exec semgrep scan \
  --config "$ROOT/.semgrep/votepit.yml" \
  --metrics=off \
  --error \
  --exclude vendor --exclude node_modules --exclude dist \
  "$ROOT/src" "$ROOT/app/src"
