<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync;

use OCA\AppleSync\Dav\DavAuthException;
use OCA\AppleSync\Db\Account;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\ICloud\Exception\ReauthRequiredException;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Service\NotificationService;
use OCA\AppleSync\Service\UserScope;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotEnoughSpaceException;
use Psr\Log\LoggerInterface;

/**
 * Runs one engine for one user and service: takes the service's lock, runs
 * within the budget, and records the outcome (counters, errors, backoff and
 * the next run time).
 */
class SyncRunner {
	/** The lock outlives a crashed run by at most this long; live runs keep extending it. */
	private const LOCK_TTL = 300;
	private const HEARTBEAT = 60;
	private const MAX_BACKOFF = 6 * 3600;
	/** Consecutive failures before the user gets a notification. */
	private const NOTIFY_AFTER_FAILURES = 3;

	public function __construct(
		private SyncStateMapper $states,
		private AccountService $accounts,
		private EngineRegistry $engines,
		private UserScope $userScope,
		private NotificationService $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the credentials this service needs are connected.
	 */
	public function isConnected(Account $account, string $service): bool {
		return in_array($service, SyncState::DAV_SERVICES, true)
			? $account->getDavState() === Account::DAV_CONNECTED
			: $account->getWebState() === Account::WEB_CONNECTED;
	}

	public function run(string $userId, string $service, SyncBudget $budget): RunOutcome {
		if (!$this->engines->has($service)) {
			return RunOutcome::Skipped;
		}
		$state = $this->states->findOrCreate($userId, $service);
		if (!$state->getEnabled() || !$this->isConnected($this->accounts->getAccount($userId), $service)) {
			return RunOutcome::Skipped;
		}
		$now = $this->time->getTime();
		if (!$this->states->tryLock($state->getId(), $now, self::LOCK_TTL)) {
			return RunOutcome::Busy;
		}

		$lastBeat = $now;
		try {
			// Re-read under the lock: another run may have moved the cursor.
			$state = $this->states->find($userId, $service);
			if ($state->getStatus() !== SyncState::STATUS_PENDING) {
				$state->setItemsDone(0);
				$state->setBytesDone(0);
			}
			$context = new SyncContext($userId, $state, $budget, function (SyncState $state) use (&$lastBeat): void {
				$this->states->update($state);
				$now = $this->time->getTime();
				if ($now - $lastBeat >= self::HEARTBEAT) {
					$this->states->extendLock($state->getId(), $now + self::LOCK_TTL);
					$lastBeat = $now;
				}
			});

			$outcome = $this->runEngine($context);
			$this->states->update($state);
			return $outcome;
		} finally {
			$this->states->unlock($state->getId());
		}
	}

	private function runEngine(SyncContext $context): RunOutcome {
		$state = $context->state;
		$userId = $context->userId;
		$service = $state->getService();
		try {
			$finished = $this->userScope->run($userId, fn (): bool => $this->engines->get($service)->run($context));
		} catch (ReauthRequiredException) {
			$this->fail($state, 'Waiting for the iCloud sign-in to be renewed.', false);
			return RunOutcome::Failed;
		} catch (DavAuthException $e) {
			$this->accounts->markDavRejected($this->accounts->getAccount($userId));
			$this->fail($state, 'Apple rejected the app-specific password. Enter a new one above.', false);
			return RunOutcome::Failed;
		} catch (NotEnoughSpaceException) {
			$this->fail($state, 'There is not enough free space in your Nextcloud.', true);
			return RunOutcome::Failed;
		} catch (ICloudException $e) {
			$this->logger->warning('iCloud {service} sync failed for {user}: {error}', [
				'service' => $service, 'user' => $userId, 'error' => $e->getMessage(),
			]);
			$this->fail($state, $e->getMessage(), true, in_array($e->getHttpStatus(), [429, 503], true));
			return RunOutcome::Failed;
		} catch (\Throwable $e) {
			$this->logger->error('iCloud {service} sync failed for {user}', [
				'service' => $service, 'user' => $userId, 'exception' => $e,
			]);
			$this->fail($state, 'Unexpected error: ' . $e->getMessage(), true);
			return RunOutcome::Failed;
		}

		$now = $this->time->getTime();
		$state->setFailCount(0);
		if ($state->getStatus() === SyncState::STATUS_ERROR) {
			$this->notifications->dismiss($userId, NotificationService::SYNC_FAILED, $service);
		}
		$warning = $this->warning($context);
		if (!$finished) {
			$state->setStatus(SyncState::STATUS_PENDING);
			$state->setNextRunAt($now);
			$state->setLastError($warning);
			return RunOutcome::Partial;
		}
		$state->setStatus(SyncState::STATUS_IDLE);
		$state->setLastSuccessAt($now);
		$state->setNextRunAt($now + (SyncState::INTERVALS[$service] ?? 3600));
		$state->setLastError($warning);
		return RunOutcome::Finished;
	}

	private function fail(SyncState $state, string $message, bool $counts, bool $throttled = false): void {
		$now = $this->time->getTime();
		$state->setStatus(SyncState::STATUS_ERROR);
		$state->setLastError(mb_substr($message, 0, 1000));
		if (!$counts) {
			$state->setNextRunAt($now + (SyncState::INTERVALS[$state->getService()] ?? 3600));
			return;
		}
		$failures = $state->getFailCount() + 1;
		$state->setFailCount($failures);
		$backoff = min(self::MAX_BACKOFF, 300 * 2 ** min($failures - 1, 10));
		$state->setNextRunAt($now + ($throttled ? max($backoff, 1800) : $backoff));
		if ($failures === self::NOTIFY_AFTER_FAILURES) {
			$this->notifications->notify($state->getUserId(), NotificationService::SYNC_FAILED, $state->getService(), [
				'service' => $state->getService(),
			]);
		}
	}

	private function warning(SyncContext $context): ?string {
		if ($context->skipped() === 0) {
			return null;
		}
		return sprintf('%d item(s) skipped: %s', $context->skipped(), implode('; ', $context->warnings()));
	}
}
