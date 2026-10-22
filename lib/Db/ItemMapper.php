<?php

declare(strict_types=1);

namespace OCA\AppleSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Item>
 */
class ItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'apple_sync_items', Item::class);
	}

	/**
	 * Insert a new item or update an existing one.
	 */
	public function save(Item $item): Item {
		return $item->getId() === null ? $this->insert($item) : $this->update($item);
	}

	/**
	 * Remote ids and collections are URL paths, record names or drivewsids;
	 * the rare one longer than the column is stored as a hash.
	 */
	public static function key(string $id): string {
		return strlen($id) <= 255 ? $id : 'sha1:' . sha1($id);
	}

	public function findByRemoteId(string $userId, string $service, string $remoteId): ?Item {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('remote_id', $qb->createNamedParameter($remoteId, IQueryBuilder::PARAM_STR)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return Item[]
	 */
	public function findByCollection(string $userId, string $service, string $collection): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection, IQueryBuilder::PARAM_STR)));
		return $this->findEntities($qb);
	}

	/**
	 * @param list<string> $remoteIds
	 * @return array<string, Item> keyed by remote id
	 */
	public function findByRemoteIds(string $userId, string $service, array $remoteIds): array {
		$found = [];
		foreach (array_chunk(array_values(array_unique($remoteIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->in('remote_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			foreach ($this->findEntities($qb) as $item) {
				$found[$item->getRemoteId()] = $item;
			}
		}
		return $found;
	}

	/**
	 * Items of a service not marked as seen since $since, oldest first.
	 *
	 * @return Item[]
	 */
	public function findNotSeenSince(string $userId, string $service, int $since, ?string $collection = null, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('id')
			->setMaxResults($limit);
		if ($collection !== null) {
			$qb->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection, IQueryBuilder::PARAM_STR)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Mark items as seen during the pass that started at $seenAt.
	 *
	 * @param list<int> $ids
	 */
	public function markSeen(array $ids, int $seenAt): void {
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('updated_at', $qb->createNamedParameter($seenAt, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteByCollection(string $userId, string $service, string $collection): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter($collection, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}

	/**
	 * Items that have a copy in Nextcloud: not folders, iCloud contact groups
	 * (they become categories) or items that could not be stored.
	 */
	public function countSynced(string $userId, string $service): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->isNotNull('local_ref'))
			->andWhere($qb->expr()->neq('collection', $qb->createNamedParameter(Item::COLLECTION_FOLDER, IQueryBuilder::PARAM_STR)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	public function deleteByUser(string $userId, ?string $service = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		if ($service !== null) {
			$qb->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)));
		}
		$qb->executeStatement();
	}
}
