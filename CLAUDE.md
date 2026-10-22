# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

All project guidance (architecture, commands, status, production procedure, gotchas) is in AGENTS.md, so other coding agents read the same content:

@AGENTS.md

## Claude-specific notes

- The approved build plan is in `/home/vincent/.claude/plans/i-want-to-make-serialized-beacon.md`. Its phases are tracked in "Status" in AGENTS.md.
- Docker works without `sudo`. If the daemon is ever down, ask the user to start it rather than working around it.
- **After every change that is finished and passes its tests on the dev stack, install it on the owner's production Nextcloud (container `nextcloud`)** so they can test it there. Bump the version, run `make deploy-prod` (it backs up the DB first), then report the version, what to test, and the backup path. This is a standing instruction from the owner (2026-10-20), so don't wait to be asked. Never ship untested or failing changes, and touch nothing on production except the `apple_sync` app and the viewer apps the owner approved (see "Viewer apps" in AGENTS.md). Production cron is not running; ask before changing it.
