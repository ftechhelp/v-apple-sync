<?php

declare(strict_types=1);

namespace OCA\AppleSync\Service;

use OCP\Files\ISetupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Run code as a given user, with their filesystem set up. Background jobs
 * have no user session, and file versions, the trash bin and activity all
 * expect one.
 */
class UserScope {
	public function __construct(
		private IUserManager $userManager,
		private IUserSession $userSession,
		private ISetupManager $setupManager,
	) {
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function run(string $userId, callable $fn): mixed {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new \RuntimeException(sprintf('User %s does not exist', $userId));
		}
		$previous = $this->userSession->getUser();
		$switch = $previous?->getUID() !== $userId;
		if ($switch) {
			$this->userSession->setVolatileActiveUser($user);
		}
		try {
			$this->setupManager->setupForUser($user);
			return $fn();
		} finally {
			if ($switch) {
				$this->userSession->setVolatileActiveUser($previous);
			}
		}
	}
}
