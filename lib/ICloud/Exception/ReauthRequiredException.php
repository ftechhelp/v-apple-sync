<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Exception;

/**
 * The stored session can't be renewed without the user: the trust token
 * expired and Apple wants a new two-factor code.
 */
class ReauthRequiredException extends AuthenticationException {
}
