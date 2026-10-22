<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Store;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Reader;

/**
 * Events go through Nextcloud's own CalDAV backend (internal API, the same
 * one integration_google uses), so the Calendar app and DAV clients see them.
 */
class NcCalendarStore implements CalendarStore {
	private const DISPLAY_NAME = '{DAV:}displayname';
	private const COLOR = '{http://apple.com/ns/ical/}calendar-color';
	private const DELETED_AT = '{http://nextcloud.com/ns}deleted-at';

	public function __construct(
		private CalDavBackend $backend,
		private IDBConnection $db,
	) {
	}

	public function ensureCalendar(string $userId, string $uri, string $displayName, ?string $color, bool &$created = false): int {
		$principal = 'principals/users/' . $userId;
		$calendar = $this->backend->getCalendarByUri($principal, $uri);
		if ($calendar === null) {
			$created = true;
			$properties = [self::DISPLAY_NAME => $displayName, 'components' => 'VEVENT'];
			if ($color !== null) {
				$properties[self::COLOR] = $color;
			}
			return (int)$this->backend->createCalendar($principal, $uri, $properties);
		}

		$created = false;
		$id = (int)$calendar['id'];
		if (!empty($calendar[self::DELETED_AT])) {
			// Trashed in Nextcloud; iCloud still has it, so bring it back.
			$this->backend->restoreCalendar($id);
		}
		$changes = [];
		if (($calendar[self::DISPLAY_NAME] ?? null) !== $displayName) {
			$changes[self::DISPLAY_NAME] = $displayName;
		}
		if ($color !== null && strcasecmp((string)($calendar[self::COLOR] ?? ''), $color) !== 0) {
			$changes[self::COLOR] = $color;
		}
		if ($changes !== []) {
			$patch = new PropPatch($changes);
			$this->backend->updateCalendar($id, $patch);
			$patch->commit();
		}
		return $id;
	}

	public function objectUris(int $calendarId): array {
		$uris = [];
		foreach ($this->backend->getCalendarObjects($calendarId) as $object) {
			$uris[(string)$object['uri']] = true;
		}
		return $uris;
	}

	public function putObject(int $calendarId, string $uri, string $data): string {
		$existing = $this->backend->getCalendarObject($calendarId, $uri);
		if ($existing !== null && empty($existing[self::DELETED_AT])) {
			$this->backend->updateCalendarObject($calendarId, $uri, $data);
			return $uri;
		}
		if ($existing !== null) {
			// In Nextcloud's trash bin; iCloud still has it.
			$this->backend->deleteCalendarObject($calendarId, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
		}
		try {
			$this->backend->createCalendarObject($calendarId, $uri, $data);
			return $uri;
		} catch (Conflict $e) {
			// Same UID under another URI, e.g. an event left behind when
			// iCloud deleted and re-created it. iCloud's copy wins.
			$existing = $this->uriByUid($calendarId, $data);
			if ($existing === null) {
				throw $e;
			}
			$this->backend->updateCalendarObject($calendarId, $existing, $data);
			return $existing;
		}
	}

	public function deleteObject(int $calendarId, string $uri): void {
		$existing = $this->backend->getCalendarObject($calendarId, $uri);
		if ($existing !== null && empty($existing[self::DELETED_AT])) {
			$this->backend->deleteCalendarObject($calendarId, $uri);
		}
	}

	public function deleteCalendar(int $calendarId): void {
		$this->backend->deleteCalendar($calendarId);
	}

	private function uriByUid(int $calendarId, string $data): ?string {
		$uid = null;
		$calendar = Reader::read($data);
		foreach ($calendar->getComponents() as $component) {
			if (isset($component->UID)) {
				$uid = (string)$component->UID;
				break;
			}
		}
		if ($uid === null) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('uri')
			->from('calendarobjects')
			->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$uri = $result->fetchOne();
		$result->closeCursor();
		return $uri === false ? null : (string)$uri;
	}
}
