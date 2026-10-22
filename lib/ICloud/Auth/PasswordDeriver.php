<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Auth;

use InvalidArgumentException;

/**
 * Turns the Apple ID password into the SRP "password" Apple expects.
 *
 * Both protocols start from sha256(password). "s2k" feeds the raw digest into
 * PBKDF2, "s2k_fo" feeds its lowercase hex string. Apple picks the protocol in
 * its signin/init response.
 */
final class PasswordDeriver {
	public const S2K = 's2k';
	public const S2K_FO = 's2k_fo';

	public static function derive(string $password, string $salt, int $iterations, string $protocol): string {
		$digest = hash('sha256', $password, true);
		$input = match ($protocol) {
			self::S2K => $digest,
			self::S2K_FO => bin2hex($digest),
			default => throw new InvalidArgumentException('Unsupported SRP protocol: ' . $protocol),
		};
		return hash_pbkdf2('sha256', $input, $salt, $iterations, 32, true);
	}
}
