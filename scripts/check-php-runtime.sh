#!/bin/sh
# Check both consumers: replacing only the web container leaves cron vulnerable.
set -eu
cd "$(dirname "$0")/.."
for service in app cron; do
    printf '%s: ' "$service"
    docker compose exec -T "$service" php -r '
        if (PHP_VERSION_ID < 80512) {
            fwrite(STDERR, "FAIL: PHP " . PHP_VERSION . "; require PHP 8.5.12 or newer.\n");
            exit(1);
        }
        echo "PASS: Nextcloud runtime PHP " . PHP_VERSION . "\n";
    '
done
