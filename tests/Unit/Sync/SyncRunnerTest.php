<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\Sync;

use OCA\AppleSync\Db\Account;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Service\NotificationService;
use OCA\AppleSync\Service\UserScope;
use OCA\AppleSync\Sync\EngineRegistry;
use OCA\AppleSync\Sync\RunOutcome;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncEngine;
use OCA\AppleSync\Sync\SyncRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncRunnerTest extends TestCase {
	private const USER = 'alice';

	private int $now = 1_800_000_000;
	private SyncState $state;
	private SyncEngine $engine;
	private SyncRunner $runner;

	protected function setUp(): void {
		$this->state = new SyncState();
		$this->state->setId(1);
		$this->state->setUserId(self::USER);
		$this->state->setService(SyncState::SERVICE_CONTACTS);
		$this->state->setEnabled(true);

		$states = $this->createMock(SyncStateMapper::class);
		$states->method('findOrCreate')->willReturnCallback(fn (): SyncState => $this->state);
		$states->method('find')->willReturnCallback(fn (): SyncState => $this->state);
		$states->method('tryLock')->willReturn(true);
		$states->method('update')->willReturnArgument(0);

		$account = new Account();
		$account->setDavState(Account::DAV_CONNECTED);
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getAccount')->willReturn($account);

		$this->engine = $this->createMock(SyncEngine::class);
		$engines = $this->createMock(EngineRegistry::class);
		$engines->method('has')->willReturn(true);
		$engines->method('get')->willReturn($this->engine);

		$userScope = $this->createMock(UserScope::class);
		$userScope->method('run')->willReturnCallback(static fn (string $userId, callable $fn): mixed => $fn());

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->runner = new SyncRunner($states, $accounts, $engines, $userScope,
			$this->createMock(NotificationService::class), $time, new NullLogger());
	}

	public function testNextRunIsOneIntervalAfterTheStartOfTheRun(): void {
		$start = $this->now;
		$this->engine->method('run')->willReturnCallback(function (): bool {
			$this->now += 200;
			return true;
		});

		$this->assertSame(RunOutcome::Finished, $this->runner->run(self::USER, SyncState::SERVICE_CONTACTS, SyncBudget::unlimited()));
		$this->assertSame($start + 200, $this->state->getLastSuccessAt());
		$this->assertSame($start + 5 * 60, $this->state->getNextRunAt());
	}

	public function testUnfinishedRunContinuesRightAway(): void {
		$this->engine->method('run')->willReturnCallback(function (): bool {
			$this->now += 200;
			return false;
		});

		$this->assertSame(RunOutcome::Partial, $this->runner->run(self::USER, SyncState::SERVICE_CONTACTS, SyncBudget::unlimited()));
		$this->assertSame($this->now, $this->state->getNextRunAt());
	}
}
