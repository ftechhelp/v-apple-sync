<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Auth;

use OCA\AppleSync\ICloud\Constants;
use OCA\AppleSync\ICloud\Endpoints;
use OCA\AppleSync\ICloud\Exception\AuthenticationException;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\ICloud\Exception\WrongCodeException;
use OCA\AppleSync\ICloud\Http\ICloudHttp;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * iCloud web sign-in: SRP-6a against idmsa.apple.com, two-factor
 * verification, session trust and accountLogin.
 *
 * Stateless: all state lives in the WebSession, so a sign-in can be started in
 * one HTTP request and finished (2FA code) in another.
 *
 * Ported from rclone backend/iclouddrive/api/session.go and pyicloud base.py.
 */
class AuthService {
	public function __construct(
		private ICloudHttp $http,
		private Endpoints $endpoints,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run the SRP sign-in, then accountLogin when no second factor is needed.
	 *
	 * @throws AuthenticationException wrong Apple ID or password, locked account
	 * @throws ICloudException
	 */
	public function signIn(WebSession $session, string $appleId, string $password): SignInStatus {
		$this->authorizeStart($session);
		$this->federate($session, $appleId);

		$srp = new Srp();
		$init = $this->authPost($session, '/signin/init', [
			'a' => base64_encode($srp->getPublicA()),
			'accountName' => $appleId,
			'protocols' => [PasswordDeriver::S2K, PasswordDeriver::S2K_FO],
		]);
		$initData = ICloudHttp::json($init);
		if ($init->getStatusCode() !== 200 || !isset($initData['salt'], $initData['b'], $initData['c'], $initData['iteration'], $initData['protocol'])) {
			throw $this->failure('Sign-in could not be started', $init, $initData);
		}

		$salt = base64_decode($initData['salt'], true);
		$serverB = base64_decode($initData['b'], true);
		if ($salt === false || $serverB === false) {
			throw new ICloudException('Apple sent a malformed sign-in challenge');
		}
		$key = PasswordDeriver::derive($password, $salt, (int)$initData['iteration'], (string)$initData['protocol']);
		$proof = $srp->processChallenge($appleId, $key, $salt, $serverB);

		$complete = $this->authPost($session, '/signin/complete?isRememberMeEnabled=true', [
			'accountName' => $appleId,
			'm1' => base64_encode($proof['m1']),
			'm2' => base64_encode($proof['m2']),
			'c' => $initData['c'],
			'rememberMe' => true,
			'trustTokens' => $session->trustToken !== null ? [$session->trustToken] : [],
		]);

		switch ($complete->getStatusCode()) {
			case 200:
				break;
			case 409:
				return SignInStatus::NeedsTwoFactor;
			case 412:
				// Apple wants the user to acknowledge something (terms, privacy notice).
				$repair = $this->authPost($session, '/repair/complete', null);
				if ($repair->getStatusCode() >= 400) {
					throw $this->failure('Apple requires an account update. Sign in at icloud.com once, then try again', $repair, ICloudHttp::json($repair));
				}
				break;
			case 401:
			case 403:
				throw new AuthenticationException('Incorrect Apple ID or password', $complete->getStatusCode());
			default:
				throw $this->failure('Sign-in failed', $complete, ICloudHttp::json($complete));
		}

		$account = $this->accountLogin($session);
		return self::needsTwoFactor($account) ? SignInStatus::NeedsTwoFactor : SignInStatus::Ok;
	}

	/**
	 * Ask Apple to show a code on the user's trusted devices. Recent iOS
	 * versions don't push the code without this.
	 */
	public function requestTrustedDeviceCode(WebSession $session): void {
		$response = $this->auth($session, 'PUT', '/verify/trusteddevice/securitycode', null);
		if ($response->getStatusCode() >= 400) {
			$this->logger->info('Trusted device code request returned HTTP {status}', ['status' => $response->getStatusCode()]);
		}
	}

	/**
	 * Two-factor options for the pending sign-in: trusted phone numbers, code length, ...
	 */
	public function getTwoFactorOptions(WebSession $session): array {
		$response = $this->auth($session, 'GET', '', null);
		return ICloudHttp::json($response);
	}

	/**
	 * @return list<array{id: int, number: string}>
	 */
	public function getTrustedPhoneNumbers(WebSession $session): array {
		$phones = [];
		foreach ($this->getTwoFactorOptions($session)['trustedPhoneNumbers'] ?? [] as $phone) {
			if (isset($phone['id'])) {
				$phones[] = [
					'id' => (int)$phone['id'],
					'number' => (string)($phone['numberWithDialCode'] ?? $phone['obfuscatedNumber'] ?? ''),
				];
			}
		}
		return $phones;
	}

	public function requestSmsCode(WebSession $session, int $phoneId): void {
		$response = $this->auth($session, 'PUT', '/verify/phone', [
			'phoneNumber' => ['id' => $phoneId],
			'mode' => 'sms',
		]);
		if ($response->getStatusCode() >= 400) {
			throw $this->failure('Could not send the text message', $response, ICloudHttp::json($response));
		}
	}

	/**
	 * Verify a code shown on a trusted device, then trust the session.
	 *
	 * @throws WrongCodeException
	 */
	public function verifyTrustedDeviceCode(WebSession $session, string $code): void {
		$response = $this->auth($session, 'POST', '/verify/trusteddevice/securitycode', [
			'securityCode' => ['code' => $code],
		]);
		$this->checkCodeResponse($response);
		$this->trust($session);
	}

	/**
	 * Verify a code received by text message, then trust the session.
	 *
	 * @throws WrongCodeException
	 */
	public function verifySmsCode(WebSession $session, int $phoneId, string $code): void {
		$response = $this->auth($session, 'POST', '/verify/phone/securitycode', [
			'securityCode' => ['code' => $code],
			'phoneNumber' => ['id' => $phoneId],
			'mode' => 'sms',
		]);
		$this->checkCodeResponse($response);
		$this->trust($session);
	}

	/**
	 * Mark this browser as trusted (yields the trust token that lets later
	 * sign-ins skip 2FA), then log in to iCloud.
	 */
	public function trust(WebSession $session): void {
		$response = $this->auth($session, 'GET', '/2sv/trust', null);
		if ($response->getStatusCode() >= 400) {
			throw $this->failure('Apple did not trust this session', $response, ICloudHttp::json($response));
		}
		$account = $this->accountLogin($session);
		if (self::needsTwoFactor($account)) {
			throw new AuthenticationException('Apple still requires two-factor authentication after verification');
		}
	}

	/**
	 * Exchange the session token for iCloud cookies and the service URL list.
	 */
	public function accountLogin(WebSession $session): array {
		if ($session->sessionToken === null) {
			throw new AuthenticationException('No Apple session token; sign in again');
		}
		$response = $this->http->request($session, 'POST', $this->endpoints->setup . '/accountLogin?' . http_build_query($session->serviceParams()), [
			'headers' => $this->setupHeaders(),
			'json' => [
				'accountCountryCode' => $session->accountCountry ?? '',
				'dsWebAuthToken' => $session->sessionToken,
				'extended_login' => true,
				'trustToken' => $session->trustToken ?? '',
			],
		]);
		$data = ICloudHttp::json($response);
		if ($response->getStatusCode() === 421 || $response->getStatusCode() === 401 || $response->getStatusCode() === 403) {
			throw new AuthenticationException('iCloud rejected the session', $response->getStatusCode());
		}
		if ($response->getStatusCode() !== 200) {
			throw $this->failure('iCloud login failed', $response, $data);
		}
		$this->applyAccountData($session, $data);
		return $data;
	}

	/**
	 * Check whether the iCloud cookies are still valid; refreshes the service list if so.
	 */
	public function validate(WebSession $session): bool {
		$response = $this->http->request($session, 'POST', $this->endpoints->setup . '/validate?' . http_build_query($session->serviceParams()), [
			'headers' => $this->setupHeaders(),
			'body' => 'null',
		]);
		if ($response->getStatusCode() !== 200) {
			return false;
		}
		$data = ICloudHttp::json($response);
		$this->applyAccountData($session, $data);
		return !self::needsTwoFactor($data);
	}

	public static function needsTwoFactor(array $account): bool {
		$hsaVersion = (int)($account['dsInfo']['hsaVersion'] ?? 0);
		return $hsaVersion === 2
			&& (!empty($account['hsaChallengeRequired']) || empty($account['hsaTrustedBrowser']));
	}

	private function authorizeStart(WebSession $session): void {
		$query = http_build_query([
			'frame_id' => $session->clientId,
			'language' => 'en_US',
			'skVersion' => '7',
			'iframeId' => $session->clientId,
			'client_id' => Constants::WIDGET_KEY,
			'redirect_uri' => Constants::HOME_ENDPOINT,
			'response_type' => 'code',
			'response_mode' => 'web_message',
			'state' => $session->clientId,
			'authVersion' => 'latest',
		]);
		$response = $this->http->request($session, 'GET', $this->endpoints->auth . '/authorize/signin?' . $query, [
			'headers' => ['Accept' => '*/*'],
		]);
		$body = (string)$response->getBody();
		if (str_contains($body, 'auth/bridge/step')) {
			// pyicloud has a separate "HSA2 bridge" flow for this route; we only
			// implement the classic one. Log it so failures can be traced.
			$this->logger->warning('Apple offered the HSA2 bridge sign-in route, which is not implemented; trying the classic flow');
		}
	}

	private function federate(WebSession $session, string $appleId): void {
		$response = $this->authPost($session, '/federate?isRememberMeEnabled=true', [
			'accountName' => $appleId,
			'rememberMe' => true,
		]);
		if ($response->getStatusCode() >= 400) {
			$this->logger->info('federate returned HTTP {status}', ['status' => $response->getStatusCode()]);
		}
	}

	private function checkCodeResponse(IResponse $response): void {
		$status = $response->getStatusCode();
		if ($status === 200 || $status === 204) {
			return;
		}
		// rclone: a 409 that still carries a session token means accepted.
		if ($status === 409 && $response->getHeader('X-Apple-Session-Token') !== '') {
			return;
		}
		$data = ICloudHttp::json($response);
		if (in_array((string)Constants::ERROR_WRONG_CODE, ICloudHttp::errorCodes($data), true) || $status === 400 || $status === 401) {
			throw new WrongCodeException(ICloudHttp::errorMessage($data) ?? 'Incorrect verification code', $status);
		}
		throw $this->failure('Verification failed', $response, $data);
	}

	private function applyAccountData(WebSession $session, array $data): void {
		if (isset($data['dsInfo']['dsid'])) {
			$session->dsid = (string)$data['dsInfo']['dsid'];
		}
		if (is_array($data['webservices'] ?? null)) {
			$services = [];
			foreach ($data['webservices'] as $name => $service) {
				if (isset($service['url'])) {
					$services[$name] = (string)$service['url'];
				}
			}
			if ($services !== []) {
				$session->webservices = $services;
			}
		}
	}

	private function authPost(WebSession $session, string $path, ?array $json): IResponse {
		return $this->auth($session, 'POST', $path, $json);
	}

	private function auth(WebSession $session, string $method, string $path, ?array $json): IResponse {
		$options = ['headers' => $this->authHeaders($session)];
		if ($json !== null) {
			$options['json'] = $json;
		}
		return $this->http->request($session, $method, $this->endpoints->auth . $path, $options);
	}

	/**
	 * @return array<string, string>
	 */
	private function authHeaders(WebSession $session): array {
		$headers = [
			'Accept' => 'application/json, text/javascript',
			'Content-Type' => 'application/json',
			'Origin' => Constants::AUTH_ORIGIN,
			'Referer' => Constants::AUTH_ORIGIN . '/',
			'X-Apple-Widget-Key' => Constants::WIDGET_KEY,
			'X-Apple-OAuth-Client-Id' => Constants::WIDGET_KEY,
			'X-Apple-OAuth-Client-Type' => 'firstPartyAuth',
			'X-Apple-OAuth-Redirect-URI' => Constants::HOME_ENDPOINT,
			'X-Apple-OAuth-Require-Grant-Code' => 'true',
			'X-Apple-OAuth-Response-Mode' => 'web_message',
			'X-Apple-OAuth-Response-Type' => 'code',
			'X-Apple-OAuth-State' => $session->clientId,
			'X-Apple-Frame-Id' => $session->clientId,
			'X-Requested-With' => 'XMLHttpRequest',
			'X-Apple-Mandate-Security-Upgrade' => '0',
			'X-Apple-I-Require-UE' => 'true',
			'X-Apple-I-FD-Client-Info' => json_encode([
				'U' => Constants::USER_AGENT, 'L' => 'en-US', 'Z' => 'GMT+00:00', 'V' => '1.1', 'F' => '',
			], JSON_UNESCAPED_SLASHES),
		];
		if ($session->scnt !== null) {
			$headers['scnt'] = $session->scnt;
		}
		if ($session->sessionId !== null) {
			$headers['X-Apple-ID-Session-Id'] = $session->sessionId;
		}
		if ($session->authAttributes !== null) {
			$headers['X-Apple-Auth-Attributes'] = $session->authAttributes;
		}
		return $headers;
	}

	/**
	 * @return array<string, string>
	 */
	private function setupHeaders(): array {
		return [
			'Accept' => 'application/json',
			'Content-Type' => 'text/plain',
			'Origin' => Constants::HOME_ENDPOINT,
			'Referer' => Constants::HOME_ENDPOINT . '/',
		];
	}

	private function failure(string $what, IResponse $response, array $data): ICloudException {
		$status = $response->getStatusCode();
		$detail = ICloudHttp::errorMessage($data);
		$message = sprintf('%s (HTTP %d%s)', $what, $status, $detail !== null ? ': ' . $detail : '');
		return $status === 401 || $status === 403
			? new AuthenticationException($message, $status)
			: new ICloudException($message, $status);
	}
}
