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
use OCA\AppleSync\Sync\Store\ContactsStore;
use OCA\AppleSync\Sync\SyncContext;
use OCA\AppleSync\Sync\SyncEngine;
use OCP\AppFramework\Utility\ITimeFactory;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * iCloud contacts → the Nextcloud address book "iCloud".
 *
 * Address books whose ctag did not change are skipped. Otherwise members are
 * compared by etag and changed cards fetched with addressbook-multiget.
 * iCloud groups are cards of their own (X-ADDRESSBOOKSERVER-KIND:group);
 * Nextcloud's Contacts app uses CATEGORIES instead, so group names are added
 * to their members' CATEGORIES and the group cards themselves are not stored.
 *
 * Items: collection = address book path, remote id = card path,
 * local ref = card URI (null for groups and unreadable cards),
 * local path = the card's UID, etag = remote etag + hash of the groups written.
 */
class ContactsSync implements SyncEngine {
	use DavSupport;

	private const SERVICE = SyncState::SERVICE_CONTACTS;
	private const BATCH = 50;
	/** Rounds of fetching when group changes require rewriting their members. */
	private const MAX_PASSES = 3;

	public function __construct(
		private DavClient $dav,
		private AccountService $accounts,
		private ContactsStore $store,
		private ItemMapper $items,
		private Endpoints $endpoints,
		private ITimeFactory $time,
	) {
	}

	public function run(SyncContext $context): bool {
		$credentials = $this->credentials($this->accounts, $context);
		$cursor = $context->cursor();
		$books = $this->collections($this->dav, $credentials, $this->endpoints->contacts, DavClient::KIND_CONTACTS, $cursor);

		foreach ($books as $book) {
			$key = ItemMapper::key($book->path);
			$isDefault = basename(rtrim($book->path, '/')) === 'card' || count($books) === 1;
			$created = false;
			$bookId = $this->store->ensureAddressBook(
				$context->userId,
				$isDefault ? 'icloud' : 'icloud-' . substr(sha1($book->path), 0, 10),
				$isDefault ? 'iCloud' : 'iCloud – ' . ($book->displayName !== '' ? $book->displayName : 'Contacts'),
				$created,
			);
			if ($created) {
				// New, or deleted in Nextcloud since the last sync: fill it again.
				$this->items->deleteByCollection($context->userId, self::SERVICE, $key);
				unset($cursor['books'][$key], $cursor['groups'][$key]);
			}
			if ($book->ctag !== null && ($cursor['books'][$key]['ctag'] ?? null) === $book->ctag) {
				continue;
			}

			$groups = $cursor['groups'][$key] ?? [];
			$done = $this->syncBook($context, $credentials, $book, $bookId, $groups, $cursor);
			$cursor['groups'][$key] = $groups;
			if (!$done) {
				$context->saveCursor($cursor);
				return false;
			}
			$cursor['books'][$key] = ['ctag' => $book->ctag];
			$context->saveCursor($cursor);
		}
		$context->saveCursor($cursor);
		return true;
	}

	/**
	 * @param array<string, array{name: string, members: list<string>}> $groups group card path => group; updated in place
	 */
	private function syncBook(SyncContext $context, DavCredentials $credentials, DavCollection $book, int $bookId, array &$groups, array &$cursor): bool {
		$userId = $context->userId;
		$key = ItemMapper::key($book->path);
		$remote = $this->dav->listMembers($credentials, $book);
		$known = $this->knownItems($this->items, $userId, self::SERVICE, $key);

		// Deletions first: a removed group changes its members' categories.
		$remoteKeys = self::remoteKeys($remote);
		foreach ($known as $remoteId => $item) {
			if (isset($remoteKeys[$remoteId])) {
				continue;
			}
			unset($groups[$remoteId]);
			if ($item->getLocalRef() !== null && $context->mirrorDeletions()) {
				$this->store->deleteCard($bookId, $item->getLocalRef());
			}
			$this->items->delete($item);
			unset($known[$remoteId]);
		}

		$localUris = $this->store->cardUris($bookId);
		for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
			$categories = self::categoriesByUid($groups);
			$changed = $this->changedMembers($remote, $known, $localUris, static fn (Item $item, string $etag): string => $item->getLocalRef() === null
				? $etag
				: self::etagWithGroups($etag, $categories[(string)$item->getLocalPath()] ?? []));
			if ($changed === []) {
				return true;
			}
			foreach (array_chunk($changed, self::BATCH) as $batch) {
				if ($context->shouldStop()) {
					return false;
				}
				$cards = $this->dav->multiget($credentials, $book, DavClient::KIND_CONTACTS, $batch);
				$this->store($context, $cards, $bookId, $key, $groups, $known, $localUris);
				$cursor['groups'][$key] = $groups;
				$context->saveCursor($cursor);
			}
		}
		return true;
	}

	/**
	 * @param array<string, array{etag: string, data: string}> $cards
	 * @param array<string, array{name: string, members: list<string>}> $groups
	 * @param array<string, Item> $known
	 * @param array<string, true> $localUris
	 */
	private function store(SyncContext $context, array $cards, int $bookId, string $collection, array &$groups, array &$known, array &$localUris): void {
		$now = $this->time->getTime();
		$contacts = [];
		// Groups first, so members in the same batch get the right categories.
		foreach ($cards as $path => $card) {
			try {
				$vcard = Reader::read($card['data']);
			} catch (\Throwable) {
				$context->skip('a contact could not be read');
				$this->record($this->items, $known, $context->userId, self::SERVICE, $collection, $path, $card['etag'], null, null, null, $now);
				continue;
			}
			if (!$vcard instanceof VCard) {
				continue;
			}
			if (strtolower((string)($vcard->{'X-ADDRESSBOOKSERVER-KIND'} ?? '')) === 'group') {
				$groups[ItemMapper::key($path)] = self::group($vcard);
				$this->record($this->items, $known, $context->userId, self::SERVICE, $collection, $path, $card['etag'], null, null, null, $now);
			} else {
				$contacts[$path] = [$card['etag'], $vcard];
			}
		}

		$categories = self::categoriesByUid($groups);
		foreach ($contacts as $path => [$etag, $vcard]) {
			$uid = (string)($vcard->UID ?? '');
			$groupNames = $uid === '' ? [] : ($categories[$uid] ?? []);
			if ($groupNames !== []) {
				$existing = isset($vcard->CATEGORIES) ? $vcard->CATEGORIES->getParts() : [];
				$vcard->remove('CATEGORIES');
				$vcard->add('CATEGORIES', array_values(array_unique(array_merge($existing, $groupNames))));
			}
			$uri = self::objectUri($path, '.vcf');
			try {
				$this->store->putCard($bookId, $uri, $vcard->serialize());
			} catch (\Throwable $e) {
				$context->skip('a contact could not be saved: ' . $e->getMessage());
				$this->record($this->items, $known, $context->userId, self::SERVICE, $collection, $path, $etag, null, null, $uid, $now);
				continue;
			}
			$localUris[$uri] = true;
			$this->record($this->items, $known, $context->userId, self::SERVICE, $collection, $path, self::etagWithGroups($etag, $groupNames), $uri, $bookId, $uid, $now);
			$context->countItem();
		}
	}

	/**
	 * @return array{name: string, members: list<string>}
	 */
	private static function group(VCard $vcard): array {
		$members = [];
		foreach ($vcard->select('X-ADDRESSBOOKSERVER-MEMBER') as $member) {
			$members[] = preg_replace('/^urn:uuid:/i', '', trim((string)$member)) ?? '';
		}
		return [
			'name' => trim((string)($vcard->FN ?? $vcard->N ?? 'Group')),
			'members' => array_values(array_filter($members)),
		];
	}

	/**
	 * @param array<string, array{name: string, members: list<string>}> $groups
	 * @return array<string, list<string>> member UID => sorted group names
	 */
	private static function categoriesByUid(array $groups): array {
		$byUid = [];
		foreach ($groups as $group) {
			foreach ($group['members'] as $uid) {
				$byUid[$uid][] = $group['name'];
			}
		}
		foreach ($byUid as &$names) {
			$names = array_values(array_unique($names));
			sort($names);
		}
		return $byUid;
	}

	/**
	 * The stored etag also covers the group names written into the card, so
	 * a group change rewrites its members.
	 *
	 * @param list<string> $groupNames
	 */
	private static function etagWithGroups(string $etag, array $groupNames): string {
		return $groupNames === [] ? $etag : $etag . '|' . substr(md5(implode("\n", $groupNames)), 0, 12);
	}
}
