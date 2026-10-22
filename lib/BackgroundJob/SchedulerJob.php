<?php

declare(strict_types=1);

namespace OCA\AppleSync\BackgroundJob;

use OCA\AppleSync\Db\AccountMapper;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\Sync\SyncRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;

/**
 * Every 5 minutes: queue a SyncJob for each enabled service that is due and
 * whose credentials are connected.
 */
class SchedulerJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SyncStateMapper $states,
		private AccountMapper $accounts,
		private SyncRunner $runner,
		private IJobList $jobList,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$now = $this->time->getTime();
		$accounts = [];
		foreach ($this->states->findDue($now) as $state) {
			if (!in_array($state->getService(), SyncState::AVAILABLE_SERVICES, true) || $state->getLockedUntil() > $now) {
				continue;
			}
			$userId = $state->getUserId();
			if (!array_key_exists($userId, $accounts)) {
				try {
					$accounts[$userId] = $this->accounts->findByUser($userId);
				} catch (DoesNotExistException) {
					$accounts[$userId] = null;
				}
			}
			if ($accounts[$userId] === null || !$this->runner->isConnected($accounts[$userId], $state->getService())) {
				continue;
			}
			$arguments = ['userId' => $userId, 'service' => $state->getService()];
			if (!$this->jobList->has(SyncJob::class, $arguments)) {
				$this->jobList->add(SyncJob::class, $arguments);
			}
		}
	}
}
