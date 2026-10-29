#!/usr/bin/env bash
#
# Votepit Community Edition — one-line installer for a fresh Ubuntu/Debian VPS.
#
#   curl -fsSL https://raw.githubusercontent.com/silvio-l/votepit/main/install.sh | bash
#   bash install.sh                       # after a manual download
#
# What it does (idempotent, safe to re-run):
#   1. checks PHP, its extensions and the database server version; prints the
#      exact apt line for anything missing (installs only with --install-deps)
#   2. downloads the latest release archive (or clones and builds when no
#      release exists) and verifies its .sha256 when one is published
#   3. asks for the install directory, database access and base URL
#      (or reads VOTEPIT_* variables) and writes config/config.php from the
#      template with freshly generated secrets (never printed)
#   4. applies the database migrations and prints the remaining manual steps
#      (web root, cron, mail)
#
# Prompts are read from /dev/tty, so the one-liner works. Non-interactive use:
# set the VOTEPIT_* variables below plus VOTEPIT_NONINTERACTIVE=1; a missing
# value aborts. Shared hosting without SSH cannot run this script: use the
# release archive and the upload path in documentation/installation.md.
#
# Options:  --install-deps   run `sudo apt-get install` for missing packages
#           -h, --help       show this header
set -eu -o pipefail

REPO="${VOTEPIT_REPO:-silvio-l/votepit}"      # GitHub owner/repo
VERSION="${VOTEPIT_VERSION:-}"                # release tag, empty = latest
ARCHIVE_LOCAL="${VOTEPIT_ARCHIVE:-}"          # local release .zip (skips download)
INSTALL_DIR="${VOTEPIT_INSTALL_DIR:-}"        # default: /var/www/votepit
APP_URL="${VOTEPIT_APP_URL:-}"                # https://votes.example.com
DB_HOST="${VOTEPIT_DB_HOST:-}"                # default: localhost
DB_NAME="${VOTEPIT_DB_NAME:-}"                # default: votepit
DB_USER="${VOTEPIT_DB_USER:-}"
DB_PASS=""
printf -v DB_PASS '%s' "${VOTEPIT_DB_PASS:-}"   # env value, never echoed
ADMIN_EMAILS="${VOTEPIT_ADMIN_EMAILS:-}"      # comma-separated
NONINTERACTIVE="${VOTEPIT_NONINTERACTIVE:-0}"
MIGRATE_YES="${VOTEPIT_MIGRATE_YES:-0}"       # 1 = bin/migrate.php --yes (no backup prompt)
INSTALL_DEPS=0
ARCHIVE_PATH=""

# Minimum versions. Keep in sync with documentation/installation.md (Requirements).
MIN_PHP="8.2.0"
MIN_MYSQL="8.0.19"
MIN_MARIADB="10.6.0"
# Extensions from composer.json plus what its dependencies need at runtime
# (dompdf: dom, zip, gd; php-css-parser: iconv). pdo_mysql is the driver used.
REQUIRED_EXT="curl gd intl mbstring pdo pdo_mysql sodium dom zip iconv"

step() { printf '\n==> %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() {
    printf 'ERROR: %s\n' "$*" >&2
    printf 'Installation aborted. Fix the problem and re-run the same command; an existing config is kept.\n' >&2
    exit 1
}

case "${1:-}" in
    -h|--help) sed -n '2,25p' "$0" 2>/dev/null || printf 'See https://github.com/silvio-l/votepit\n'; exit 0 ;;
    --install-deps) INSTALL_DEPS=1 ;;
    "") ;;
    *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 2 ;;
esac

TMP="$(mktemp -d)"
cleanup() { rm -rf "${TMP:?}"; }
trap cleanup EXIT
umask 022   # code must stay readable by the web server; config.php is written owner-only (write_config)

# ── Terminal input ──────────────────────────────────────────────────────────
INTERACTIVE=1
if [ "$NONINTERACTIVE" = "1" ] || ! ( : </dev/tty ) 2>/dev/null; then
    INTERACTIVE=0
fi

# ask VAR "Question" [default]: keeps an environment value, else asks on the
# terminal; without a terminal it uses the default or aborts.
ask() {
    local var="$1" text="$2" def="${3:-}" answer=""
    if [ -n "${!var:-}" ]; then return 0; fi
    if [ "$INTERACTIVE" != 1 ]; then
        [ -n "$def" ] || die "$var is not set (non-interactive mode)"
        printf -v "$var" '%s' "$def"
        return 0
    fi
    if [ -n "$def" ]; then printf '%s [%s]: ' "$text" "$def" >&2; else printf '%s: ' "$text" >&2; fi
    IFS= read -r answer </dev/tty || die "no input received for $var"
    [ -n "$answer" ] || answer="$def"
    [ -n "$answer" ] || die "$var is required"
    printf -v "$var" '%s' "$answer"
}

# ask_secret VAR "Question": hidden input, never echoed or logged.
ask_secret() {
    local var="$1" text="$2" answer=""
    if [ -n "${!var:-}" ]; then return 0; fi
    [ "$INTERACTIVE" = 1 ] || die "$var is not set (non-interactive mode)"
    printf '%s: ' "$text" >&2
    IFS= read -rs answer </dev/tty || die "no input received for $var"
    printf '\n' >&2
    [ -n "$answer" ] || die "$var is required"
    printf -v "$var" '%s' "$answer"
}

# ── Checks ──────────────────────────────────────────────────────────────────
APT_PKGS=()
add_pkg() {
    local p
    for p in "${APT_PKGS[@]+"${APT_PKGS[@]}"}"; do
        [ "$p" = "$1" ] && return 0
    done
    APT_PKGS+=("$1")
}

ext_package() {
    case "$1" in
        curl) echo php-curl ;;
        gd) echo php-gd ;;
        intl) echo php-intl ;;
        mbstring) echo php-mbstring ;;
        pdo_mysql) echo php-mysql ;;
        sodium) echo ;;
        dom) echo php-xml ;;
        zip) echo php-zip ;;
        pdo|iconv) echo php-common ;;
    esac
}

check_tools() {
    step "Checking tools and PHP"
    local t ext modules
    for t in curl unzip; do
        if ! command -v "$t" >/dev/null 2>&1; then
            warn "$t is missing"
            add_pkg "$t"
        fi
    done
    if ! command -v php >/dev/null 2>&1; then
        add_pkg php-cli
        printf '\nMissing packages. Run this, then re-run the installer:\n\n    sudo apt-get install -y %s\n\n' "${APT_PKGS[*]}" >&2
        die "PHP is not installed (PHP ${MIN_PHP}+ required)"
    fi
    php -r 'exit(version_compare(PHP_VERSION, "'"$MIN_PHP"'", ">=") ? 0 : 1);' \
        || die "PHP ${MIN_PHP}+ required, found $(php -r 'echo PHP_VERSION;')"
    info "PHP $(php -r 'echo PHP_VERSION;')"

    modules="$(php -m | tr '[:upper:]' '[:lower:]')"
    for ext in $REQUIRED_EXT; do
        if ! printf '%s\n' "$modules" | grep -qx "$ext"; then
            warn "PHP extension missing: $ext"
            add_pkg "$(ext_package "$ext")"
        fi
    done
    if [ "${#APT_PKGS[@]}" -eq 0 ]; then
        info "PHP extensions present: $REQUIRED_EXT"
        return 0
    fi

    local line="sudo apt-get install -y ${APT_PKGS[*]}"
    if [ "$INSTALL_DEPS" != 1 ]; then
        printf '\nMissing packages. Run this, then re-run the installer:\n\n    %s\n\n' "$line" >&2
        die "missing PHP packages (or re-run with --install-deps to let the installer run apt)"
    fi
    command -v apt-get >/dev/null 2>&1 || die "--install-deps needs apt-get (Ubuntu/Debian)"
    step "Installing missing packages (--install-deps)"
    info "$line"
    sudo apt-get update
    sudo apt-get install -y "${APT_PKGS[@]}"
}

# db_check: connects with the entered settings, prints the server version
# and enforces the minimum. Passwords go through the environment, never argv.
db_check() {
    local version numeric min flavour
    # shellcheck disable=SC2016 # the PHP snippet is single-quoted on purpose
    version="$(VP_H="$DB_HOST" VP_N="$DB_NAME" VP_U="$DB_USER" VP_P="$DB_PASS" php -d display_errors=0 -r '
        try {
            $pdo = new PDO("mysql:host=" . getenv("VP_H") . ";port=3306;dbname=" . getenv("VP_N") . ";charset=utf8mb4",
                getenv("VP_U"), getenv("VP_P"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            echo $pdo->query("SELECT VERSION()")->fetchColumn();
        } catch (Throwable $e) {
            exit(1);
        }' 2>/dev/null)" \
        || die "database connection failed: check host, database name, user and password, and that the database exists (documentation/installation.md, section 3)"

    numeric="${version%%-*}"
    if [[ "$version" == *MariaDB* ]]; then
        min="$MIN_MARIADB"; flavour="MariaDB"
    else
        min="$MIN_MYSQL"; flavour="MySQL"
    fi
    VP_A="$numeric" VP_B="$min" php -r 'exit(version_compare(getenv("VP_A"), getenv("VP_B"), ">=") ? 0 : 1);' \
        || die "$flavour $numeric is older than the minimum $min"
    info "database server: $flavour $numeric (minimum $min)"
}

collect_settings() {
    step "Settings"
    ask INSTALL_DIR "Install directory" "/var/www/votepit"
    if [ -f "$INSTALL_DIR/config/config.php" ]; then
        info "existing config/config.php found: keeping it, no settings asked"
        return 0
    fi
    ask APP_URL "Public base URL, e.g. https://votes.example.com"
    APP_URL="${APP_URL%/}"
    case "$APP_URL" in
        https://*) ;;
        http://localhost*|http://127.0.0.1*) warn "plain http: only for a local test installation" ;;
        *) die "APP_URL must start with https:// (or http://localhost for a local test)" ;;
    esac
    ask DB_HOST "Database host" "localhost"
    ask DB_NAME "Database name" "votepit"
    ask DB_USER "Database user"
    ask_secret DB_PASS "Database password (input hidden)"
    ask ADMIN_EMAILS "Admin e-mail address(es), comma-separated"
    case "$ADMIN_EMAILS" in
        *@*) ;;
        *) die "ADMIN_EMAILS needs at least one e-mail address" ;;
    esac
}

# ── Release archive ─────────────────────────────────────────────────────────
verify_sha256() { # <archive> <checksum-file>, both in the same directory
    local dir name sum
    dir="$(dirname "$1")"; name="$(basename "$1")"
    if command -v sha256sum >/dev/null 2>&1; then
        sum="sha256sum"
    elif command -v shasum >/dev/null 2>&1; then
        sum="shasum -a 256"
    else
        warn "no sha256sum/shasum: checksum not verified"
        return 0
    fi
    # shellcheck disable=SC2086 # $sum is a command with arguments
    (cd "$dir" && $sum -c "$(basename "$2")" >/dev/null 2>&1) || die "checksum mismatch for $name"
    info "checksum verified: $name"
}

build_from_source() {
    step "No release archive found: cloning $REPO and building locally"
    local t
    for t in git composer node pnpm zip; do
        command -v "$t" >/dev/null 2>&1 || die "building from source needs '$t' (documentation/installation.md); or publish a release archive"
    done
    if [ -n "$VERSION" ]; then
        git clone --depth 1 --branch "$VERSION" "https://github.com/$REPO.git" "$TMP/src"
    else
        git clone --depth 1 "https://github.com/$REPO.git" "$TMP/src"
    fi
    ( cd "$TMP/src" && bash bin/build-release.sh )
    set -- "$TMP"/src/build/votepit-community-*.zip
    [ -f "$1" ] || die "the build produced no archive"
    ARCHIVE_PATH="$1"
}

fetch_archive() {
    step "Getting the Votepit release archive"
    if [ -n "$ARCHIVE_LOCAL" ]; then
        [ -f "$ARCHIVE_LOCAL" ] || die "VOTEPIT_ARCHIVE not found: $ARCHIVE_LOCAL"
        ARCHIVE_PATH="$ARCHIVE_LOCAL"
        info "using local archive: $ARCHIVE_PATH"
        return 0
    fi
    local api json zip_url sha_url name
    if [ -n "$VERSION" ]; then
        api="https://api.github.com/repos/$REPO/releases/tags/$VERSION"
    else
        api="https://api.github.com/repos/$REPO/releases/latest"
    fi
    json="$(curl -fsSL -H 'Accept: application/vnd.github+json' "$api" 2>/dev/null)" || json=""
    zip_url="$(printf '%s' "$json" | grep -o '"browser_download_url":[[:space:]]*"[^"]*\.zip"' | head -n1 | sed 's/.*"\(https:[^"]*\)"$/\1/')" || true
    if [ -z "$zip_url" ]; then
        build_from_source
        return 0
    fi
    name="${zip_url##*/}"
    curl -fSL --proto '=https' --tlsv1.2 -o "$TMP/$name" "$zip_url"
    info "downloaded $name"
    sha_url="$(printf '%s' "$json" | grep -o '"browser_download_url":[[:space:]]*"[^"]*\.zip\.sha256"' | head -n1 | sed 's/.*"\(https:[^"]*\)"$/\1/')" || true
    if [ -n "$sha_url" ]; then
        curl -fsSL --proto '=https' --tlsv1.2 -o "$TMP/$name.sha256" "$sha_url"
        verify_sha256 "$TMP/$name" "$TMP/$name.sha256"
    else
        warn "this release publishes no .sha256 file: integrity not verified"
    fi
    ARCHIVE_PATH="$TMP/$name"
}

# ── Install ─────────────────────────────────────────────────────────────────
install_files() {
    step "Installing the files into $INSTALL_DIR"
    unzip -qo "$ARCHIVE_PATH" -d "$TMP/unpacked" </dev/null
    local src="$TMP/unpacked/votepit"
    [ -f "$src/public/index.php" ] || die "unexpected archive layout (public/index.php missing)"
    mkdir -p "$INSTALL_DIR" || die "cannot create $INSTALL_DIR: choose a directory you can write to"
    cp -R "$src/." "$INSTALL_DIR/"
    mkdir -p "$INSTALL_DIR/storage" "$INSTALL_DIR/logs" "$INSTALL_DIR/var"
    info "code updated; config/config.php (if present) is not touched"
}

# write_config: template -> config/config.php. Values travel through the
# environment; secrets are generated by PHP and never printed.
write_config() {
    step "Writing config/config.php"
    local out="$INSTALL_DIR/config/config.php"
    local saved_umask
    saved_umask="$(umask)"
    umask 077   # the secrets file is created owner-only, never briefly world-readable
    # shellcheck disable=SC2016 # the PHP snippet is single-quoted on purpose
    VP_TEMPLATE="$INSTALL_DIR/config/config.example.php" VP_OUT="$out" \
    VP_APP_URL="$APP_URL" VP_DB_HOST="$DB_HOST" VP_DB_NAME="$DB_NAME" VP_DB_USER="$DB_USER" \
    VP_DB_PASS="$DB_PASS" VP_ADMIN_EMAILS="$ADMIN_EMAILS" php -r '
        function fail(string $m): never { fwrite(STDERR, "config template: $m\n"); exit(1); }
        $src = file_get_contents(getenv("VP_TEMPLATE")) ?: fail("cannot read the template");
        $q = static fn (string $v): string => var_export($v, true);
        $once = static function (string $s, string $pattern, callable $cb): string {
            $out = preg_replace_callback($pattern, $cb, $s, 1, $n);
            if ($out === null || $n !== 1) { fail("pattern not found: $pattern"); }
            return $out;
        };
        $src = $once($src, "/^(\\s*\x27app_url\x27 => )\x27[^\x27]*\x27,/m",
            fn ($m) => $m[1] . $q(getenv("VP_APP_URL")) . ",");
        $src = $once($src, "/^(\\s*\x27app_key\x27 => )\x27\x27,/m",
            fn ($m) => $m[1] . $q(bin2hex(random_bytes(32))) . ",");
        $src = $once($src, "/^(\\s*\x27identity_server_key\x27 => )\x27\x27,/m",
            fn ($m) => $m[1] . $q(bin2hex(random_bytes(32))) . ",");
        // Database block only: host/name/user/pass also occur under smtp.
        preg_match("/\x27db\x27 => \\[.*?\n    \\],/s", $src, $dbm) === 1 || fail("db block");
        $db = $dbm[0];
        $db = $once($db, "/^(\\s*\x27host\x27\\s*=>\\s*)\x27[^\x27]*\x27,/m", fn ($m) => $m[1] . $q(getenv("VP_DB_HOST")) . ",");
        $db = $once($db, "/^(\\s*\x27name\x27\\s*=>\\s*)\x27[^\x27]*\x27,/m", fn ($m) => $m[1] . $q(getenv("VP_DB_NAME")) . ",");
        $db = $once($db, "/^(\\s*\x27user\x27\\s*=>\\s*)\x27[^\x27]*\x27,/m", fn ($m) => $m[1] . $q(getenv("VP_DB_USER")) . ",");
        $db = $once($db, "/^(\\s*\x27pass\x27\\s*=>\\s*)\x27[^\x27]*\x27,/m", fn ($m) => $m[1] . $q(getenv("VP_DB_PASS")) . ",");
        $src = str_replace($dbm[0], $db, $src);
        preg_match("/\x27admin_emails\x27 => \\[.*?\n    \\],/s", $src, $am) === 1 || fail("admin_emails block");
        $items = "";
        foreach (array_filter(array_map("trim", explode(",", getenv("VP_ADMIN_EMAILS")))) as $email) {
            $items .= "        " . $q($email) . ",\n";
        }
        $src = str_replace($am[0], "\x27admin_emails\x27 => [\n" . $items . "    ],", $src);
        file_put_contents(getenv("VP_OUT"), $src) === strlen($src) || fail("cannot write config");
    ' || die "could not write config/config.php"
    umask "$saved_umask"
    chmod 600 "$out"
    php -l "$out" >/dev/null || die "the generated config/config.php has a syntax error"
    info "written, secrets generated, file mode 600"
}

migrate() {
    step "Database schema"
    cd "$INSTALL_DIR"
    local pending
    pending="$(php bin/migrate.php --dry-run </dev/null)" || die "migration dry run failed (see the message above)"
    case "$pending" in
        *"Nothing to migrate"*) info "database is up to date"; return 0 ;;
    esac
    info "$pending"
    if [ "$MIGRATE_YES" = "1" ]; then
        php bin/migrate.php --yes </dev/null || die "migration failed (see the message above; restore your backup before retrying on existing data)"
    elif [ "$INTERACTIVE" = 1 ]; then
        printf '    Migrations are forward-only. Take a backup first if this database holds data.\n' >&2
        php bin/migrate.php </dev/tty || die "migration failed (see the message above)"
    else
        die "migrations pending and no terminal: set VOTEPIT_MIGRATE_YES=1 to apply them without the backup prompt"
    fi
}

next_steps() {
    cat <<EOF

==> Done. Votepit Community is installed in: $INSTALL_DIR

Remaining manual steps (VPS or hosting panel):
  1. Web root: point the virtual host at $INSTALL_DIR/public (Apache: DocumentRoot,
     AllowOverride All, modules rewrite and headers). If you cannot change the
     document root, leave it at $INSTALL_DIR: the root .htaccess routes into public/.
  2. HTTPS: certificate for the host in your APP_URL (e.g. certbot).
  3. Writable folders for the web server user (example for www-data):
       sudo chown -R www-data:www-data $INSTALL_DIR/storage $INSTALL_DIR/logs $INSTALL_DIR/var
  4. Cron, one job every minute:
       * * * * * php $INSTALL_DIR/bin/votepit-cron.php
  5. Mail: sign-in works by magic link only, so fill in the smtp.* block in
     config/config.php, then test:  php $INSTALL_DIR/bin/send-test-mail.php you@example.com
  6. Open your base URL and sign in with an admin address from admin_emails.

Documentation: documentation/installation.md (sections 4 to 6) and documentation/troubleshooting.md.
EOF
}

# ── Main ────────────────────────────────────────────────────────────────────
main() {
    printf 'Votepit Community installer\n'
    check_tools
    collect_settings
    fetch_archive
    install_files
    if [ ! -f "$INSTALL_DIR/config/config.php" ]; then
        db_check
        write_config
    fi
    migrate
    next_steps
}

main "$@"
