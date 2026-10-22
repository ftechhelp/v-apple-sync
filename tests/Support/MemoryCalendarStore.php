<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Support;

use OCA\AppleSync\Sync\Store\CalendarStore;

final class MemoryCalendarStore implements CalendarStore {
	/** @var array<string, array{id: int, name: string, color: ?string, deleted: bool}> uri => calendar */
	public array $calendars = [];
	/** @var array<int, array<string, string>> calendar id => uri => iCalendar */
	public array $objects = [];
	public int $writes = 0;

	public function ensureCalendar(string $userId, string $uri, string $displayName, ?string $color, bool &$created = false): int {
		$created = !isset($this->calendars[$uri]);
		$id = $this->calendars[$uri]['id'] ?? count($this->calendars) + 1;
		$this->calendars[$uri] = ['id' => $id, 'name' => $displayName, 'color' => $color, 'deleted' => false];
		$this->objects[$id] ??= [];
		return $id;
	}

	public function objectUris(int $calendarId): array {
		return array_fill_keys(array_keys($this->objects[$calendarId] ?? []), true);
	}

	public function putObject(int $calendarId, string $uri, string $data): string {
		$this->writes++;
		$this->objects[$calendarId][$uri] = $data;
		return $uri;
	}

	public function deleteObject(int $calendarId, string $uri): void {
		unset($this->objects[$calendarId][$uri]);
	}

	public function deleteCalendar(int $calendarId): void {
		foreach ($this->calendars as $uri => $calendar) {
			if ($calendar['id'] === $calendarId) {
				$this->calendars[$uri]['deleted'] = true;
			}
		}
	}

	public function byName(string $name): ?array {
		foreach ($this->calendars as $calendar) {
			if ($calendar['name'] === $name) {
				return $calendar + ['objects' => $this->objects[$calendar['id']] ?? []];
			}
		}
		return null;
	}
}
