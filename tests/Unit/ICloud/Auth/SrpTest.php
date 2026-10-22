<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\ICloud\Auth;

use OCA\AppleSync\ICloud\Auth\PasswordDeriver;
use OCA\AppleSync\ICloud\Auth\Srp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vectors come from tests/tools/gen_srp_vectors.py (pysrp, configured like pyicloud).
 */
class SrpTest extends TestCase {
	public static function vectors(): array {
		$vectors = json_decode(file_get_contents(__DIR__ . '/../../../fixtures/srp_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
		$cases = [];
		foreach ($vectors as $i => $v) {
			$cases[$v['protocol'] . '#' . $i] = [$v];
		}
		return $cases;
	}

	#[DataProvider('vectors')]
	public function testPasswordDerivation(array $v): void {
		$key = PasswordDeriver::derive($v['password'], base64_decode($v['salt']), $v['iterations'], $v['protocol']);
		$this->assertSame($v['derivedKey'], base64_encode($key));
	}

	#[DataProvider('vectors')]
	public function testClientProofsMatchPysrp(array $v): void {
		$srp = new Srp(base64_decode($v['a']));
		$this->assertSame($v['A'], base64_encode($srp->getPublicA()));

		$proof = $srp->processChallenge($v['username'], base64_decode($v['derivedKey']), base64_decode($v['salt']), base64_decode($v['B']));
		$this->assertSame($v['M1'], base64_encode($proof['m1']));
		$this->assertSame($v['M2'], base64_encode($proof['m2']));
	}

	public function testRandomEphemeralsDiffer(): void {
		$this->assertNotSame((new Srp())->getPublicA(), (new Srp())->getPublicA());
	}

	public function testRejectsZeroB(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new Srp())->processChallenge('u', 'p', 'salt', "\0");
	}

	public function testRejectsUnknownProtocol(): void {
		$this->expectException(\InvalidArgumentException::class);
		PasswordDeriver::derive('pw', 'salt', 1, 's2k_unknown');
	}
}
