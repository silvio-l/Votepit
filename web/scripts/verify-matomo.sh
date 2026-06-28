#!/usr/bin/env bash
# Verifies that the Matomo cookieless snippet is present in the prod build output.
# Run after: pnpm build
# Usage: bash web/scripts/verify-matomo.sh
set -euo pipefail

DIST="$(cd "$(dirname "$0")/.." && pwd)/dist"
TARGETS=("$DIST/index.html" "$DIST/de/index.html")
PATTERNS=(
  "window._paq"
  "disableCookies"
  '"setSiteId", "6"'
  "matomo.silvio-und-maik.de/matomo.js"
)
NO_THIRD_PARTY=(
  "google-analytics"
  "googletagmanager"
  "gtag"
)
# FORBIDDEN _paq methods — presence FAILS the gate.
# setAnonymizeIp is NOT a valid Matomo 5 JS API method. Calling
# _paq.push(["setAnonymizeIp", true]) throws a TypeError that aborts the _paq
# queue BEFORE trackPageView runs → ZERO real visits get tracked (proven via a
# headless-browser canary: with the line → no 204 hit, without it → 204 hit).
# It is a total tracking outage, NOT a "harmless no-op" or a "valid guard".
# IP anonymisation is configured SERVER-SIDE in Matomo; never send it client-side.
# (setDoNotTrack is also absent on purpose — cookieless tracking needs no consent
# signal, so emitting it would be misleading.)
FORBIDDEN=(
  "setAnonymizeIp"
  "setDoNotTrack"
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
      echo "  FAIL: queue-breaking/forbidden _paq method present: '$pattern' — see header note" >&2
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
