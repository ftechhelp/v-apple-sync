<?php

declare(strict_types=1);

namespace OCA\AppleSync\Command;

use OCA\AppleSync\ICloud\Auth\AuthService;
use OCA\AppleSync\ICloud\Auth\SignInStatus;
use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\ICloud\Exception\WrongCodeException;
use OCA\AppleSync\Service\AccountService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Interactive iCloud web sign-in (SRP + 2FA) for a Nextcloud user.
 */
class Login extends Command {
	private const MAX_CODE_ATTEMPTS = 3;

	public function __construct(
		private AuthService $auth,
		private AccountService $accounts,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('apple-sync:login')
			->setDescription('Sign a Nextcloud user in to the iCloud web API (Photos, Drive, Reminders)')
			->addArgument('user', InputArgument::REQUIRED, 'Nextcloud user id')
			->addOption('apple-id', null, InputOption::VALUE_REQUIRED, 'Apple ID email (defaults to the stored one)')
			->addOption('fresh', null, InputOption::VALUE_NONE, 'Discard the stored session and trust token and start over');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		if (!$this->userManager->userExists($userId)) {
			$output->writeln("<error>Unknown Nextcloud user: $userId</error>");
			return self::FAILURE;
		}
		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		$account = $this->accounts->getAccount($userId);

		$appleId = $input->getOption('apple-id') ?? $account->getAppleId();
		if ($appleId === null || $appleId === '') {
			$appleId = (string)$helper->ask($input, $output, new Question('Apple ID (email): '));
		}
		$output->writeln("Apple ID: <info>$appleId</info>");

		$question = (new Question('Apple ID password (not an app-specific password): '))->setHidden(true)->setHiddenFallback(false);
		$password = (string)$helper->ask($input, $output, $question);

		// Keep the stored session (and its trust token) unless asked not to:
		// a valid trust token lets Apple skip 2FA.
		$session = $input->getOption('fresh') ? null : $this->accounts->loadWebSession($account);
		$hadTrustToken = $session?->trustToken !== null;
		$session ??= WebSession::create();

		try {
			$status = $this->auth->signIn($session, $appleId, $password);
			$freshTrust = false;
			if ($status === SignInStatus::NeedsTwoFactor) {
				$output->writeln($hadTrustToken
					? 'The stored trust token was not accepted; two-factor verification is required.'
					: 'Two-factor verification is required.');
				$this->twoFactor($session, $input, $output, $helper);
				$freshTrust = true;
			} else {
				$output->writeln('Signed in without 2FA (trust token accepted).');
			}
		} catch (WrongCodeException) {
			$output->writeln('<error>Too many incorrect codes.</error>');
			return self::FAILURE;
		} catch (ICloudException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		$this->accounts->completeSignIn($account, $session, $appleId, $password, $freshTrust);
		$account = $this->accounts->getAccount($userId);

		$output->writeln('<info>Connected.</info>');
		$output->writeln('dsid: ' . $session->dsid);
		$output->writeln('Trust expires: ' . date('Y-m-d', (int)$account->getTrustExpiresAt()) . ' (estimate)');
		$output->writeln('Services: ' . implode(', ', array_keys($session->webservices)));
		return self::SUCCESS;
	}

	private function twoFactor(WebSession $session, InputInterface $input, OutputInterface $output, QuestionHelper $helper): void {
		$this->auth->requestTrustedDeviceCode($session);
		$output->writeln('A code was sent to your trusted Apple devices.');

		for ($attempt = 1; ; $attempt++) {
			$code = trim((string)$helper->ask($input, $output, new Question('Verification code (or "sms" for a text message): ')));
			try {
				if (strtolower($code) === 'sms') {
					$this->smsFlow($session, $input, $output, $helper);
				} else {
					$this->auth->verifyTrustedDeviceCode($session, $code);
				}
				return;
			} catch (WrongCodeException $e) {
				$output->writeln('<comment>' . $e->getMessage() . '</comment>');
				if ($attempt >= self::MAX_CODE_ATTEMPTS) {
					throw $e;
				}
			}
		}
	}

	private function smsFlow(WebSession $session, InputInterface $input, OutputInterface $output, QuestionHelper $helper): void {
		$phones = $this->auth->getTrustedPhoneNumbers($session);
		if ($phones === []) {
			throw new ICloudException('This account has no trusted phone numbers');
		}
		$labels = array_map(static fn (array $p): string => $p['number'], $phones);
		$choice = $phones[0];
		if (count($phones) > 1) {
			$picked = $helper->ask($input, $output, new ChoiceQuestion('Send the code to:', $labels, 0));
			$choice = $phones[array_search($picked, $labels, true)];
		}
		$this->auth->requestSmsCode($session, $choice['id']);
		$output->writeln('Text message sent to ' . $choice['number']);
		$code = trim((string)$helper->ask($input, $output, new Question('Code from the text message: ')));
		$this->auth->verifySmsCode($session, $choice['id'], $code);
	}
}
