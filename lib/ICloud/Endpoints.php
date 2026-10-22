<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud;

use OCP\IConfig;

/**
 * Base URLs of Apple's auth, setup and DAV services.
 *
 * They default to Apple's real hosts. The end-to-end tests point them at a
 * fake Apple server through the system config value
 * `apple_sync_endpoints` => ['auth' => ..., 'setup' => ..., 'contacts' => ..., 'caldav' => ...];
 * nothing else sets it. Photos and Drive hosts come from accountLogin.
 */
class Endpoints {
	public readonly string $auth;
	public readonly string $setup;
	public readonly string $contacts;
	public readonly string $caldav;

	public function __construct(IConfig $config) {
		$overrides = $config->getSystemValue('apple_sync_endpoints', []);
		$overrides = is_array($overrides) ? $overrides : [];
		$this->auth = rtrim((string)($overrides['auth'] ?? Constants::AUTH_ENDPOINT), '/');
		$this->setup = rtrim((string)($overrides['setup'] ?? Constants::SETUP_ENDPOINT), '/');
		$this->contacts = rtrim((string)($overrides['contacts'] ?? Constants::CARDDAV_ENDPOINT), '/');
		$this->caldav = rtrim((string)($overrides['caldav'] ?? Constants::CALDAV_ENDPOINT), '/');
	}
}
