<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Store;

/**
 * Where synced calendars go in Nextcloud.
 */
interface CalendarStore {
	/**
	 * Id of the user's calendar with this URI, created if missing. Name and
	 * color are updated when they changed in iCloud.
	 *
	 * @param bool $created set to true when it had to be created
	 */
	public function ensureCalendar(string $userId, string $uri, string $displayName, ?string $color, bool &$created = false): int;

	/**
	 * @return array<string, true> URIs of the objects in the calendar
	 */
	public function objectUris(int $calendarId): array;

	/**
	 * Create or replace an event.
	 *
	 * @return string the URI it was stored under; differs from $uri when an
	 *                object with the same UID already existed under another URI
	 */
	public function putObject(int $calendarId, string $uri, string $data): string;

	public function deleteObject(int $calendarId, string $uri): void;

	/**
	 * Move the calendar to Nextcloud's calendar trash bin.
	 */
	public function deleteCalendar(int $calendarId): void;
}
