<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Auth;

use InvalidArgumentException;

/**
 * SRP-6a client as used by Apple's iCloud web login.
 *
 * Matches pysrp configured the way pyicloud uses it: RFC 5054 2048-bit group,
 * SHA-256, RFC 5054 padding (rfc5054_enable) and no username in x
 * (no_username_in_x). The "password" passed in is the PBKDF2-derived key from
 * {@see PasswordDeriver}, not the user's plain password.
 */
final class Srp {
	private const N_HEX = 'AC6BDB41324A9A9BF166DE5E1389582FAF72B6651987EE07FC3192943DB56050A37329CBB4A099ED8193E0757767A13DD52312AB4B03310DCD7F48A9DA04FD50E8083969EDB767B0CF6095179A163AB3661A05FBD5FAAAE82918A9962F0B93B855F97993EC975EEAA80D740ADBF4FF747359D041D5C33EA71D281E446B14773BCA97B43A23FB801676BD207A436C6481F1D2B9078717461A5B9D32E688F87748544523B524B0D57D5EA77A2775D2ECFA032CFBDBF52FB3786160279004E57AE6AF874E7303CE53299CCC041C7BC308D82A5698F3A8D0C38271AE35F8E9DBFBB694B5C803D89F7AE435DE236D525F54759B65E372FCD68EF20FA7111F9E4AFF73';
	private const G = 2;
	private const HASH = 'sha256';

	private \GMP $n;
	private \GMP $g;
	private \GMP $k;
	private \GMP $a;
	private \GMP $publicA;
	private int $width;

	/**
	 * @param string|null $aBytes private ephemeral (256 bytes); random when null. Only tests pass this.
	 */
	public function __construct(?string $aBytes = null) {
		$this->n = gmp_init(self::N_HEX, 16);
		$this->g = gmp_init(self::G);
		$this->width = strlen(self::toBytes($this->n));
		$this->k = self::fromBytes($this->hash([$this->n, $this->g], $this->width));
		$this->a = self::fromBytes($aBytes ?? random_bytes(256));
		$this->publicA = gmp_powm($this->g, $this->a, $this->n);
	}

	/** Client public ephemeral A, unpadded big-endian bytes. */
	public function getPublicA(): string {
		return self::toBytes($this->publicA);
	}

	/**
	 * @param string $username Apple ID (only used in M1)
	 * @param string $passwordKey derived key from PasswordDeriver
	 * @param string $salt raw salt bytes from signin/init
	 * @param string $serverB raw B bytes from signin/init
	 * @return array{m1: string, m2: string} raw proof bytes
	 */
	public function processChallenge(string $username, string $passwordKey, string $salt, string $serverB): array {
		$b = self::fromBytes($serverB);
		if (gmp_cmp(gmp_mod($b, $this->n), 0) === 0) {
			throw new InvalidArgumentException('Invalid SRP server public value');
		}

		$u = self::fromBytes($this->hash([$this->publicA, $b], $this->width));
		if (gmp_cmp($u, 0) === 0) {
			throw new InvalidArgumentException('Invalid SRP scrambling parameter');
		}

		// x = H(s | H(":" | p)) — username deliberately left out.
		$x = self::fromBytes($this->hash([$salt, $this->hash([':' . $passwordKey])]));
		$v = gmp_powm($this->g, $x, $this->n);

		// S = (B - k*v) ^ (a + u*x) mod N; gmp_mod keeps the base non-negative.
		$base = gmp_mod(gmp_sub($b, gmp_mul($this->k, $v)), $this->n);
		$s = gmp_powm($base, gmp_add($this->a, gmp_mul($u, $x)), $this->n);
		$sessionKey = hash(self::HASH, self::toBytes($s), true);

		$hn = hash(self::HASH, self::toBytes($this->n), true);
		$hg = hash(self::HASH, str_pad(self::toBytes($this->g), $this->width, "\0", STR_PAD_LEFT), true);

		$aBytes = self::toBytes($this->publicA);
		$m1 = hash(self::HASH, ($hn ^ $hg) . hash(self::HASH, $username, true) . $salt . $aBytes . self::toBytes($b) . $sessionKey, true);
		$m2 = hash(self::HASH, $aBytes . $m1 . $sessionKey, true);

		return ['m1' => $m1, 'm2' => $m2];
	}

	/**
	 * pysrp's H(): integers become unpadded big-endian bytes; with a width every
	 * argument is left-padded to that width (RFC 5054 mode).
	 *
	 * @param list<\GMP|string> $args
	 */
	private function hash(array $args, ?int $width = null): string {
		$ctx = hash_init(self::HASH);
		foreach ($args as $arg) {
			$data = $arg instanceof \GMP ? self::toBytes($arg) : $arg;
			if ($width !== null) {
				$data = str_pad($data, $width, "\0", STR_PAD_LEFT);
			}
			hash_update($ctx, $data);
		}
		return hash_final($ctx, true);
	}

	private static function toBytes(\GMP $n): string {
		return gmp_export($n, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
	}

	private static function fromBytes(string $bytes): \GMP {
		return gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
	}
}
