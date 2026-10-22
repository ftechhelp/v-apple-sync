# Apple iCloud Sync for Nextcloud

One-way sync of iCloud data into Nextcloud 34/35. Nothing is ever written back to iCloud.

| Data | How | Credential | Status |
|---|---|---|---|
| Contacts | CardDAV → Nextcloud address book "iCloud"; iCloud groups become contact groups | app-specific password | **done** |
| Calendars | CalDAV → one Nextcloud calendar per iCloud calendar | app-specific password | **done** |
| iCloud Drive | iCloud web API → `/iCloud Drive` (folder can be changed) | Apple ID password + 2FA | **done** |
| Photos | iCloud web API (CloudKit) → `/iCloud Photos/YYYY/MM`, Live Photos with their video | Apple ID password + 2FA | **done** |
| Reminders | iCloud web API (CloudKit) → task lists in the Tasks app | Apple ID password + 2FA | planned |
| Web sign-in | SRP-6a + two-factor + trust token, from **Personal settings → Apple iCloud** | Apple ID password + 2FA | **done** |

## iCloud requirements

- Two-factor authentication on the Apple ID.
- **Settings → [your name] → iCloud → Access iCloud Data on the Web** must be ON.
- **Advanced Data Protection** must be OFF (support is planned).
- App-specific passwords do **not** work for Photos/Drive/Reminders, which need your real Apple ID password. They are only used for Contacts/Calendars.
- Apple's trust token lasts roughly 30 days, so expect to re-enter a 2FA code about once a month.

## How syncing works

Everything is set up under **Personal settings → Apple iCloud → What to sync**. Turning a service on syncs it right away. After that, Nextcloud's background jobs sync every turned-on service every 5 minutes, so the server needs [cron](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html) running `cron.php` every 5 minutes. "Sync now" works without cron.

Nextcloud runs background jobs one at a time, so a slow job from another app (an antivirus scan, for example) delays syncing until it finishes. To keep syncing on schedule regardless, an administrator can run the app's jobs in a cron run of their own, every 5 minutes, next to the regular one:

```sh
php -f /var/www/html/cron.php -- 'OCA\AppleSync\BackgroundJob\SchedulerJob' 'OCA\AppleSync\BackgroundJob\SyncJob'
```

- iCloud always wins: edits made in Nextcloud to synced items are overwritten on the next change in iCloud. File versions keep the history.
- Items deleted in iCloud are **kept** in Nextcloud, unless "Also delete in Nextcloud what is deleted in iCloud" is on for that service.
- Large libraries are synced in chunks. The first Photos import can take a while.

## Development

Requires Docker, Node 24+ and npm.

```sh
make up        # Nextcloud 35 on http://localhost:8089 (admin / admin-dev-pass)
make build     # composer deps (dev only) + frontend build
make enable    # enable the app
make apps      # install Contacts, Calendar, Tasks; enable Photos
make test      # PHPUnit
make e2e       # browser tests against a throwaway Nextcloud + fake Apple
make login USER_ID=admin   # interactive iCloud sign-in
make status    # show accounts and verify the iCloud session
```

The SRP implementation is checked against vectors generated with
[pysrp](https://pypi.org/project/srp/) configured as pyicloud uses it
(`make srp-vectors` regenerates them).

## Credits

The iCloud web API is undocumented. This app's protocol handling is ported from
[rclone](https://github.com/rclone/rclone)'s iclouddrive backend and the
[timlaing/pyicloud](https://github.com/timlaing/pyicloud) fork. Its overall
structure follows [nextcloud/integration_google](https://github.com/nextcloud/integration_google).
