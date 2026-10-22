<?php

declare(strict_types=1);

namespace OCA\AppleSync\BackgroundJob;

use OCA\AppleSync\Sync\RunOutcome;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * One run of one service for one user. Queues itself again while there is
 * work left, so large first imports continue on the next cron run.
 *
 * Argument: {userId, service}
 */
class SyncJob extends QueuedJob {
	private const SECONDS = 240;
	private const BYTES = 1024 * 1024 * 1024;

	public function __construct(
		ITimeFactory $time,
		private SyncRunner $runner,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$userId = (string)($argument['userId'] ?? '');
		$service = (string)($argument['service'] ?? '');
		if ($userId === '' || $service === '') {
			return;
		}
		$outcome = $this->runner->run($userId, $service, SyncBudget::seconds(self::SECONDS, self::BYTES));
		if ($outcome === RunOutcome::Partial) {
			$this->jobList->add(self::class, ['userId' => $userId, 'service' => $service]);
		}
	}
}
