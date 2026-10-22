<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Store;

use OCA\DAV\CardDAV\CardDavBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Contacts go through Nextcloud's own CardDAV backend (internal API, the same
 * one integration_google uses), so the Contacts app and DAV clients see them.
 */
class NcContactsStore implements ContactsStore {
	public function __construct(
		private CardDavBackend $backend,
		private IDBConnection $db,
	) {
	}

	public function ensureAddressBook(string $userId, string $uri, string $displayName, bool &$created = false): int {
		$principal = 'principals/users/' . $userId;
		$book = $this->backend->getAddressBooksByUri($principal, $uri);
		if ($book !== null) {
			$created = false;
			return (int)$book['id'];
		}
		$created = true;
		return (int)$this->backend->createAddressBook($principal, $uri, ['{DAV:}displayname' => $displayName]);
	}

	public function cardUris(int $addressBookId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uri')
			->from('cards')
			->where($qb->expr()->eq('addressbookid', $qb->createNamedParameter($addressBookId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$uris = [];
		while (($uri = $result->fetchOne()) !== false) {
			$uris[(string)$uri] = true;
		}
		$result->closeCursor();
		return $uris;
	}

	public function putCard(int $addressBookId, string $uri, string $data): void {
		if ($this->backend->getCard($addressBookId, $uri) !== false) {
			$this->backend->updateCard($addressBookId, $uri, $data);
			return;
		}
		// iCloud owns these cards: a UID that is also on a card left behind
		// by an earlier sync must not block the new one.
		$this->backend->createCard($addressBookId, $uri, $data, false);
	}

	public function deleteCard(int $addressBookId, string $uri): void {
		$this->backend->deleteCard($addressBookId, $uri);
	}
}
