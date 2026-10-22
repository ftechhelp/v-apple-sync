<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Engine;

use OCA\AppleSync\Dav\DavClient;
use OCA\AppleSync\Dav\DavCollection;
use OCA\AppleSync\Dav\DavCredentials;
use OCA\AppleSync\Db\Item;
use OCA\AppleSync\Db\ItemMapper;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\ICloud\Endpoints;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Sync\Store\CalendarStore;
use OCA\AppleSync\Sync\SyncContext;
use OCA\AppleSync\Sync\SyncEngine;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * iCloud calendars → one Nextcloud calendar each, named "<name> (iCloud)",
 * keeping Apple's color. Calendars whose ctag did not change are skipped;
 * otherwise events are compared by etag and fetched with calendar-multiget.
 *
 * Items: collection = calendar path, remote id = event path,
 * local ref = object URI in Nextcloud (null if Nextcloud refused the event),
 * local container = Nextcloud calendar id.
 */
class CalendarsSync implements SyncEngine {
	use DavSupport;

	private const SERVICE = SyncState::SERVICE_CALENDARS;
	private const BATCH = 50;

	public function __construct(
		private DavClient $dav,
		private AccountService $accounts,
		private CalendarStore $store,
		private ItemMapper $items,
		private Endpoints $endpoints,
		private ITimeFactory $time,
	) {
	}

	public function run(SyncContext $context): bool {
		$credentials = $this->credentials($this->accounts, $context);
		$cursor = $context->cursor();
		$calendars = $this->collections($this->dav, $credentials, $this->endpoints->caldav, DavClient::KIND_CALENDARS, $cursor);

		$current = [];
		foreach ($calendars as $calendar) {
			$key = ItemMapper::key($calendar->path);
			$current[$key] = true;
			$created = false;
			$calendarId = $this->store->ensureCalendar(
				$context->userId,
				'icloud-' . substr(sha1($calendar->path), 0, 10),
				($calendar->displayName !== '' ? $calendar->displayName : 'Calendar') . ' (iCloud)',
				self::color($calendar->color),
				$created,
			);
			if ($created) {
				$this->items->deleteByCollection($context->userId, self::SERVICE, $key);
				unset($cursor['calendars'][$key]);
			}
			if ($calendar->ctag !== null && ($cursor['calendars'][$key]['ctag'] ?? null) === $calendar->ctag) {
				continue;
			}
			if (!$this->syncCalendar($context, $credentials, $calendar, $calendarId, $cursor)) {
				$cursor['calendars'][$key] = ['ctag' => null, 'id' => $calendarId];
				$context->saveCursor($cursor);
				return false;
			}
			$cursor['calendars'][$key] = ['ctag' => $calendar->ctag, 'id' => $calendarId];
			$context->saveCursor($cursor);
		}

		// Calendars deleted in iCloud.
		foreach ($cursor['calendars'] ?? [] as $key => $known) {
			if (isset($current[$key])) {
				continue;
			}
			if ($context->mirrorDeletions() && isset($known['id'])) {
				$this->store->deleteCalendar((int)$known['id']);
			}
			$this->items->deleteByCollection($context->userId, self::SERVICE, (string)$key);
			unset($cursor['calendars'][$key]);
		}
		$context->saveCursor($cursor);
		return true;
	}

	private function syncCalendar(SyncContext $context, DavCredentials $credentials, DavCollection $calendar, int $calendarId, array &$cursor): bool {
		$userId = $context->userId;
		$key = ItemMapper::key($calendar->path);
		$remote = $this->dav->listMembers($credentials, $calendar);
		$known = $this->knownItems($this->items, $userId, self::SERVICE, $key);

		$remoteKeys = self::remoteKeys($remote);
		foreach ($known as $remoteId => $item) {
			if (isset($remoteKeys[$remoteId])) {
				continue;
			}
			if ($item->getLocalRef() !== null && $context->mirrorDeletions()) {
				$this->store->deleteObject($calendarId, $item->getLocalRef());
			}
			$this->items->delete($item);
			unset($known[$remoteId]);
		}

		$localUris = $this->store->objectUris($calendarId);
		$changed = $this->changedMembers($remote, $known, $localUris, static fn (Item $item, string $etag): string => $etag);
		foreach (array_chunk($changed, self::BATCH) as $batch) {
			if ($context->shouldStop()) {
				return false;
			}
			$now = $this->time->getTime();
			foreach ($this->dav->multiget($credentials, $calendar, DavClient::KIND_CALENDARS, $batch) as $path => $event) {
				try {
					$uri = $this->store->putObject($calendarId, self::objectUri($path, '.ics'), $event['data']);
				} catch (\Throwable $e) {
					$context->skip('an event could not be saved: ' . $e->getMessage());
					$this->record($this->items, $known, $userId, self::SERVICE, $key, $path, $event['etag'], null, $calendarId, null, $now);
					continue;
				}
				$this->record($this->items, $known, $userId, self::SERVICE, $key, $path, $event['etag'], $uri, $calendarId, null, $now);
				$context->countItem();
			}
			$context->saveCursor($cursor);
		}
		return true;
	}

	/**
	 * Apple sends #RRGGBBAA; Nextcloud's Calendar app expects #RRGGBB.
	 */
	private static function color(?string $color): ?string {
		if ($color === null || !preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/i', $color, $m)) {
			return null;
		}
		return '#' . strtoupper($m[1]);
	}
}
