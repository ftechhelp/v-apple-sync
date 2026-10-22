<?php

declare(strict_types=1);

namespace OCA\AppleSync\AppInfo;

use OCA\AppleSync\Listener\UserDeletedListener;
use OCA\AppleSync\Notification\Notifier;
use OCA\AppleSync\Sync\Store\CalendarStore;
use OCA\AppleSync\Sync\Store\ContactsStore;
use OCA\AppleSync\Sync\Store\FileStore;
use OCA\AppleSync\Sync\Store\NcCalendarStore;
use OCA\AppleSync\Sync\Store\NcContactsStore;
use OCA\AppleSync\Sync\Store\NcFileStore;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'apple_sync';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ContactsStore::class, NcContactsStore::class);
		$context->registerServiceAlias(CalendarStore::class, NcCalendarStore::class);
		$context->registerServiceAlias(FileStore::class, NcFileStore::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
