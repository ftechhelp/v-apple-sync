<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Store;

/**
 * Where synced contacts go in Nextcloud.
 */
interface ContactsStore {
	/**
	 * Id of the user's address book with this URI, created if missing.
	 *
	 * @param bool $created set to true when it had to be created
	 */
	public function ensureAddressBook(string $userId, string $uri, string $displayName, bool &$created = false): int;

	/**
	 * @return array<string, true> URIs of the cards in the address book
	 */
	public function cardUris(int $addressBookId): array;

	/**
	 * Create or replace a card.
	 */
	public function putCard(int $addressBookId, string $uri, string $data): void;

	public function deleteCard(int $addressBookId, string $uri): void;
}
