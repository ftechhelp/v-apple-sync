<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Support;

use OCA\AppleSync\Db\Item;
use OCA\AppleSync\Db\ItemMapper;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;

/**
 * ItemMapper without a database.
 */
final class MemoryItemMapper extends ItemMapper {
	/** @var array<int, Item> */
	public array $rows = [];
	private int $nextId = 1;

	public function __construct(IDBConnection $db) {
		parent::__construct($db);
	}

	public function save(Item $item): Item {
		if ($item->getId() === null) {
			$item->setId($this->nextId++);
		}
		$this->rows[$item->getId()] = clone $item;
		return $item;
	}

	public function insert(Entity $entity): Entity {
		return $this->save($entity);
	}

	public function update(Entity $entity): Entity {
		return $this->save($entity);
	}

	public function delete(Entity $entity): Entity {
		unset($this->rows[$entity->getId()]);
		return $entity;
	}

	public function findByRemoteId(string $userId, string $service, string $remoteId): ?Item {
		foreach ($this->rows as $item) {
			if ($item->getUserId() === $userId && $item->getService() === $service && $item->getRemoteId() === $remoteId) {
				return clone $item;
			}
		}
		return null;
	}

	public function findByCollection(string $userId, string $service, string $collection): array {
		return $this->where(static fn (Item $item): bool => $item->getUserId() === $userId && $item->getService() === $service && $item->getCollection() === $collection);
	}

	public function findByRemoteIds(string $userId, string $service, array $remoteIds): array {
		$wanted = array_flip($remoteIds);
		$found = [];
		foreach ($this->where(static fn (Item $item): bool => $item->getUserId() === $userId && $item->getService() === $service && isset($wanted[$item->getRemoteId()])) as $item) {
			$found[$item->getRemoteId()] = $item;
		}
		return $found;
	}

	public function findNotSeenSince(string $userId, string $service, int $since, ?string $collection = null, int $limit = 500): array {
		return array_slice($this->where(static fn (Item $item): bool => $item->getUserId() === $userId && $item->getService() === $service
			&& $item->getUpdatedAt() < $since && ($collection === null || $item->getCollection() === $collection)), 0, $limit);
	}

	public function markSeen(array $ids, int $seenAt): void {
		foreach ($ids as $id) {
			if (isset($this->rows[$id])) {
				$this->rows[$id]->setUpdatedAt($seenAt);
			}
		}
	}

	public function deleteByCollection(string $userId, string $service, string $collection): void {
		foreach ($this->findByCollection($userId, $service, $collection) as $item) {
			unset($this->rows[$item->getId()]);
		}
	}

	public function countSynced(string $userId, string $service): int {
		return count($this->where(static fn (Item $item): bool => $item->getUserId() === $userId && $item->getService() === $service
			&& $item->getLocalRef() !== null && $item->getCollection() !== Item::COLLECTION_FOLDER));
	}

	public function deleteByUser(string $userId, ?string $service = null): void {
		foreach ($this->rows as $id => $item) {
			if ($item->getUserId() === $userId && ($service === null || $item->getService() === $service)) {
				unset($this->rows[$id]);
			}
		}
	}

	/**
	 * @return list<Item>
	 */
	private function where(callable $match): array {
		return array_values(array_map(static fn (Item $item): Item => clone $item, array_filter($this->rows, $match)));
	}
}
