<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\BackgroundJob;

use OCA\AppleSync\BackgroundJob\SchedulerJob;
use OCA\AppleSync\BackgroundJob\SyncJob;
use OCA\AppleSync\Db\Account;
use OCA\AppleSync\Db\AccountMapper;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\Sync\SyncRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

class SchedulerJobTest extends TestCase {
	private const TICK = 1_800_000_000;

	/** @var list<SyncState> */
	private array $states = [];
	/** @var list<array{userId: string, service: string}> */
	private array $queued = [];

	public function testQueuesWhatIsDueBeforeTheNextTick(): void {
		// Started 90 s after the previous tick, so due 90 s after this one.
		$this->state(SyncState::SERVICE_CONTACTS, self::TICK + 90);
		// Synced by hand halfway between ticks: the next tick takes it.
		$this->state(SyncState::SERVICE_DRIVE, self::TICK + 150);
		// Still running.
		$this->state(SyncState::SERVICE_PHOTOS, self::TICK - 300, lockedUntil: self::TICK + 60);

		$this->tick();

		$this->assertSame([['userId' => 'alice', 'service' => SyncState::SERVICE_CONTACTS]], $this->queued);
	}

	public function testSkipsServicesWhoseCredentialsAreNotConnected(): void {
		$this->state(SyncState::SERVICE_CONTACTS, self::TICK);

		$this->tick(connected: false);

		$this->assertSame([], $this->queued);
	}

	private function state(string $service, int $nextRunAt, int $lockedUntil = 0): void {
		$state = new SyncState();
		$state->setUserId('alice');
		$state->setService($service);
		$state->setEnabled(true);
		$state->setNextRunAt($nextRunAt);
		$state->setLockedUntil($lockedUntil);
		$this->states[] = $state;
	}

	private function tick(bool $connected = true): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::TICK);

		$mapper = $this->createMock(SyncStateMapper::class);
		$mapper->method('findDue')->willReturnCallback(fn (int $cutoff): array => array_values(array_filter(
			$this->states, static fn (SyncState $state): bool => $state->getNextRunAt() <= $cutoff)));

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findByUser')->willReturn(new Account());
		$runner = $this->createMock(SyncRunner::class);
		$runner->method('isConnected')->willReturn($connected);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->method('add')->willReturnCallback(function (string $class, array $argument): void {
			$this->assertSame(SyncJob::class, $class);
			$this->queued[] = $argument;
		});

		$job = new SchedulerJob($time, $mapper, $accounts, $runner, $jobList);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
