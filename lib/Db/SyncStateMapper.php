<?php

declare(strict_types=1);

namespace OCA\AppleSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SyncState>
 */
class SyncStateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'apple_sync_state', SyncState::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(string $userId, string $service): SyncState {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('service', $qb->createNamedParameter($service, IQueryBuilder::PARAM_STR)));
		return $this->findEntity($qb);
	}

	public function findOrCreate(string $userId, string $service): SyncState {
		try {
			return $this->find($userId, $service);
		} catch (DoesNotExistException) {
			$state = new SyncState();
			$state->setUserId($userId);
			$state->setService($service);
			return $this->insert($state);
		}
	}

	/**
	 * @return SyncState[]
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		return $this->findEntities($qb);
	}

	/**
	 * Enabled services whose next run is due.
	 *
	 * @return SyncState[]
	 */
	public function findDue(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('next_run_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Atomically take the per-service lock. Returns false if another run holds it.
	 */
	public function tryLock(int $id, int $now, int $ttl): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('locked_until', $qb->createNamedParameter($now + $ttl, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('locked_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}

	/**
	 * Push out the lock of a run that is still working.
	 */
	public function extendLock(int $id, int $until): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('locked_until', $qb->createNamedParameter($until, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('locked_until', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function unlock(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('locked_until', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}
}
