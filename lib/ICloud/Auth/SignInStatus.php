<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Auth;

enum SignInStatus: string {
	case Ok = 'ok';
	case NeedsTwoFactor = 'needs_2fa';
}
