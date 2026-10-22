<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\Sync;

use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Sync\Engine\CalendarsSync;
use OCA\AppleSync\Sync\SyncEngine;
use OCA\AppleSync\Tests\Support\MemoryCalendarStore;

class CalendarsSyncTest extends EngineTestCase {
	private MemoryCalendarStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new MemoryCalendarStore();
		$this->icloud->addCalendar('home', 'Home', '#FF2968FF');
		$this->icloud->addCalendar('work', 'Work');
		$this->icloud->addEvent('home', 'dentist', 'Dentist');
		$this->icloud->addEvent('home', 'party', 'Party');
		$this->icloud->addEvent('work', 'standup', 'Standup');
	}

	protected function engine(): SyncEngine {
		return new CalendarsSync($this->dav(), $this->accounts(), $this->store, $this->items, $this->endpoints(), $this->time());
	}

	public function testCalendarsAndEventsAreCopied(): void {
		$this->assertTrue($this->sync($this->state(SyncState::SERVICE_CALENDARS)));

		$home = $this->store->byName('Home (iCloud)');
		$this->assertSame('#FF2968', $home['color'], 'Apple colors lose their alpha');
		$this->assertSame(['dentist.ics', 'party.ics'], array_keys($home['objects']));
		$this->assertStringContainsString('SUMMARY:Dentist', $home['objects']['dentist.ics']);
		$this->assertCount(1, $this->store->byName('Work (iCloud)')['objects']);
		$this->assertNull($this->store->byName('Reminders (iCloud)'), 'task-only lists are not calendars');
		$this->assertCount(2, $this->store->calendars);
	}

	public function testOnlyChangedCalendarsAreFetched(): void {
		$state = $this->state(SyncState::SERVICE_CALENDARS);
		$this->sync($state);
		$reports = $this->requests('REPORT');

		$this->icloud->addEvent('work', 'review', 'Review');
		$this->sync($state);
		$this->assertSame($reports + 1, $this->requests('REPORT'));
		$this->assertCount(2, $this->store->byName('Work (iCloud)')['objects']);
	}

	public function testRenamedCalendarKeepsItsEvents(): void {
		$state = $this->state(SyncState::SERVICE_CALENDARS);
		$this->sync($state);
		$this->icloud->calendars['work']['name'] = 'Office';

		$this->sync($state);
		$this->assertNull($this->store->byName('Work (iCloud)'));
		$this->assertCount(1, $this->store->byName('Office (iCloud)')['objects']);
	}

	public function testDeletionsAreMirroredOnlyWhenAskedFor(): void {
		$state = $this->state(SyncState::SERVICE_CALENDARS);
		$this->sync($state);
		unset($this->icloud->calendars['home']['events']['party']);
		unset($this->icloud->calendars['work']);
		$this->sync($state);
		$this->assertArrayHasKey('party.ics', $this->store->byName('Home (iCloud)')['objects']);
		$this->assertFalse($this->store->byName('Work (iCloud)')['deleted']);

		$this->icloud->addEvent('home', 'party', 'Party');
		$this->icloud->addCalendar('work', 'Work');
		$mirror = $this->state(SyncState::SERVICE_CALENDARS, ['mirrorDeletions' => true]);
		$this->sync($mirror);
		unset($this->icloud->calendars['home']['events']['party']);
		unset($this->icloud->calendars['work']);
		$this->sync($mirror);
		$this->assertArrayNotHasKey('party.ics', $this->store->byName('Home (iCloud)')['objects']);
		$this->assertTrue($this->store->byName('Work (iCloud)')['deleted']);
	}

	public function testFollowsTheEntryPointRedirect(): void {
		$this->sync($this->state(SyncState::SERVICE_CALENDARS));
		$this->assertContains('PROPFIND /caldav/p01/', $this->icloud->log);
	}
}
