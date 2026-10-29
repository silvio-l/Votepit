#!/usr/bin/env bash
#
# Votepit Community — builds the upload-ready release archive.
#
# Produces build/votepit-community-<version>.zip (top-level folder `votepit/`)
# plus a matching .sha256 file. The archive holds exactly what a shared-hosting
# install needs: the production vendor/ tree, the built React SPA in public/,
# the runtime code, and the config TEMPLATE. No dev or test files, no secrets.
#
# Usage (from a clone or an unpacked source tree):
#   bash bin/build-release.sh
#
# Requirements on the build machine: PHP 8.2+, Composer 2, Node 22+, pnpm 11,
# zip, and sha256sum or shasum. The build runs in a staging folder below
# build/, so your working tree's dev dependencies stay untouched.
#
# Environment:
#   SKIP_SPA=1   reuse an existing app/dist/ instead of building the SPA
set -euo pipefail

CORE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$CORE_DIR/build"
STAGE="$OUT_DIR/stage"

die() { printf 'build-release: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "missing tool: $1"; }

need php
need composer
need node
need pnpm
need zip
if command -v sha256sum >/dev/null 2>&1; then SHA256="sha256sum"; elif command -v shasum >/dev/null 2>&1; then SHA256="shasum -a 256"; else die "missing tool: sha256sum or shasum"; fi

php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || die "PHP 8.2+ required"
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]')"
[ "$NODE_MAJOR" -ge 22 ] || die "Node 22+ required (found $(node -v))"

# shellcheck disable=SC2016 # PHP code, not shell expansion
VERSION="$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo $c["version"];' "$CORE_DIR/composer.json")"
ARCHIVE="votepit-community-$VERSION.zip"

echo "▶ Votepit Community $VERSION — building release archive"

if [ "${SKIP_SPA:-0}" != "1" ]; then
    echo "▶ React SPA (pnpm install --frozen-lockfile, then build)"
    ( cd "$CORE_DIR" && pnpm install --frozen-lockfile && pnpm --filter votepit-app run build )
fi
[ -f "$CORE_DIR/app/dist/index.html" ] || die "app/dist/index.html missing: the SPA build did not run"

rm -rf "${OUT_DIR:?}/stage" "${OUT_DIR:?}/$ARCHIVE" "${OUT_DIR:?}/$ARCHIVE.sha256"
mkdir -p "$STAGE/votepit/config"
DEST="$STAGE/votepit"

echo "▶ Staging runtime files"
# Whitelist: only what the running application reads. Everything else
# (tests, tooling, app/ sources, docs, CI, docker) stays out of the archive.
for entry in .htaccess public src resources bin migrations db composer.json composer.lock README.md LICENSE; do
    cp -R "$CORE_DIR/$entry" "$DEST/"
done
cp "$CORE_DIR/config/config.example.php" "$DEST/config/config.example.php"
# Operator-only development scripts are not needed on a server.
rm -f "$DEST"/bin/dev-*
# Built SPA goes into the webroot (index.php serves public/index.html).
cp -R "$CORE_DIR/app/dist/." "$DEST/public/"

echo "▶ Composer (production dependencies, optimized autoloader)"
( cd "$DEST" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )

echo "▶ Verifying the staged tree"
[ -f "$DEST/public/index.html" ] || die "public/index.html missing in stage"
[ -f "$DEST/public/index.php" ] || die "public/index.php missing in stage"
[ -f "$DEST/.htaccess" ] || die ".htaccess missing in stage"
[ ! -e "$DEST/config/config.php" ] || die "config/config.php must never be packaged"
for forbidden in tests app packages node_modules docker .github .githooks .semgrep tools skills documentation vendor/phpunit vendor/bin/phpunit; do
    [ ! -e "$DEST/$forbidden" ] || die "dev/test artefact packaged: $forbidden"
done
# Dev-only Composer packages must be absent after --no-dev.
[ ! -d "$DEST/vendor/phpstan" ] || die "phpstan packaged; composer --no-dev failed"

echo "▶ Packing $ARCHIVE"
( cd "$STAGE" && zip -qr "$OUT_DIR/$ARCHIVE" votepit )
( cd "$OUT_DIR" && $SHA256 "$ARCHIVE" > "$ARCHIVE.sha256" )
rm -rf "$STAGE"

echo "✅ $OUT_DIR/$ARCHIVE"
echo "   $OUT_DIR/$ARCHIVE.sha256"
echo "   Upload the folder 'votepit/' (FTPES) or unzip it on the server; see documentation/installation.md."
