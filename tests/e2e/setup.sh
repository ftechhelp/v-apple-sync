#!/usr/bin/env bash
# Start the e2e stack and point the app at the fake Apple server.
set -euo pipefail
cd "$(dirname "$0")"

docker compose up -d --quiet-pull
occ() { docker compose exec -T -u www-data nextcloud php occ "$@"; }

for _ in $(seq 1 90); do
	occ status 2>/dev/null | grep -q 'installed: true' && break
	sleep 2
done
occ status | grep -q 'installed: true' || { echo "Nextcloud did not finish installing" >&2; exit 1; }

occ app:disable firstrunwizard >/dev/null 2>&1 || true
occ config:system:set allow_local_remote_servers --value=true --type=boolean >/dev/null
# The suite signs in more often than the per-user rate limits allow.
occ config:system:set ratelimit.protection.enabled --value=false --type=boolean >/dev/null
occ config:system:set apple_sync_endpoints auth --value=http://fake-apple:8000/appleauth/auth >/dev/null
occ config:system:set apple_sync_endpoints setup --value=http://fake-apple:8000/setup/ws/1 >/dev/null
occ config:system:set apple_sync_endpoints contacts --value=http://fake-apple:8000/carddav >/dev/null
occ config:system:set apple_sync_endpoints caldav --value=http://fake-apple:8000/caldav >/dev/null
occ app:enable apple_sync >/dev/null

# occ works before the entrypoint has started Apache; wait for HTTP too.
for _ in $(seq 1 60); do
	curl -sf -o /dev/null http://localhost:8091/status.php && break
	sleep 2
done
curl -sf -o /dev/null http://localhost:8091/status.php || { echo "Nextcloud is not answering on :8091" >&2; exit 1; }
echo "e2e stack ready on http://localhost:8091 (admin / admin-e2e-pass)"
