#!/usr/bin/env bash
#
# Votepit — doc gate (protective layer for public content)
#
# Deterministic, purely local check (no LLM, no network → free-tier friendly).
# Runs in pre-commit (MODE=staged) and pre-push (MODE=head). Enforces the
# mechanical invariants for EVERYTHING that ends up in the public repo:
#
#   1. No infra/secret leaks — generic markers (private IPs except in tests,
#      local paths, private-key/access-key patterns) PLUS a project-specific,
#      NOT checked-in blocklist (.githooks/leak-blocklist.local, gitignored)
#      with hosting-provider/host/third-party-project names. Deliberately, NO
#      maintainer-specific names are hardcoded here — otherwise the gate
#      itself would be an information leak.
#   2. Public GitHub markdown (README/SECURITY/…) is English.
#   3. No security marketing (guardrail), no false identity, no placeholders.
#   4. Required files present + not empty; the local blocklist stays untracked.
#
# Semantic freshness (docs<->code drift) is left to the manual votepit-sync-check;
# this gate is the mechanical protective layer, not the drift check.
#
# Bash-3.2-compatible (macOS /bin/bash) — no mapfile/readarray.
#
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
MODE="${1:-staged}"
REF="${2:-HEAD}"

fail=0
err(){ echo "  ✗ $1" >&2; fail=1; }

# --- Generic infra/secret markers (provider-agnostic) ---
HARD_RE='/Users/[A-Za-z]|/home/[a-z][a-z0-9_-]*/|BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}'
# Private IPs: a leak in docs/config, but legitimate fixtures in tests → exempt there.
IP_RE='192\.168\.[0-9]|10\.[0-9]+\.[0-9]+\.[0-9]+|172\.(1[6-9]|2[0-9]|3[01])\.[0-9]'

# --- Project-specific blocklist (NOT checked in, gitignored) ---
# One line = one extended-regex grep term (comments/blank lines with # ignored).
LOCAL_BLOCK="$ROOT/.githooks/leak-blocklist.local"
LOCAL_RE=""
if [ -f "$LOCAL_BLOCK" ]; then
  LOCAL_RE="$(grep -vE '^[[:space:]]*(#|$)' "$LOCAL_BLOCK" | paste -sd '|' - || true)"
fi

# --- English-required files (public GitHub markdown) ---
ENGLISH_FILES="README.md SECURITY.md CONTRIBUTING.md CODE_OF_CONDUCT.md"
# Unambiguously German function words (pure ASCII → no locale/umlaut issues,
# no false positives on English words or 'MIT'). Boundary excludes '-' so a
# hyphenated URL/account slug (e.g. "silvio-und-maik" in a feedback-board
# link) doesn't false-positive — real German prose is space-separated.
GERMAN_RE='(^|[^[:alpha:]-])(und|oder|nicht|werden|wurde|eine|einen|keine|sind|auch|dass|wird|diese|sich|wenn|gehört)([^[:alpha:]-]|$)'

list_files(){
  if [ "$MODE" = "staged" ]; then
    git diff --cached --name-only --diff-filter=ACM
  else
    git ls-tree -r --name-only "$REF"
  fi
}
read_file(){ if [ "$MODE" = "staged" ]; then git show ":$1" 2>/dev/null; else git show "$REF:$1" 2>/dev/null; fi; }
exists(){
  if [ "$MODE" = "staged" ]; then git ls-files --cached --error-unmatch "$1" >/dev/null 2>&1
  else git cat-file -e "$REF:$1" 2>/dev/null; fi
}
is_test(){ case "$1" in tests/*|*/tests/*|test/*|*/test/*|*_test.*|*.test.*|*Test.php|spec/*|*/spec/*) return 0 ;; esac; return 1; }
skip_file(){
  case "$1" in
    .githooks/*) return 0 ;;
    *node_modules/*|*/dist/*|*/.astro/*|vendor/*) return 0 ;;
    *.png|*.jpg|*.jpeg|*.gif|*.ico|*.svg|*.webp|*.woff|*.woff2|*.ttf|*.eot|*.pdf) return 0 ;;
    pnpm-lock.yaml|*/pnpm-lock.yaml|composer.lock|package-lock.json) return 0 ;;
  esac
  return 1
}

# 0) The local blocklist must NEVER be tracked/committed.
if exists ".githooks/leak-blocklist.local"; then
  err ".githooks/leak-blocklist.local is tracked — must stay local/gitignored"
fi

# 1) Infra/secret leak scan across all (scannable) files
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if skip_file "$f"; then continue; fi
  content="$(read_file "$f")"
  if printf '%s' "$content" | LC_ALL=C grep -qE "$HARD_RE"; then
    hit="$(printf '%s' "$content" | LC_ALL=C grep -nE "$HARD_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
    err "$f: infra/secret leak -> $hit"
  fi
  if [ -n "$LOCAL_RE" ] && printf '%s' "$content" | LC_ALL=C grep -qiE "$LOCAL_RE"; then
    hit="$(printf '%s' "$content" | LC_ALL=C grep -niE "$LOCAL_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
    err "$f: blocklist hit (internal details) -> $hit"
  fi
  if ! is_test "$f"; then
    if printf '%s' "$content" | LC_ALL=C grep -qE "$IP_RE"; then
      hit="$(printf '%s' "$content" | LC_ALL=C grep -nE "$IP_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
      err "$f: private IP (infra leak) -> $hit"
    fi
  fi
done < <(list_files)

# 2) Required files present + not empty
for f in README.md SECURITY.md LICENSE; do
  if ! exists "$f"; then err "required file missing: $f"; continue; fi
  if [ -z "$(read_file "$f" | tr -d '[:space:]')" ]; then err "required file empty: $f"; fi
done

# 3) Markdown-specific: English, no security marketing, identity, placeholders
for f in $ENGLISH_FILES; do
  if ! exists "$f"; then continue; fi
  c="$(read_file "$f")"
  if printf '%s' "$c" | grep -qiE 'security[ -]by[ -]design|security-by-default|battle-tested|military-grade|bank-grade|hardened by design'; then
    err "$f: security marketing (guardrail: don't advertise security)"
  fi
  if printf '%s' "$c" | grep -qE 'votepit/votepit'; then
    err "$f: wrong identity 'votepit/votepit' (expected silvio-l/votepit)"
  fi
  if printf '%s' "$c" | grep -qE 'TODO|FIXME|XXX|PLACEHOLDER|LOREM IPSUM'; then
    err "$f: placeholder (TODO/FIXME/…) in public docs"
  fi
  if printf '%s' "$c" | LC_ALL=C grep -qE "$GERMAN_RE"; then
    err "$f: German language markers — public GitHub markdown must be English"
  fi
done

if [ "$fail" -ne 0 ]; then
  echo "" >&2
  echo "DOC GATE BLOCKED — public content violates invariants (see above)." >&2
  echo "Internal/infra details (provider, hosts, IPs, local paths) must NEVER" >&2
  echo "go into the public repo; GitHub markdown stays English and without security marketing." >&2
  echo "" >&2
  exit 1
fi
exit 0
