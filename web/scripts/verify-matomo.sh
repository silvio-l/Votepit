#!/usr/bin/env bash
# Verifies that the Matomo cookieless snippet is present in the prod build output.
# Run after: pnpm build
# Usage: bash web/scripts/verify-matomo.sh
set -euo pipefail

DIST="$(cd "$(dirname "$0")/.." && pwd)/dist"
TARGETS=("$DIST/index.html")
PATTERNS=(
  "window._paq"
  "disableCookies"
  '"setSiteId", "6"'
  "matomo.silvio-und-maik.de/matomo.js"
)
# Verbotene Muster: ungültige/queue-brechende oder unerwünschte _paq-Methoden.
FORBIDDEN=(
  "setAnonymizeIp"
  "setDoNotTrack"
)
NO_THIRD_PARTY=(
  "google-analytics"
  "googletagmanager"
  "gtag"
)

OK=true

for file in "${TARGETS[@]}"; do
  if [[ ! -f "$file" ]]; then
    echo "FAIL: $file not found — run pnpm build first" >&2
    OK=false
    continue
  fi

  echo "--- Checking $file ---"

  for pattern in "${PATTERNS[@]}"; do
    if grep -qF "$pattern" "$file"; then
      echo "  OK: '$pattern'"
    else
      echo "  FAIL: '$pattern' not found" >&2
      OK=false
    fi
  done

  for pattern in "${NO_THIRD_PARTY[@]}"; do
    if grep -qF "$pattern" "$file"; then
      echo "  FAIL: third-party tracker found: '$pattern'" >&2
      OK=false
    else
      echo "  OK: no '$pattern'"
    fi
  done

  for pattern in "${FORBIDDEN[@]}"; do
    if grep -qF "$pattern" "$file"; then
      echo "  FAIL: forbidden _paq method found: '$pattern' (would abort the queue / not wanted)" >&2
      OK=false
    else
      echo "  OK: no '$pattern'"
    fi
  done
done

if $OK; then
  echo ""
  echo "All checks passed."
  exit 0
else
  echo ""
  echo "One or more checks FAILED." >&2
  exit 1
fi
