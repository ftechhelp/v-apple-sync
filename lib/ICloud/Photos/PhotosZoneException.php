<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Photos;

use OCA\AppleSync\ICloud\Exception\ICloudException;

/**
 * CloudKit could not serve the photo zone (for example an expired sync token).
 */
class PhotosZoneException extends ICloudException {
	public function __construct(
		string $message,
		public readonly string $serverErrorCode,
	) {
		parent::__construct($message, 400);
	}
}
