#!/bin/sh
# Votepit — Docker entrypoint.
#
# Docker-only convenience layer on top of the same install steps described
# in documentation/installation.md (config file, then the migration
# runner): generate config/config.php from env vars if none exists yet,
# wait for the database, then apply pending migrations non-interactively
# before handing off to the real CMD (apache2-foreground).
#
# php bin/migrate.php --yes skips the interactive backup prompt that the
# shared-hosting path always shows — that prompt exists because there is no
# bundled backup tooling (see documentation/operations.md#backuprestore).
# Here it would deadlock container startup waiting on stdin. This mirrors
# bin/migrate.php's own documented "--yes ONLY for automated deploys on
# throwaway/staging data" caveat: back up the `db` volume yourself
# (`docker compose exec db mysqldump ...`) before upgrading a Docker
# installation that holds real data, same as you would on shared hosting.
set -eu

APP_ROOT="/var/www/html"
CONFIG_PATH="${APP_ROOT}/config/config.php"

if [ ! -f "$CONFIG_PATH" ]; then
    echo "votepit: generating config/config.php from environment variables..."
    php "${APP_ROOT}/docker/generate-config.php" > "$CONFIG_PATH"
fi

echo "votepit: waiting for the database..."
attempt=0
until php -r '
    $config = require "'"$CONFIG_PATH"'";
    $db = $config["db"];
    new PDO(
        "mysql:host={$db["host"]};port={$db["port"]}",
        $db["user"],
        $db["pass"],
        [PDO::ATTR_TIMEOUT => 2]
    );
' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "votepit: database not reachable after 30 attempts, giving up." >&2
        exit 1
    fi
    sleep 2
done

echo "votepit: applying pending migrations..."
php "${APP_ROOT}/bin/migrate.php" --yes

exec "$@"
