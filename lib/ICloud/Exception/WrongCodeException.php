<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Exception;

/**
 * The two-factor verification code was not accepted.
 */
class WrongCodeException extends AuthenticationException {
}
