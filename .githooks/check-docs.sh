#!/usr/bin/env bash
#
# Votepit — Doc-Gate (Schutzschicht für öffentliche Inhalte)
#
# Deterministische, rein lokale Prüfung (kein LLM, kein Netz → Free-Tier-konform).
# Läuft in pre-commit (MODE=staged) und pre-push (MODE=head). Erzwingt die
# mechanischen Invarianten für ALLES, was ins öffentliche Repo gelangt:
#
#   1. Keine Infra-/Secret-Leaks — generische Marker (private IPs ausser in Tests,
#      lokale Pfade, Private-Key-/Access-Key-Muster) PLUS eine projektspezifische,
#      NICHT eingecheckte Blockliste (.githooks/leak-blocklist.local, gitignored)
#      mit Hosting-Provider-/Host-/Fremdprojekt-Namen. Bewusst werden hier KEINE
#      maintainer-spezifischen Namen hart eincodiert — sonst wäre der Gate selbst
#      ein Informationsleck.
#   2. Öffentliche GitHub-Markdown (README/SECURITY/…) ist Englisch.
#   3. Keine Security-Werbung (Guardrail), keine falsche Identität, keine Platzhalter.
#   4. Pflichtdateien vorhanden + nicht leer; die lokale Blockliste bleibt ungetrackt.
#
# Semantische Aktualität (Drift Doku<->Code) bleibt dem manuellen votepit-sync-check
# vorbehalten; dieser Gate ist die mechanische Schutzschicht, nicht der Drift-Check.
#
# Bash-3.2-kompatibel (macOS /bin/bash) — kein mapfile/readarray.
#
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
MODE="${1:-staged}"

fail=0
err(){ echo "  ✗ $1" >&2; fail=1; }

# --- Generische Infra-/Secret-Marker (provider-agnostisch) ---
HARD_RE='/Users/[A-Za-z]|/home/[a-z][a-z0-9_-]*/|BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}'
# Private IPs: Leak in Doku/Config, aber legitime Fixtures in Tests → dort exempt.
IP_RE='192\.168\.[0-9]|10\.[0-9]+\.[0-9]+\.[0-9]+|172\.(1[6-9]|2[0-9]|3[01])\.[0-9]'

# --- Projektspezifische Blockliste (NICHT eingecheckt, gitignored) ---
# Eine Zeile = ein erweiterter grep-Term (Kommentare/Leerzeilen mit # ignoriert).
LOCAL_BLOCK="$ROOT/.githooks/leak-blocklist.local"
LOCAL_RE=""
if [ -f "$LOCAL_BLOCK" ]; then
  LOCAL_RE="$(grep -vE '^[[:space:]]*(#|$)' "$LOCAL_BLOCK" | paste -sd '|' - || true)"
fi

# --- Englisch-Pflicht-Dateien (öffentliche GitHub-Markdown) ---
ENGLISH_FILES="README.md SECURITY.md CONTRIBUTING.md CODE_OF_CONDUCT.md"
# Eindeutig deutsche Funktionswörter (rein ASCII → keine Locale-/Umlaut-Probleme,
# keine False-Positives auf englische Wörter oder 'MIT').
GERMAN_RE='(^|[^[:alpha:]])(und|oder|nicht|werden|wurde|eine|einen|keine|sind|auch|dass|wird|diese|sich|wenn|gehört)([^[:alpha:]]|$)'

list_files(){
  if [ "$MODE" = "staged" ]; then
    git diff --cached --name-only --diff-filter=ACM
  else
    git ls-tree -r --name-only HEAD
  fi
}
read_file(){ if [ "$MODE" = "staged" ]; then git show ":$1" 2>/dev/null; else git show "HEAD:$1" 2>/dev/null; fi; }
exists(){
  if [ "$MODE" = "staged" ]; then git ls-files --cached --error-unmatch "$1" >/dev/null 2>&1
  else git cat-file -e "HEAD:$1" 2>/dev/null; fi
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

# 0) Die lokale Blockliste darf NIE getrackt/committet werden.
if exists ".githooks/leak-blocklist.local"; then
  err ".githooks/leak-blocklist.local ist getrackt — muss lokal/gitignored bleiben"
fi

# 1) Infra-/Secret-Leak-Scan über alle (scannbaren) Dateien
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if skip_file "$f"; then continue; fi
  content="$(read_file "$f")"
  if printf '%s' "$content" | LC_ALL=C grep -qE "$HARD_RE"; then
    hit="$(printf '%s' "$content" | LC_ALL=C grep -nE "$HARD_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
    err "$f: Infra-/Secret-Leak -> $hit"
  fi
  if [ -n "$LOCAL_RE" ] && printf '%s' "$content" | LC_ALL=C grep -qiE "$LOCAL_RE"; then
    hit="$(printf '%s' "$content" | LC_ALL=C grep -niE "$LOCAL_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
    err "$f: Blocklisten-Treffer (Internas) -> $hit"
  fi
  if ! is_test "$f"; then
    if printf '%s' "$content" | LC_ALL=C grep -qE "$IP_RE"; then
      hit="$(printf '%s' "$content" | LC_ALL=C grep -nE "$IP_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
      err "$f: private IP (Infra-Leak) -> $hit"
    fi
  fi
done < <(list_files)

# 2) Pflichtdateien vorhanden + nicht leer
for f in README.md SECURITY.md LICENSE; do
  if ! exists "$f"; then err "Pflichtdatei fehlt: $f"; continue; fi
  if [ -z "$(read_file "$f" | tr -d '[:space:]')" ]; then err "Pflichtdatei leer: $f"; fi
done

# 3) Markdown-spezifisch: Englisch, keine Security-Werbung, Identität, Platzhalter
for f in $ENGLISH_FILES; do
  if ! exists "$f"; then continue; fi
  c="$(read_file "$f")"
  if printf '%s' "$c" | grep -qiE 'security[ -]by[ -]design|security-by-default|battle-tested|military-grade|bank-grade|hardened by design'; then
    err "$f: Security-Werbung (Guardrail: Security nicht bewerben)"
  fi
  if printf '%s' "$c" | grep -qE 'votepit/votepit'; then
    err "$f: falsche Identität 'votepit/votepit' (erwartet silvio-l/votepit)"
  fi
  if printf '%s' "$c" | grep -qE 'TODO|FIXME|XXX|PLACEHOLDER|LOREM IPSUM'; then
    err "$f: Platzhalter (TODO/FIXME/…) in öffentlicher Doku"
  fi
  if printf '%s' "$c" | LC_ALL=C grep -qE "$GERMAN_RE"; then
    err "$f: deutsche Sprachmarker — öffentliche GitHub-Markdown muss Englisch sein"
  fi
done

if [ "$fail" -ne 0 ]; then
  echo "" >&2
  echo "DOC-GATE BLOCKIERT — öffentliche Inhalte verletzen Invarianten (s.o.)." >&2
  echo "Internas/Infra-Details (Provider, Hosts, IPs, lokale Pfade) gehören NIE" >&2
  echo "ins öffentliche Repo; GitHub-Markdown bleibt Englisch und ohne Security-Werbung." >&2
  echo "" >&2
  exit 1
fi
exit 0
