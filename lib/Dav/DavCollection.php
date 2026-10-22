<?php

declare(strict_types=1);

namespace OCA\AppleSync\Dav;

/**
 * An address book or calendar on Apple's DAV server.
 */
final class DavCollection {
	/**
	 * @param string $url absolute URL, with a trailing slash
	 * @param string $path URL path; stable even when Apple moves the account to another pNN host
	 * @param list<string> $components VEVENT, VTODO... (calendars only)
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $path,
		public readonly string $displayName,
		public readonly ?string $ctag,
		public readonly ?string $color = null,
		public readonly array $components = [],
	) {
	}
}
