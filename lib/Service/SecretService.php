<?php

declare(strict_types=1);

namespace OCA\AppleSync\Service;

use OCP\Security\ICrypto;

/**
 * Thin ICrypto wrapper so every secret column goes through one place.
 */
class SecretService {
	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public function encrypt(?string $plaintext): ?string {
		if ($plaintext === null || $plaintext === '') {
			return null;
		}
		return $this->crypto->encrypt($plaintext);
	}

	public function decrypt(?string $ciphertext): ?string {
		if ($ciphertext === null || $ciphertext === '') {
			return null;
		}
		return $this->crypto->decrypt($ciphertext);
	}

	public function encryptJson(?array $data): ?string {
		return $data === null ? null : $this->encrypt(json_encode($data, JSON_THROW_ON_ERROR));
	}

	public function decryptJson(?string $ciphertext): ?array {
		$plaintext = $this->decrypt($ciphertext);
		if ($plaintext === null) {
			return null;
		}
		$data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
		return is_array($data) ? $data : null;
	}
}
