<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync;

use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\Sync\Engine\CalendarsSync;
use OCA\AppleSync\Sync\Engine\ContactsSync;
use OCA\AppleSync\Sync\Engine\DriveSync;
use OCA\AppleSync\Sync\Engine\PhotosSync;
use Psr\Container\ContainerInterface;

/**
 * Builds the engine for a service on first use, so a run only loads what it needs.
 */
class EngineRegistry {
	private const ENGINES = [
		SyncState::SERVICE_CONTACTS => ContactsSync::class,
		SyncState::SERVICE_CALENDARS => CalendarsSync::class,
		SyncState::SERVICE_DRIVE => DriveSync::class,
		SyncState::SERVICE_PHOTOS => PhotosSync::class,
	];

	public function __construct(
		private ContainerInterface $container,
	) {
	}

	public function has(string $service): bool {
		return isset(self::ENGINES[$service]);
	}

	public function get(string $service): SyncEngine {
		if (!isset(self::ENGINES[$service])) {
			throw new \InvalidArgumentException(sprintf('No sync engine for %s', $service));
		}
		return $this->container->get(self::ENGINES[$service]);
	}
}
