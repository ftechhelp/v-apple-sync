<?php

declare(strict_types=1);

namespace OCA\AppleSync\Command;

use OCA\AppleSync\Db\AccountMapper;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\Service\AccountService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command {
	public function __construct(
		private AccountMapper $accountMapper,
		private SyncStateMapper $stateMapper,
		private AccountService $accounts,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('apple-sync:status')
			->setDescription('Show linked Apple accounts and sync state')
			->addArgument('user', InputArgument::OPTIONAL, 'Only this Nextcloud user')
			->addOption('verify', null, InputOption::VALUE_NONE, 'Check the iCloud web session against Apple (renews it if needed)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user');
		try {
			$accounts = $userId !== null ? [$this->accountMapper->findByUser($userId)] : $this->accountMapper->findAll();
		} catch (DoesNotExistException) {
			$accounts = [];
		}
		if ($accounts === []) {
			$output->writeln('No linked Apple accounts.');
			return self::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['User', 'Apple ID', 'Web API', 'CardDAV/CalDAV', 'Trust expires', 'Last error']);
		foreach ($accounts as $account) {
			$table->addRow([
				$account->getUserId(),
				$account->getAppleId() ?? '-',
				$account->getWebState(),
				$account->getDavState(),
				$account->getTrustExpiresAt() ? date('Y-m-d', $account->getTrustExpiresAt()) : '-',
				$account->getLastError() ?? '',
			]);
		}
		$table->render();

		foreach ($accounts as $account) {
			$states = $this->stateMapper->findByUser($account->getUserId());
			if ($states !== []) {
				$t = new Table($output);
				$t->setHeaders(['Service', 'Enabled', 'Status', 'Last success', 'Items', 'Bytes', 'Last error']);
				foreach ($states as $s) {
					$t->addRow([
						$s->getService(), $s->getEnabled() ? 'yes' : 'no', $s->getStatus(),
						$s->getLastSuccessAt() ? date('Y-m-d H:i', $s->getLastSuccessAt()) : '-',
						$s->getItemsDone(), $s->getBytesDone(), $s->getLastError() ?? '',
					]);
				}
				$output->writeln("\n<info>" . $account->getUserId() . '</info>');
				$t->render();
			}

			if ($input->getOption('verify')) {
				try {
					$session = $this->accounts->ensureWebSession($account->getUserId());
					$output->writeln($account->getUserId() . ': <info>iCloud web session OK</info> (' . count($session->webservices) . ' services)');
				} catch (ICloudException $e) {
					$output->writeln($account->getUserId() . ': <error>' . $e->getMessage() . '</error>');
				}
			}
		}
		return self::SUCCESS;
	}
}
