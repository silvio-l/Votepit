#!/usr/bin/env bash
#
# Votepit — PHP backend for local development on http://localhost:8080.
# Native PHP built-in server, no Docker. Serves public/ through
# bin/dev-router.php. The Vite dev server (pnpm -C core/app dev, port 5173)
# proxies /api and other non-HTML requests to this port.
#
# Ctrl+C stops the server.
set -euo pipefail

CORE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-/opt/homebrew/bin/php}"

if [ ! -f "$CORE/config/config.php" ]; then
  echo "config/config.php is missing. Run bin/dev-setup.sh first." >&2
  exit 1
fi

trap 'kill 0' EXIT INT TERM
"$PHP" -S localhost:8080 -t "$CORE/public" "$CORE/bin/dev-router.php" & wait
