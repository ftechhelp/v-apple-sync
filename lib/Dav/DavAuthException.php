<?php

declare(strict_types=1);

namespace OCA\AppleSync\Dav;

use OCA\AppleSync\ICloud\Exception\ICloudException;

/**
 * Apple answered 401 to CardDAV/CalDAV: the app-specific password is wrong or was revoked.
 */
class DavAuthException extends ICloudException {
}
