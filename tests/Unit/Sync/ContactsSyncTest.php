<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\Sync;

use OCA\AppleSync\Dav\DavAuthException;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Sync\Engine\ContactsSync;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncEngine;
use OCA\AppleSync\Tests\Support\MemoryContactsStore;
use Sabre\VObject\Reader;

class ContactsSyncTest extends EngineTestCase {
	private MemoryContactsStore $store;
	private string $password;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new MemoryContactsStore();
		$this->password = \OCA\AppleSync\Tests\Support\FakeICloud::APP_PASSWORD;
		$this->icloud->addContact('anna', 'Anna Apple');
		$this->icloud->addContact('bert', 'Bert Banana', ['CATEGORIES:Friends']);
		$this->icloud->addContact('carl', 'Carl Cherry');
		$this->icloud->addGroup('group-family', 'Family', ['anna', 'bert']);
	}

	protected function engine(): SyncEngine {
		return new ContactsSync($this->dav(), $this->accounts($this->password), $this->store, $this->items, $this->endpoints(), $this->time());
	}

	private function card(string $uid): ?array {
		$data = $this->store->cards[$this->store->books['icloud']['id']][$uid . '.vcf'] ?? null;
		if ($data === null) {
			return null;
		}
		$card = Reader::read($data);
		return [
			'name' => (string)$card->FN,
			'categories' => isset($card->CATEGORIES) ? $card->CATEGORIES->getParts() : [],
		];
	}

	public function testFirstSyncCopiesContactsAndTurnsGroupsIntoCategories(): void {
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->assertTrue($this->sync($state));

		$this->assertSame('iCloud', $this->store->books['icloud']['name']);
		$this->assertCount(3, $this->store->cards[1], 'the group card itself is not stored');
		$this->assertSame(['name' => 'Anna Apple', 'categories' => ['Family']], $this->card('anna'));
		$this->assertEqualsCanonicalizing(['Friends', 'Family'], $this->card('bert')['categories']);
		$this->assertSame([], $this->card('carl')['categories']);
		$this->assertSame(3, $this->items->countSynced(self::USER, SyncState::SERVICE_CONTACTS), 'groups are not counted');
	}

	public function testUnchangedAddressBookIsNotFetchedAgain(): void {
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->sync($state);
		$writes = $this->store->writes;
		$reports = $this->requests('REPORT');

		$this->assertTrue($this->sync($state));
		$this->assertSame($writes, $this->store->writes);
		$this->assertSame($reports, $this->requests('REPORT'));
		$this->assertSame(1, count(array_keys($this->icloud->log, 'PROPFIND /carddav/', true)), 'the home is remembered');
	}

	public function testChangedContactIsUpdated(): void {
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->sync($state);
		$writes = $this->store->writes;

		$this->icloud->addContact('carl', 'Carl Cranberry');
		$this->sync($state);
		$this->assertSame('Carl Cranberry', $this->card('carl')['name']);
		$this->assertSame($writes + 1, $this->store->writes, 'only the changed card is written');
	}

	public function testGroupChangeRewritesItsMembers(): void {
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->sync($state);

		$this->icloud->addGroup('group-family', 'Family', ['anna', 'carl']);
		$this->sync($state);
		$this->assertSame(['Family'], $this->card('carl')['categories']);
		$this->assertSame(['Friends'], $this->card('bert')['categories']);
	}

	public function testDeletedContactIsKeptUnlessMirroring(): void {
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->sync($state);
		unset($this->icloud->contacts['carl']);
		$this->sync($state);
		$this->assertNotNull($this->card('carl'));

		$mirror = $this->state(SyncState::SERVICE_CONTACTS, ['mirrorDeletions' => true]);
		$this->store = new MemoryContactsStore();
		$this->items->rows = [];
		$this->icloud->addContact('carl', 'Carl Cherry');
		$this->sync($mirror);
		unset($this->icloud->contacts['carl']);
		$this->sync($mirror);
		$this->assertNull($this->card('carl'));
	}

	public function testContactDeletedInNextcloudComesBack(): void {
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->sync($state);
		unset($this->store->cards[1]['anna.vcf']);
		$this->icloud->addContact('dora', 'Dora Date'); // changes the ctag

		$this->sync($state);
		$this->assertNotNull($this->card('anna'));
		$this->assertNotNull($this->card('dora'));
	}

	public function testAddressBookDeletedInNextcloudIsFilledAgain(): void {
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->sync($state);
		$this->store->dropBook('icloud');

		$this->sync($state);
		$this->assertCount(3, $this->store->cards[$this->store->books['icloud']['id']]);
	}

	public function testOutOfBudgetResumesWhereItStopped(): void {
		for ($i = 0; $i < 120; $i++) {
			$this->icloud->addContact("bulk-$i", "Bulk $i");
		}
		$state = $this->state(SyncState::SERVICE_CONTACTS);
		$this->assertFalse($this->sync($state, new SyncBudget(0, PHP_INT_MAX)));
		$this->assertTrue($this->sync($state));
		$this->assertCount(123, $this->store->cards[1]);
		$this->assertSame(123, $state->getItemsDone());
	}

	public function testRejectedPasswordIsReported(): void {
		$this->password = 'wrong';
		$this->expectException(DavAuthException::class);
		$this->sync($this->state(SyncState::SERVICE_CONTACTS));
	}
}
