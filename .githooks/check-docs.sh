#!/usr/bin/env bash
#
# Votepit — Doc-Gate (Schutzschicht für öffentliche Inhalte)
#
# Deterministische, rein lokale Prüfung (kein LLM, kein Netz → Free-Tier-konform).
# Läuft in pre-commit (MODE=staged) und pre-push (MODE=head). Erzwingt die
# mechanischen Invarianten für ALLES, was ins öffentliche Repo gelangt:
#
#   1. Keine Internas / Infra-Recon-Leaks (Hosting-Provider, interne Hosts, private
#      IPs, lokale Pfade, Deploy-Internals) — NIRGENDS in getrackten Dateien.
#   2. Öffentliche GitHub-Markdown (README/SECURITY/…) ist Englisch.
#   3. Keine Security-Werbung (Guardrail), keine falsche Identität, keine Platzhalter.
#   4. Pflichtdateien vorhanden + nicht leer.
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

# --- Internas / Recon-Leaks (NIE im öffentlichen Repo) ---
# Hosting-Provider, interne Hosts, lokale Pfade, Deploy-Internals, fremde
# Projektnamen. (matomo.silvio-und-maik.de ist BEWUSST nicht gelistet: legitimer,
# ohnehin öffentlicher Analytics-Endpoint + gesetzlich im Datenschutz genannt.
# silvio-l/votepit ist die korrekte öffentliche Identität.)
# HARD: nie irgendwo (auch nicht in Tests).
HARD_RE='host|example|localhost|localhost|localhost|example|example|example|webroot|SSH|/Users/[A-Za-z]|/home/[a-z]+/'
# Private IPs: Leak in Doku/Config, aber legitime Fixtures in Tests → dort exempt.
IP_RE='192\.168\.[0-9]|10\.[0-9]+\.[0-9]+\.[0-9]+|172\.(1[6-9]|2[0-9]|3[01])\.[0-9]'

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
read_file(){ # $1 path
  if [ "$MODE" = "staged" ]; then git show ":$1" 2>/dev/null; else git show "HEAD:$1" 2>/dev/null; fi
}
exists(){ # $1 path
  if [ "$MODE" = "staged" ]; then
    git ls-files --cached --error-unmatch "$1" >/dev/null 2>&1
  else
    git cat-file -e "HEAD:$1" 2>/dev/null
  fi
}

# Nicht scannen: die Hook-Skripte selbst (enthalten die Muster als Detektion),
# Binaries, Lockfiles, Build-Artefakte, Fremd-Code.
skip_file(){ # $1 path -> 0 = skip
  case "$1" in
    .githooks/*) return 0 ;;
    *node_modules/*|*/dist/*|*/.astro/*|vendor/*) return 0 ;;
    *.png|*.jpg|*.jpeg|*.gif|*.ico|*.svg|*.webp|*.woff|*.woff2|*.ttf|*.eot|*.pdf) return 0 ;;
    pnpm-lock.yaml|*/pnpm-lock.yaml|composer.lock|package-lock.json) return 0 ;;
  esac
  return 1
}

# 1) Internal-Leak-Scan über alle (scannbaren) Dateien
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if skip_file "$f"; then continue; fi
  content="$(read_file "$f")"
  if printf '%s' "$content" | LC_ALL=C grep -qiE "$HARD_RE"; then
    hit="$(printf '%s' "$content" | LC_ALL=C grep -niE "$HARD_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
    err "$f: Internas/Infra-Leak -> $hit"
  fi
  # Private-IP-Check überall AUSSER in Test-Dateien (Fixtures sind kein Leak).
  case "$f" in
    tests/*|*/tests/*|test/*|*/test/*|*_test.*|*.test.*|*Test.php|spec/*|*/spec/*) ;;
    *)
      if printf '%s' "$content" | LC_ALL=C grep -qE "$IP_RE"; then
        hit="$(printf '%s' "$content" | LC_ALL=C grep -nE "$IP_RE" | head -1 | sed 's/^[[:space:]]*//; s/  */ /g' | cut -c1-100)"
        err "$f: private IP (Infra-Leak) -> $hit"
      fi
      ;;
  esac
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
