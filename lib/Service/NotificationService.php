<?php

declare(strict_types=1);

namespace OCA\AppleSync\Service;

use OCA\AppleSync\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud notifications for things the user has to fix. At most one of each
 * kind is shown; a new one replaces the old one.
 */
class NotificationService {
	/** Apple wants a new two-factor code (trust token expired). */
	public const REAUTH_REQUIRED = 'reauth_required';
	/** Apple rejected the app-specific password. */
	public const DAV_REJECTED = 'dav_rejected';
	/** A service failed several times in a row; param: service. */
	public const SYNC_FAILED = 'sync_failed';

	public function __construct(
		private IManager $manager,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function notify(string $userId, string $subject, string $objectId = 'account', array $params = []): void {
		try {
			$this->manager->markProcessed($this->base($userId, $subject, $objectId));
			$notification = $this->base($userId, $subject, $objectId)
				->setDateTime($this->time->getDateTime())
				->setSubject($subject, $params);
			$this->manager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not send the apple_sync notification: {error}', ['error' => $e->getMessage()]);
		}
	}

	public function dismiss(string $userId, string $subject, string $objectId = 'account'): void {
		try {
			$this->manager->markProcessed($this->base($userId, $subject, $objectId));
		} catch (\Throwable $e) {
			$this->logger->debug('Could not dismiss the apple_sync notification: {error}', ['error' => $e->getMessage()]);
		}
	}

	private function base(string $userId, string $subject, string $objectId): INotification {
		return $this->manager->createNotification()
			->setApp(Application::APP_ID)
			->setUser($userId)
			->setObject($subject, $objectId)
			->setSubject($subject);
	}
}
