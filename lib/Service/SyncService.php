<?php

declare(strict_types=1);

namespace OCA\AppleSync\Service;

use OCA\AppleSync\BackgroundJob\SyncJob;
use OCA\AppleSync\Db\ItemMapper;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\Sync\SyncRunner;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;

/**
 * Per-service settings and status for the settings page.
 */
class SyncService {
	/** Nextcloud app that shows each service's data. */
	private const VIEWER_APPS = [
		SyncState::SERVICE_CONTACTS => 'contacts',
		SyncState::SERVICE_CALENDARS => 'calendar',
		SyncState::SERVICE_DRIVE => 'files',
		SyncState::SERVICE_PHOTOS => 'photos',
	];

	/** Background jobs count as stalled when cron has not run for this long. */
	private const CRON_STALE_AFTER = 3600;

	public function __construct(
		private SyncStateMapper $states,
		private ItemMapper $items,
		private AccountService $accounts,
		private SyncRunner $runner,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{services: list<array>, cron: array}
	 */
	public function describeAll(string $userId): array {
		$services = [];
		foreach (SyncState::AVAILABLE_SERVICES as $service) {
			$services[] = $this->describe($this->states->findOrCreate($userId, $service));
		}
		return ['services' => $services, 'cron' => $this->cron()];
	}

	public function describe(SyncState $state): array {
		$now = $this->time->getTime();
		$service = $state->getService();
		$viewer = self::VIEWER_APPS[$service] ?? null;
		return [
			'service' => $service,
			'enabled' => (bool)$state->getEnabled(),
			'connected' => $this->runner->isConnected($this->accounts->getAccount($state->getUserId()), $service),
			'credential' => $state->usesDav() ? 'dav' : 'web',
			'target' => $state->getTargetOrDefault(),
			'mirrorDeletions' => $state->mirrorsDeletions(),
			'status' => $state->getStatus(),
			'running' => $state->getLockedUntil() > $now,
			'lastSuccessAt' => $state->getLastSuccessAt(),
			'nextRunAt' => $state->getNextRunAt(),
			'lastError' => $state->getLastError(),
			'itemsDone' => $state->getItemsDone(),
			'bytesDone' => $state->getBytesDone(),
			'itemCount' => $this->items->countSynced($state->getUserId(), $service),
			'viewerApp' => $viewer,
			'viewerEnabled' => $viewer === null || $this->appManager->isEnabledForUser($viewer),
		];
	}

	/**
	 * @throws \InvalidArgumentException for an unknown service or a bad target folder
	 */
	public function update(string $userId, string $service, ?bool $enabled, ?string $target, ?bool $mirrorDeletions): SyncState {
		$state = $this->state($userId, $service);
		if ($enabled !== null && $enabled !== (bool)$state->getEnabled()) {
			$state->setEnabled($enabled);
			if ($enabled) {
				$state->setNextRunAt(0);
				$state->setFailCount(0);
				if ($state->getStatus() === SyncState::STATUS_ERROR) {
					$state->setStatus(SyncState::STATUS_IDLE);
					$state->setLastError(null);
				}
			}
		}
		if ($target !== null && isset(SyncState::DEFAULT_TARGETS[$service])) {
			$target = self::normalizeTarget($target);
			if ($target !== $state->getTargetOrDefault()) {
				$state->setTarget($target);
				// A full pass moves what is already synced into the new folder.
				$state->setSyncCursor(null);
				$state->setStatus(SyncState::STATUS_IDLE);
				$state->setNextRunAt(0);
			}
		}
		if ($mirrorDeletions !== null) {
			$options = $state->getOptionsArray();
			$options['mirrorDeletions'] = $mirrorDeletions;
			$state->setOptionsArray($options);
		}
		return $this->states->update($state);
	}

	/**
	 * Forget the sync position so the next run compares everything again.
	 * Nothing is downloaded again that is already up to date.
	 */
	public function reset(string $userId, string $service): SyncState {
		$state = $this->state($userId, $service);
		$state->setSyncCursor(null);
		$state->setStatus(SyncState::STATUS_IDLE);
		$state->setNextRunAt(0);
		$state->setFailCount(0);
		$state->setLastError(null);
		$state->setItemsDone(0);
		$state->setBytesDone(0);
		return $this->states->update($state);
	}

	/**
	 * Let background jobs continue a run the settings page started.
	 */
	public function queue(string $userId, string $service): void {
		$arguments = ['userId' => $userId, 'service' => $service];
		if (!$this->jobList->has(SyncJob::class, $arguments)) {
			$this->jobList->add(SyncJob::class, $arguments);
		}
	}

	public function state(string $userId, string $service): SyncState {
		if (!in_array($service, SyncState::AVAILABLE_SERVICES, true)) {
			throw new \InvalidArgumentException('Unknown service');
		}
		return $this->states->findOrCreate($userId, $service);
	}

	/**
	 * Whether Nextcloud's background jobs run, since they do the automatic syncing.
	 */
	private function cron(): array {
		try {
			$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
			$lastRun = $this->appConfig->getValueInt('core', 'lastcron', 0);
		} catch (\Throwable) {
			return ['mode' => null, 'lastRun' => null, 'stale' => false];
		}
		return [
			'mode' => $mode,
			'lastRun' => $lastRun > 0 ? $lastRun : null,
			'stale' => $lastRun < $this->time->getTime() - self::CRON_STALE_AFTER,
		];
	}

	private static function normalizeTarget(string $target): string {
		$parts = array_values(array_filter(explode('/', str_replace('\\', '/', trim($target))), static fn (string $part): bool => $part !== '' && $part !== '.'));
		if ($parts === [] || in_array('..', $parts, true)) {
			throw new \InvalidArgumentException('Choose a folder inside your files');
		}
		return '/' . implode('/', $parts);
	}
}
