<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Exception;

/**
 * Apple rejected the credentials or the session (wrong password, locked account, expired session).
 */
class AuthenticationException extends ICloudException {
}
