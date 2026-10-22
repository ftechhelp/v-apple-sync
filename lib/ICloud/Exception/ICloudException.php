<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Exception;

use RuntimeException;

/**
 * Any failure talking to iCloud. The message is safe to show to the user.
 */
class ICloudException extends RuntimeException {
	public function __construct(
		string $message,
		private int $httpStatus = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}
}
