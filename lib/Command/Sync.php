<?php

declare(strict_types=1);

namespace OCA\AppleSync\Command;

use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\Service\SyncService;
use OCA\AppleSync\Sync\RunOutcome;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Admin and debugging tool: run a user's enabled services now, in the
 * foreground. Users sync from the settings page instead.
 */
class Sync extends Command {
	public function __construct(
		private SyncRunner $runner,
		private SyncService $sync,
		private SyncStateMapper $states,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('apple-sync:sync')
			->setDescription('Run the iCloud sync for a user now')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user')
			->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Only this service: ' . implode(', ', SyncState::AVAILABLE_SERVICES))
			->addOption('no-limit', null, InputOption::VALUE_NONE, 'Run until done instead of stopping after a few minutes')
			->addOption('reset', null, InputOption::VALUE_NONE, 'Compare everything again (downloads only what changed)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		$only = $input->getOption('service');
		$services = $only !== null ? [(string)$only] : SyncState::AVAILABLE_SERVICES;

		foreach ($services as $service) {
			if (!in_array($service, SyncState::AVAILABLE_SERVICES, true)) {
				$output->writeln("<error>Unknown service $service</error>");
				return self::INVALID;
			}
			if ($input->getOption('reset')) {
				$this->sync->reset($userId, $service);
			}
			do {
				$budget = $input->getOption('no-limit') ? SyncBudget::unlimited() : SyncBudget::seconds(240, 1024 * 1024 * 1024);
				$outcome = $this->runner->run($userId, $service, $budget);
				$state = $this->states->findOrCreate($userId, $service);
				$output->writeln(sprintf('%-10s %-9s %d items, %s%s', $service, $outcome->value, $state->getItemsDone(),
					self::bytes($state->getBytesDone()), $state->getLastError() ? ' – ' . $state->getLastError() : ''));
			} while ($outcome === RunOutcome::Partial && $input->getOption('no-limit'));
		}
		return self::SUCCESS;
	}

	private static function bytes(int $bytes): string {
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;
		$value = (float)$bytes;
		while ($value >= 1024 && $i < count($units) - 1) {
			$value /= 1024;
			$i++;
		}
		return sprintf($i === 0 ? '%d %s' : '%.1f %s', $value, $units[$i]);
	}
}
