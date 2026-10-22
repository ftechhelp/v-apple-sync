#!/usr/bin/env bash
# Install or update apple_sync on the owner's production Nextcloud.
# Procedure and rationale: AGENTS.md, "Production". Touches only the apple_sync app.
#
#   scripts/deploy-prod.sh            # first install, or update after a version bump
#
# Overridable: PROD_CONTAINER, PROD_DB_CONTAINER, PROD_URL, BACKUP_DIR
set -euo pipefail

APP=apple_sync
CONTAINER=${PROD_CONTAINER:-nextcloud}
DB_CONTAINER=${PROD_DB_CONTAINER:-nextcloud-mariadb}
PUBLIC_URL=${PROD_URL:-https://cloud.vfontaine.ca}
BACKUP_DIR=${BACKUP_DIR:-$HOME/backups}
LOG=/var/www/html/data/nextcloud.log
ROOT=$(cd "$(dirname "$0")/.." && pwd)
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/appinfo/info.xml" | head -1)

occ() { docker exec -u www-data "$CONTAINER" php occ "$@"; }
fail() { echo "ERROR: $*" >&2; exit 1; }
json_field() { python3 -c "import json,sys; print(json.load(sys.stdin).get('$1'))"; }

echo "==> $APP $VERSION -> container '$CONTAINER'"

# 1. Preflight: nothing else may be pending, and the version must go up.
status=$(occ status --output=json)
[ "$(json_field maintenance <<<"$status")" = "False" ] || fail "production is in maintenance mode"
[ "$(json_field needsDbUpgrade <<<"$status")" = "False" ] || fail "production already has a pending upgrade; resolve that first (occ upgrade would apply it too)"

installed=$(occ config:app:get "$APP" installed_version 2>/dev/null || true)
if [ -n "$installed" ]; then
	[ "$installed" != "$VERSION" ] || fail "$VERSION is already installed: bump <version> in appinfo/info.xml"
	[ "$(printf '%s\n%s\n' "$installed" "$VERSION" | sort -V | tail -1)" = "$VERSION" ] || fail "installed $installed is newer than $VERSION; Nextcloud never downgrades"
	echo "    updating from $installed"
else
	echo "    first install"
fi

# 2. Build the package that gets installed.
make -C "$ROOT" -s dist >/dev/null
[ -f "$ROOT/build/$APP/appinfo/info.xml" ] || fail "build/$APP is missing"

# 3. Back up the database.
mkdir -p "$BACKUP_DIR"
backup="$BACKUP_DIR/nextcloud-db-pre-$APP-$VERSION-$(date +%Y%m%d-%H%M%S).sql.gz"
(umask 077; docker exec "$DB_CONTAINER" sh -c 'exec mariadb-dump --single-transaction -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip > "$backup")
chmod 600 "$backup"
zcat "$backup" | tail -n 1 | grep -q 'Dump completed' || fail "backup $backup looks incomplete"
echo "    backup: $backup"

log_lines=$(docker exec "$CONTAINER" sh -c "wc -l < $LOG 2>/dev/null || echo 0")

# 4. Swap the app folder.
docker exec "$CONTAINER" rm -rf "/tmp/$APP-new"
docker cp -q "$ROOT/build/$APP" "$CONTAINER:/tmp/$APP-new"
docker exec "$CONTAINER" sh -c "chown -R www-data:www-data /tmp/$APP-new && rm -rf /var/www/html/custom_apps/$APP && mv /tmp/$APP-new /var/www/html/custom_apps/$APP"

# 5. Enable (first install) or upgrade.
if [ -z "$installed" ]; then
	occ app:enable "$APP"
else
	# Ends with a harmless IAppConfig error from production's disabled Mail/Talk
	# apps (see AGENTS.md); installed_version below is the real check.
	occ upgrade || true
	occ app:enable "$APP" >/dev/null 2>&1 || true
fi
now=$(occ config:app:get "$APP" installed_version)
[ "$now" = "$VERSION" ] || fail "installed_version is '$now', expected $VERSION"

# 6. Verify.
status=$(occ status --output=json)
[ "$(json_field maintenance <<<"$status")" = "False" ] || fail "production is in maintenance mode after the update"
code=$(curl -s -o /dev/null -w '%{http_code}' "$PUBLIC_URL/status.php")
[ "$code" = 200 ] || fail "$PUBLIC_URL/status.php returned $code"
occ apple-sync:status >/dev/null || fail "occ apple-sync:status failed"
errors=$(docker exec "$CONTAINER" sh -c "tail -n +$((log_lines + 1)) $LOG 2>/dev/null" \
	| python3 -c 'import json,sys
for line in sys.stdin:
	try: e = json.loads(line)
	except ValueError: continue
	if e.get("level", 0) >= 3: print("   ", e.get("app"), "-", str(e.get("message"))[:200])')
if [ -n "$errors" ]; then
	echo "    new log entries with level >= 3 since the update:"
	echo "$errors"
fi

echo "==> $APP $VERSION is installed on $PUBLIC_URL"
echo "    rollback: restore $backup and the previous build/$APP-<version>.tar.gz"
