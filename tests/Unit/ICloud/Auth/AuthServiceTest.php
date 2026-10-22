<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\ICloud\Auth;

use OCA\AppleSync\ICloud\Auth\AuthService;
use OCA\AppleSync\ICloud\Auth\SignInStatus;
use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Constants;
use OCA\AppleSync\ICloud\Endpoints;
use OCA\AppleSync\ICloud\Exception\AuthenticationException;
use OCA\AppleSync\ICloud\Exception\WrongCodeException;
use OCA\AppleSync\ICloud\Http\ICloudHttp;
use OCA\AppleSync\Tests\Unit\Support\FakeApple;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AuthServiceTest extends TestCase {
	private const AUTH = '/appleauth/auth';
	private const SETUP = '/setup/ws/1';

	private FakeApple $apple;
	private AuthService $auth;

	protected function setUp(): void {
		$this->apple = new FakeApple();
		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback($this->apple->handle(...));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$this->auth = new AuthService(new ICloudHttp($clientService, new NullLogger()), new Endpoints($this->createMock(IConfig::class)), new NullLogger());

		$this->apple
			->on('GET ' . self::AUTH . '/authorize/signin', FakeApple::json(200, [], ['scnt' => 'scnt-1', 'X-Apple-ID-Session-Id' => 'sid-1', 'Set-Cookie' => 'aasp=cookie1; Domain=.apple.com; Path=/; Secure']))
			->on('POST ' . self::AUTH . '/federate', FakeApple::json(200, ['hasSWP' => true]))
			->on('POST ' . self::AUTH . '/signin/init', FakeApple::json(200, [
				'iteration' => 1000,
				'salt' => base64_encode(random_bytes(16)),
				'protocol' => 's2k',
				'b' => base64_encode(random_bytes(256)),
				'c' => 'challenge-c',
			], ['scnt' => 'scnt-2']))
			->on('POST ' . self::SETUP . '/accountLogin', FakeApple::json(200, self::accountData(trusted: true), [
				'Set-Cookie' => 'X-APPLE-WEBAUTH-TOKEN=v=2:t=abc; Domain=.icloud.com; Path=/; Secure',
			]));
	}

	private static function accountData(bool $trusted): array {
		return [
			'dsInfo' => ['dsid' => '1234567890', 'hsaVersion' => 2],
			'hsaChallengeRequired' => !$trusted,
			'hsaTrustedBrowser' => $trusted,
			'webservices' => [
				'drivews' => ['url' => 'https://p01-drivews.icloud.com:443', 'status' => 'active'],
				'ckdatabasews' => ['url' => 'https://p01-ckdatabasews.icloud.com:443', 'status' => 'active'],
			],
		];
	}

	public function testSignInNeedingTwoFactorThenDeviceCode(): void {
		$this->apple->on('POST ' . self::AUTH . '/signin/complete', FakeApple::json(409, ['authType' => 'hsa2'], ['X-Apple-Session-Token' => 'session-1', 'scnt' => 'scnt-3']));
		$session = WebSession::create();

		$this->assertSame(SignInStatus::NeedsTwoFactor, $this->auth->signIn($session, 'me@example.com', 'secret'));

		$init = $this->apple->lastJson('POST ' . self::AUTH . '/signin/init');
		$this->assertSame('me@example.com', $init['accountName']);
		$this->assertSame(['s2k', 's2k_fo'], $init['protocols']);
		$this->assertNotFalse(base64_decode($init['a'], true));

		$complete = $this->apple->last('POST ' . self::AUTH . '/signin/complete');
		$body = json_decode((string)$complete->getBody(), true);
		$this->assertSame('challenge-c', $body['c']);
		$this->assertSame([], $body['trustTokens']);
		$this->assertSame(32, strlen(base64_decode($body['m1'])));
		// Headers captured from earlier responses are echoed back.
		$this->assertSame('scnt-2', $complete->getHeaderLine('scnt'));
		$this->assertSame('sid-1', $complete->getHeaderLine('X-Apple-ID-Session-Id'));
		$this->assertSame(Constants::WIDGET_KEY, $complete->getHeaderLine('X-Apple-Widget-Key'));
		$this->assertSame($session->clientId, $complete->getHeaderLine('X-Apple-Frame-Id'));
		$this->assertStringContainsString('aasp=cookie1', $complete->getHeaderLine('Cookie'));
		$this->assertSame('session-1', $session->sessionToken);

		$this->apple
			->on('POST ' . self::AUTH . '/verify/trusteddevice/securitycode', FakeApple::json(204))
			->on('GET ' . self::AUTH . '/2sv/trust', FakeApple::json(204, [], ['X-Apple-TwoSV-Trust-Token' => 'trust-1', 'X-Apple-Session-Token' => 'session-2']));

		$this->auth->verifyTrustedDeviceCode($session, '123456');

		$this->assertSame(['code' => '123456'], $this->apple->lastJson('POST ' . self::AUTH . '/verify/trusteddevice/securitycode')['securityCode']);
		$login = $this->apple->lastJson('POST ' . self::SETUP . '/accountLogin');
		$this->assertSame('session-2', $login['dsWebAuthToken']);
		$this->assertSame('trust-1', $login['trustToken']);
		$this->assertTrue($login['extended_login']);

		$this->assertSame('trust-1', $session->trustToken);
		$this->assertSame('1234567890', $session->dsid);
		$this->assertSame('https://p01-drivews.icloud.com:443', $session->serviceUrl('drivews'));
		$this->assertSame('1234567890', $session->serviceParams()['dsid']);
	}

	public function testSignInWithValidTrustTokenSkipsTwoFactor(): void {
		$this->apple->on('POST ' . self::AUTH . '/signin/complete', FakeApple::json(200, [], ['X-Apple-Session-Token' => 'session-1']));
		$session = WebSession::create();
		$session->trustToken = 'trust-old';

		$this->assertSame(SignInStatus::Ok, $this->auth->signIn($session, 'me@example.com', 'secret'));
		$this->assertSame(['trust-old'], $this->apple->lastJson('POST ' . self::AUTH . '/signin/complete')['trustTokens']);
		$this->assertSame('1234567890', $session->dsid);
	}

	public function testAccountLoginAskingForChallengeMeansTwoFactor(): void {
		$this->apple
			->on('POST ' . self::AUTH . '/signin/complete', FakeApple::json(200, [], ['X-Apple-Session-Token' => 'session-1']))
			->on('POST ' . self::SETUP . '/accountLogin', FakeApple::json(200, self::accountData(trusted: false)));

		$this->assertSame(SignInStatus::NeedsTwoFactor, $this->auth->signIn(WebSession::create(), 'me@example.com', 'secret'));
	}

	public function testWrongPassword(): void {
		$this->apple->on('POST ' . self::AUTH . '/signin/complete', FakeApple::json(401, ['serviceErrors' => [['code' => '-20101', 'message' => 'Your Apple ID or password was incorrect.']]]));
		$this->expectException(AuthenticationException::class);
		$this->auth->signIn(WebSession::create(), 'me@example.com', 'wrong');
	}

	public function testWrongCode(): void {
		$this->apple->on('POST ' . self::AUTH . '/verify/trusteddevice/securitycode', FakeApple::json(400, ['service_errors' => [['code' => '-21669', 'message' => 'Incorrect verification code.']]]));
		$this->expectException(WrongCodeException::class);
		$this->expectExceptionMessage('Incorrect verification code.');
		$this->auth->verifyTrustedDeviceCode(WebSession::create(), '000000');
	}

	public function testSmsFlow(): void {
		$this->apple
			->on('GET ' . self::AUTH, FakeApple::json(200, ['trustedPhoneNumbers' => [['id' => 2, 'numberWithDialCode' => '+1 (•••) •••-••42']]]))
			->on('PUT ' . self::AUTH . '/verify/phone', FakeApple::json(200))
			->on('POST ' . self::AUTH . '/verify/phone/securitycode', FakeApple::json(200))
			->on('GET ' . self::AUTH . '/2sv/trust', FakeApple::json(204, [], ['X-Apple-TwoSV-Trust-Token' => 'trust-sms', 'X-Apple-Session-Token' => 'session-sms']));
		$session = WebSession::create();

		$this->assertSame([['id' => 2, 'number' => '+1 (•••) •••-••42']], $this->auth->getTrustedPhoneNumbers($session));
		$this->auth->requestSmsCode($session, 2);
		$this->assertSame(['phoneNumber' => ['id' => 2], 'mode' => 'sms'], $this->apple->lastJson('PUT ' . self::AUTH . '/verify/phone'));
		$this->auth->verifySmsCode($session, 2, '654321');
		$this->assertSame('trust-sms', $session->trustToken);
	}

	public function testValidate(): void {
		$this->apple->on('POST ' . self::SETUP . '/validate', FakeApple::json(200, self::accountData(trusted: true)));
		$session = WebSession::create();
		$this->assertTrue($this->auth->validate($session));
		$this->assertSame('null', (string)$this->apple->last('POST ' . self::SETUP . '/validate')->getBody());

		$this->apple->on('POST ' . self::SETUP . '/validate', FakeApple::json(421));
		$this->assertFalse($this->auth->validate($session));
	}

	public function testSessionRoundTripKeepsCookiesAndTokens(): void {
		$this->apple->on('POST ' . self::AUTH . '/signin/complete', FakeApple::json(200, [], ['X-Apple-Session-Token' => 'session-1']));
		$session = WebSession::create();
		$this->auth->signIn($session, 'me@example.com', 'secret');

		$restored = WebSession::fromArray(json_decode(json_encode($session->toArray()), true));
		$this->assertSame($session->clientId, $restored->clientId);
		$this->assertSame('session-1', $restored->sessionToken);
		$this->assertSame($session->webservices, $restored->webservices);
		$names = array_map(static fn (array $c) => $c['Name'], $restored->cookies->toArray());
		$this->assertContains('X-APPLE-WEBAUTH-TOKEN', $names);
		$this->assertContains('aasp', $names);
	}
}
