DC := docker compose
OCC := $(DC) exec -u www-data app php occ
# Run PHP as the host user so test caches etc. stay writable.
PHP := $(DC) exec -u $(shell id -u) -w /var/www/html/custom_apps/apple_sync app php
COMPOSER := docker run --rm -u $(shell id -u):$(shell id -g) -e COMPOSER_HOME=/tmp/composer -v "$(CURDIR)":/app -w /app composer:2
SRP_PYTHON ?= python3

VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)

.PHONY: up down enable apps build composer js test e2e e2e-down lint srp-vectors login status dist deploy-prod

up:            ## start Nextcloud on http://localhost:8089 (admin / admin-dev-pass)
	$(DC) up -d

down:
	$(DC) down

enable:        ## enable this app
	$(OCC) app:enable apple_sync

apps:          ## install the apps the synced data shows up in
	docker compose exec app chown www-data:www-data /var/www/html/custom_apps  # created by Docker for the bind mount
	-$(OCC) app:install contacts
	-$(OCC) app:install calendar
	-$(OCC) app:install tasks
	$(OCC) app:enable photos

build: composer js

composer:
	$(COMPOSER) install --no-interaction --ignore-platform-req=ext-gmp

js:
	npm ci --no-audit --no-fund
	npm run build

test:
	$(PHP) vendor/bin/phpunit

e2e:           ## browser tests: throwaway Nextcloud (port 8091) + fake Apple (8092)
	tests/e2e/setup.sh
	npx playwright test

e2e-down:
	docker compose -f tests/e2e/docker-compose.yml down -v

lint:
	$(PHP) -r 'foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("lib")) as $$f) if ($$f->getExtension() === "php") passthru("php -l " . escapeshellarg($$f) . " | grep -v \"^No syntax errors\"");'

srp-vectors:   ## regenerate SRP test vectors (needs `pip install srp`)
	$(SRP_PYTHON) tests/tools/gen_srp_vectors.py > tests/fixtures/srp_vectors.json

login:         ## interactive iCloud sign-in: make login USER_ID=admin
	$(DC) exec -it -u www-data app php occ apple-sync:login $(USER_ID)

status:
	$(OCC) apple-sync:status --verify

dist: js       ## package the installable app into build/apple_sync (+ tarball)
	rm -rf build/apple_sync
	mkdir -p build/apple_sync
	cp -r appinfo img js lib templates build/apple_sync/
	rm -f build/apple_sync/js/*.map
	sed -i '/^\/\/# sourceMappingURL=/d' build/apple_sync/js/*.mjs
	tar -czf build/apple_sync-$(VERSION).tar.gz -C build apple_sync

deploy-prod:   ## install/update on the production Nextcloud (see AGENTS.md "Production")
	scripts/deploy-prod.sh
