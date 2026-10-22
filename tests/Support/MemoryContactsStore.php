<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Support;

use OCA\AppleSync\Sync\Store\ContactsStore;

final class MemoryContactsStore implements ContactsStore {
	/** @var array<string, array{id: int, name: string}> uri => book */
	public array $books = [];
	/** @var array<int, array<string, string>> book id => uri => vCard */
	public array $cards = [];
	public int $writes = 0;

	public function ensureAddressBook(string $userId, string $uri, string $displayName, bool &$created = false): int {
		$created = !isset($this->books[$uri]);
		$this->books[$uri] ??= ['id' => count($this->books) + 1, 'name' => $displayName];
		$this->cards[$this->books[$uri]['id']] ??= [];
		return $this->books[$uri]['id'];
	}

	public function cardUris(int $addressBookId): array {
		return array_fill_keys(array_keys($this->cards[$addressBookId] ?? []), true);
	}

	public function putCard(int $addressBookId, string $uri, string $data): void {
		$this->writes++;
		$this->cards[$addressBookId][$uri] = $data;
	}

	public function deleteCard(int $addressBookId, string $uri): void {
		unset($this->cards[$addressBookId][$uri]);
	}

	/** Remove an address book, as a user deleting it in Nextcloud. */
	public function dropBook(string $uri): void {
		unset($this->cards[$this->books[$uri]['id']], $this->books[$uri]);
	}
}
