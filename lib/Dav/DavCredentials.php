<?php

declare(strict_types=1);

namespace OCA\AppleSync\Dav;

/**
 * Apple ID + app-specific password for CardDAV/CalDAV.
 */
final class DavCredentials {
	public function __construct(
		public readonly string $appleId,
		#[\SensitiveParameter]
		public readonly string $password,
	) {
	}
}
