# AGENTS.md

Guidance for coding agents working in this repository.

## Project

`apple_sync` is a **Nextcloud 34/35 app** (namespace `OCA\AppleSync`). It syncs the owner's iCloud data into their own Nextcloud, **one-way**: nothing is ever written back to iCloud.

- **Contacts, Calendars:** Apple's CardDAV/CalDAV, using an app-specific password.
- **Photos, iCloud Drive, Reminders:** the undocumented iCloud web API. It needs the real Apple ID password, SRP-6a login and two-factor authentication. Apple's trust token lasts about 30 days.
- **Reminders (iOS 13+)** are not available over CalDAV. They come from CloudKit `com.apple.reminders`, where titles are zlib-compressed protobuf.
- **Photos layout:** `/iCloud Photos/YYYY/MM/<file>`.
- **Stack:** classic PHP app (the owner chose this over a Python ExApp), Vue 3 + `@nextcloud/vue` 9, Vite. Personal use, not built for the app store.
- **Everything the owner does must work from the Nextcloud web UI**: connecting, 2FA codes, settings, triggering syncs, seeing status and errors. A terminal step "defeats the purpose" (owner, 2026-10-20). `occ` commands are only for admins and debugging, never the intended path.
- **Protocol sources:** ported from rclone `backend/iclouddrive/api/session.go` and the timlaing/pyicloud fork. The structure follows `nextcloud/integration_google`.

## Status

| Phase | State |
|---|---|
| 0. Scaffold (info.xml, migration, entities, settings page, Vite, Docker dev) | ✅ done |
| 1. iCloud web sign-in (SRP, 2FA by device or SMS, trust token, accountLogin, `ensureWebSession`, `occ apple-sync:login`/`status`) | ✅ unit-tested, and ✅ **works against Apple with the owner's real Apple ID** (production, 2026-10-20: SRP, device code, trust and accountLogin; Apple did not offer the HSA2 bridge route). Not yet seen for real: the SMS path, and re-sign-in with the stored trust token after cookies expire |
| Connect UI in personal settings (Apple ID + password, device code / text message, check, reconnect, disconnect) | ✅ v0.2.0, browser-tested against a fake Apple |
| 2. Contacts + Calendars over DAV, SchedulerJob/SyncJob, locking, `occ apple-sync:sync` | ✅ v0.3.0, tested against the fake iCloud (unit + browser). Not yet run against the real iCloud |
| 3. Drive sync, reauth/sync-failed notifications | ✅ v0.3.0, same |
| 4. Photos (CloudKit) | ✅ v0.3.0, same. Live Photos get their `.MOV`; Shared Library zones are not read yet (phase 6) |
| "What to sync" UI (per service: on/off, folder, mirror deletions, Sync now, Check everything again, status) | ✅ v0.3.0 |
| Background sync of every service every 5 minutes | ✅ v0.4.0, checked over two real cron ticks on the e2e stack, and on production against the real iCloud (2026-10-21, ticks at 18:25 and 18:30) |
| 5. Reminders (CloudKit + protobuf) | ⏳ next |
| 6. ADP (`requestPCS`), Shared Library, albums | ⏳ |
| Tests | ✅ 55 PHPUnit tests: SRP vectors, sign-in flows, scheduling (`SchedulerJob`, `SyncRunner`), and the four sync engines against `tests/Support/FakeICloud.php` with in-memory Nextcloud stores. ✅ 4 Playwright tests (`make e2e`): the connect flow, and a full sync of all four services into a real Nextcloud (SQLite) from the same FakeICloud. The sync spec also passed on MariaDB 11.4 (throwaway stack, packaged app) |
| Production install | ✅ v0.4.0 on the owner's Nextcloud (35.0.2, MariaDB 11.4), 2026-10-21, syncing in the background through `apple-sync-cron.timer`. Earlier: 0.1.0–0.3.0 on 2026-10-20 |

## Production

The owner's production Nextcloud runs on the **same Docker host** as the dev stack, next to many unrelated production services:
- `nextcloud`: Nextcloud 35.0.2. `/var/www/html` is bind-mounted from `/HDD/docker-data/apps/nextcloud/data`, and its compose file is `/HDD/docker-data/apps/nextcloud/docker-compose.yaml`.
- `nextcloud-mariadb`: MariaDB 11.4.
- Public URL: https://cloud.vfontaine.ca (behind nginx proxy manager).

Another of the owner's apps, `vpass` (from `/HDD/development/V-Pass`), is installed there too and has its own update procedure.

**Ship every tested change to production (owner's standing instruction, 2026-10-20).**
- **When:** once a change is finished and its tests pass on the dev stack, install it on production so the owner can test it on their real instance. Don't wait to be asked.
- **Never ship** a change that fails its tests or wasn't tried on the dev stack.
- **Scope:** touch only the `apple_sync` app. Anything else on production (other apps, config, containers) needs the owner's explicit permission.
- **Viewer apps:** with 0.3.0 the owner approved enabling the apps the synced data shows up in ("enable as needed", 2026-10-20). Contacts (updated 8.4.4 → 8.9.1, the old one only supported NC ≤ 33), Calendar (6.2.2 → 6.6.2) and Photos are now enabled. Tasks is not installed yet: install it when Reminders ship. Mail and Talk stay disabled. Synced data is stored through Nextcloud's built-in DAV and files either way.
- **Background jobs on production** run from two host systemd timers. Both fire every 5 minutes and run `cron.php` through `docker exec`.
  - `murphy-nextcloud-cron.timer` runs all of Nextcloud's jobs, with background jobs in `cron` mode. It doesn't belong to this project; it appeared on 2026-10-21. Each of its runs spends 80+ minutes in `files_antivirus`'s `BackgroundScanner` (ClamAV at 100% CPU), and because it is a oneshot unit, it skips ticks until the run ends.
  - `apple-sync-cron.timer` and `.service` in `/etc/systemd/system/` (added with the owner's OK, 2026-10-21) run only `SchedulerJob` and `SyncJob`, through `cron.php -- <job classes>`. That way other apps' slow jobs can't hold up syncing. It also updates `lastcron`, so Nextcloud's "last job execution" looks fresh even while the main run is stuck.
  - Undo with `sudo systemctl disable --now apple-sync-cron.timer`. Leave the murphy timer and the antivirus app alone: changing either needs the owner's permission.

**Procedure:** `make deploy-prod` runs `scripts/deploy-prod.sh`. It does these steps in order and stops at the first failure:
1. **Preflight.** `occ status` must show no maintenance mode and no pending `needsDbUpgrade`; `occ upgrade` would apply anything pending. The version in `appinfo/info.xml` must be higher than the installed one. Nextcloud never downgrades an app, so never reuse or lower a version.
2. **Build.** `make dist` produces `build/apple_sync/` and `build/apple_sync-<version>.tar.gz`: appinfo, img, js, lib and templates, with source maps stripped. No vendor or tests.
3. **Back up.** `mariadb-dump --single-transaction` in `nextcloud-mariadb` writes `~/backups/nextcloud-db-pre-apple_sync-<version>-<timestamp>.sql.gz` (mode 600). The dump must end with "Dump completed".
4. **Swap.** `docker cp` the package to `/tmp/apple_sync-new`, `chown www-data`, and replace `/var/www/html/custom_apps/apple_sync`.
5. **Enable or upgrade.** A first install runs `occ app:enable apple_sync`; an update runs `occ upgrade`. The upgrade's exit code is ignored on purpose: Nextcloud 35's post-upgrade check replays the migrations of production's disabled Mail/Talk apps and ends with a harmless `Could not resolve OCP\AppFramework\Services\IAppConfig`. The real check is that `installed_version` equals the new version.
6. **Verify.**
   - No maintenance mode.
   - `status.php` returns 200 through the public URL.
   - `occ apple-sync:status` runs.
   - No new level ≥ 3 entries in `data/nextcloud.log`.

**Before running it:** bump `<version>` in `appinfo/info.xml`, using patch for fixes and minor for features. The dev instance mounts the same folder, so then run `docker compose exec -u www-data app php occ upgrade` there, or it answers 503.

**Report to the owner:** the version deployed, what changed and what to test, the backup path, and how to roll back. To roll back, restore the backup and copy in the previous `build/apple_sync-<version>.tar.gz`.

**Signing in on production:** the owner uses **Personal settings → Apple iCloud** in the browser. Don't ask them to use a terminal; they explicitly don't want that. `occ apple-sync:login` / `occ apple-sync:status --verify` remain as admin and debug tools.

## Commands

There is no PHP on the host: PHP runs in containers, and Composer runs in the `composer:2` image.

```sh
make up                     # dev Nextcloud 35 + Postgres 17 + cron on http://localhost:8089 (admin / admin-dev-pass)
make build                  # composer (dev deps, for tests only) + frontend build into js/
make enable                 # enable the app on dev
make apps                   # install Contacts, Calendar, Tasks and enable Photos on dev
make test                   # PHPUnit inside the dev app container (runs as the host uid)
make e2e                    # Playwright: throwaway Nextcloud (SQLite, :8091) + fake Apple (:8092)
make e2e-down               # remove the e2e stack
make lint                   # php -l over lib/
make login USER_ID=admin    # interactive iCloud sign-in on dev
# occ apple-sync:sync <user> [--service=contacts|calendars|drive|photos] [--no-limit] [--reset]   (debugging)
make status                 # accounts + live session check on dev
make srp-vectors            # regenerate tests/fixtures/srp_vectors.json (needs `pip install srp`)
make dist                   # installable package in build/
make deploy-prod            # install/update on production (see above)
```

- **Dev stack:** `docker-compose.yml`, compose project `v-apple-sync`. The repo is bind-mounted as `custom_apps/apple_sync`. Port 8089 is used because 8080 belongs to the V-Pass dev stack.
- **e2e stack:** `tests/e2e/docker-compose.yml` (project `apple-sync-e2e`).
  - `tests/e2e/setup.sh` points the app at `tests/e2e/fake-apple.php` through the `apple_sync_endpoints` system config value, read by `lib/ICloud/Endpoints.php`. It also allows local remote servers and turns off rate limiting.
  - The fake Apple server accepts any Apple ID except `wrong@example.com`. Device code is `123456`, text-message code `654321`. `POST /__control/expire` simulates the monthly trust expiry.
  - Every other path goes to `tests/Support/FakeICloud.php` (CardDAV, CalDAV, drivews/docws, CloudKit photos, downloads), the same fake the unit tests use. Its state is kept in `/tmp/fake-icloud.json` in the fake-apple container. `POST /__control/reset` restores the seed data, and `POST /__control/icloud` with `{"call": "addContact", "args": [...]}` changes it. The DAV app-specific password is `abcd-efgh-ijkl-mnop`.
  - `sync.spec.js` creates a fresh user per run and deletes it at the end, so runs don't affect each other and the `UserDeletedEvent` purge is exercised.
  - The e2e tests check the UI, the request flow and the real Nextcloud stores. Only a real Apple ID can check the real protocol.
- **MariaDB check:** production uses MariaDB, dev uses Postgres. For schema or query changes, start a throwaway Nextcloud 35 + `mariadb:11.4` stack with `build/apple_sync` mounted plus a `fake-apple` service like the e2e one. Configure it like `setup.sh` does (including disabling `firstrunwizard`), then run `sync.spec.js` with `E2E_URL` and `E2E_FAKE_APPLE` pointing at it. Never test against production.

## Architecture

- **`lib/ICloud/`**: the iCloud client. All Apple magic values are in `Constants.php`.
  - `Auth/Srp.php`: SRP-6a on ext-gmp, RFC 5054 2048-bit group, SHA-256, padded k and u, no username in x.
  - `Auth/PasswordDeriver.php`: the `s2k` / `s2k_fo` PBKDF2 key derivation.
  - `Auth/AuthService.php`: stateless sign-in, 2FA, trust, accountLogin and validate. All state lives in `Auth/WebSession.php` (tokens, scnt, service URLs, Guzzle `CookieJar`).
  - `Http/ICloudHttp.php`: the transport. It wraps `IClientService` and captures Apple's headers. It logs only method, host, path and status, never tokens or query strings.
- **`lib/Service/AccountService.php`**: persistence (all secrets encrypted with `ICrypto` through `SecretService`). It also has `ensureWebSession()`, which background work calls. It validates the stored session, re-signs in with the stored password and trust token, or marks the account `reauth_required`.
- **`lib/Db/`**: three tables.
  - `apple_sync_accounts`: one row per user, with secrets in `*_enc` columns.
  - `apple_sync_state`: one row per user and service, with an opaque `sync_cursor` and the lock.
  - `apple_sync_items`: maps each remote id to a local file or card/object, plus its etag.
- **`lib/Controller/ConnectController.php`**: the settings page API under `/apps/apple_sync/api/`. It covers status, web connect, verify, resend, phones, sms, cancel, check and disconnect.
  - Connect and disconnect need Nextcloud password confirmation (lax).
  - Apple-facing calls are rate-limited per user, so retries can't get the Apple ID locked.
  - A 2FA sign-in in progress lives encrypted in `pending_auth_enc` for 15 minutes. It remembers the previous state, so cancelling restores it.
  - Reconnecting with an empty password reuses the stored one, so only a new code is needed.
- **`lib/ICloud/Endpoints.php`**: Apple's auth, setup, CardDAV (`contacts`) and CalDAV (`caldav`) base URLs. Only the e2e stack overrides them.
- **`lib/Dav/`**: a small read-only CardDAV/CalDAV client for iCloud: discovery (principal → home set → collections), etag listing and multiget in batches of 50. It follows redirects by hand, because Apple sends you to partition hosts (`pNN-…`). Only a 401 counts as a rejected password.
- **`lib/ICloud/Drive/` and `lib/ICloud/Photos/`**: the drivews/docws and CloudKit (`com.apple.photos.cloud`, zone `PrimarySync`) clients.
- **`lib/Sync/`**: the sync engine.
  - `SyncRunner` runs one service for one user. It takes the DB lock (`locked_until`, extended every minute), runs the engine within a `SyncBudget` (time and bytes; the first download of a run always fits), and records the outcome. Errors back off 5 min × 2ⁿ up to 6 h (at least 30 min on 429/503). Three failures in a row send a notification.
  - `Engine/ContactsSync`, `Engine/CalendarsSync`, `Engine/DriveSync` and `Engine/PhotosSync` all resume from a JSON cursor in `apple_sync_state.sync_cursor`. `Engine/FileMirror` handles a file's download, move and adoption for Drive and Photos.
  - `Store/`: interfaces with Nextcloud implementations (`NcContactsStore`/`NcCalendarStore` on the internal `OCA\DAV` backends, `NcFileStore` on `IRootFolder`). The tests swap in memory versions.
  - **Behaviour:** iCloud always wins over local edits. Deletions in iCloud are kept in Nextcloud unless the user turns on "mirror deletions" for that service, which moves them to the NC trash bin. iCloud contact groups become CATEGORIES on the member cards.
- **`lib/BackgroundJob/`**: `SchedulerJob` queues a `SyncJob` for each due service. `SyncJob` runs with 240 s / 1 GB and requeues itself until finished.
  - Every service syncs every 5 minutes (`SyncState::INTERVALS`), once per cron tick.
  - Three details keep it at 5 minutes and not 10. The scheduler's own interval is 60 s, because `TimedJob` needs strictly more than its interval since the last run, and cron jitter would skip ticks. It queues what is due within `SchedulerJob::LOOKAHEAD` (120 s). And `next_run_at` counts from the start of a run, not its end.
- **`lib/Controller/SyncController.php`**: the "What to sync" API, `/api/sync`, `/api/sync/{service}` (settings, run, reset) and `/api/dav` (app-specific password). "Sync now" runs inline for up to 20 s / 300 MB per request, and the page keeps calling it while the answer is `partial`, so syncing works without cron.
- **`lib/Notification/Notifier.php`**: notifications for re-sign-in needed, app-specific password rejected, and repeated sync failures. They link to the settings page.
- **`lib/Command/`**: `occ` commands, kept for admins and debugging.
- **`lib/Settings/` and `src/`**: the personal settings page.
  - `src/components/WebConnect.vue` shows each state: form, code entry or phone choice, connected, reconnect.
  - `DavConnect.vue` handles the app-specific password. `SyncServices.vue` and `ServiceCard.vue` make up the "What to sync" section; it polls every 10 s while something runs.
  - `src/services/api.js` makes the API calls.

## Gotchas

- **`nextcloud/ocp` has no autoload rules.** `composer.json` maps `OCP\`/`NCU\` in `autoload-dev`. Guzzle is a dev dependency for tests only; at runtime the app uses Nextcloud's bundled Guzzle. Nextcloud never loads `vendor/`.
- **PHP minimum is 8.3**, because Nextcloud 35 requires it.
- **The column is `sync_cursor`, not `cursor`**: `CURSOR` is reserved in MySQL/MariaDB.
- **Frontend toolchain:** `@nextcloud/vite-config` 2.5 needs Vite 7 and TypeScript (its dts plugin crashes without it). Node 26 prints harmless `EBADENGINE` warnings. npm 12 blocks install scripts, which is fine for the build.
- **pysrp:** it loads its ctypes backend by default. The vector generator imports `srp._pysrp` explicitly and enables `rfc5054_enable()` and `no_username_in_x()` on it. Its private ephemeral `a` is 256 bytes.
- **New or changed routes need a version bump:** Nextcloud caches app routes per app version, so new controller routes return 404 until `<version>` goes up and `occ upgrade` runs.
- **Vue boolean props:** `:disabled="busy"` with `busy = ''` disables the button, because Vue treats `''` as `true` for boolean props and attributes. Use `!!busy`.
- **Rate limits in tests:** `ratelimit.protection.enabled=false` only stops *recording* attempts; ones already stored still count. The e2e stack sets it before any test runs. On an existing stack, clear `oc_ratelimit_entries`.
- **Nextcloud image startup:** `occ status` reports installed before the entrypoint starts Apache, so wait for `status.php` over HTTP too.
- **Settings page loads create a row:** loading the personal settings page calls `findOrCreate`, so `apple-sync:status` lists users who only opened the page, with state `disconnected`.
- **Test-only dev dependencies:** `sabre/vobject` and `doctrine/dbal` are in `require-dev`, because the engines parse vCards and mocking `IDBConnection` needs Doctrine's classes. At runtime Nextcloud's bundled copies are used.
- **One class per file in `tests/Support/`**: PSR-4 autoloading can't find extra classes declared in a shared file.
- **NcCheckboxRadioSwitch (`@nextcloud/vue` 9)** puts `data-test` on the hidden `<input role="switch">`, and its label covers the input. In Playwright, find it with `getByRole('switch')` and click the label text.
- **Dev `custom_apps/`** is created by Docker for the bind mount and owned by root, so `app:install` fails until it is `chown`ed to www-data. `make apps` does that.
- **Item counts** on the settings page (`ItemMapper::countSynced`) leave out Drive folders and iCloud contact groups.
- **The internal DAV backends** keep deleted calendars and objects in a trash bin. `NcCalendarStore` restores a trashed calendar and permanently deletes a trashed object before writing it again, because otherwise the URI or UID conflicts.
