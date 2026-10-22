<?php

declare(strict_types=1);

namespace OCA\AppleSync\Listener;

use OCA\AppleSync\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Forget a deleted user's Apple credentials and sync state.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private AccountService $accounts,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->accounts->purge($event->getUid());
		}
	}
}
