<?php

declare(strict_types=1);

namespace OCA\AppleSync\Controller;

use OCA\AppleSync\Db\Account;
use OCA\AppleSync\ICloud\Auth\AuthService;
use OCA\AppleSync\ICloud\Auth\SignInStatus;
use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Exception\AuthenticationException;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\ICloud\Exception\WrongCodeException;
use OCA\AppleSync\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Connecting the iCloud web API (Photos, Drive, Reminders) from the settings page:
 * Apple ID + password, then the 2FA code from a trusted device or a text message.
 *
 * Rate limits keep a misbehaving client from getting the Apple ID locked.
 */
class ConnectController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private AuthService $auth,
		private AccountService $accounts,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/status')]
	public function status(): JSONResponse {
		return $this->describe();
	}

	/**
	 * Start the sign-in. An empty password reuses the stored one, so
	 * "Reconnect" only asks for a new 2FA code.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/web/connect')]
	public function connect(string $appleId = '', string $password = ''): JSONResponse {
		$account = $this->account();
		$appleId = trim($appleId);
		$sameAccount = $appleId !== '' && strcasecmp($appleId, (string)$account->getAppleId()) === 0;
		if ($password === '' && $sameAccount) {
			$password = $this->accounts->getPassword($account) ?? '';
		}
		if ($appleId === '' || $password === '') {
			return $this->error($this->l->t('Enter your Apple ID and password.'), Http::STATUS_BAD_REQUEST);
		}

		// Reconnecting the same Apple ID keeps the old session: its trust token
		// may still let Apple skip the second factor.
		$session = $sameAccount ? $this->accounts->loadWebSession($account) : null;
		$session ??= WebSession::create();

		try {
			$status = $this->auth->signIn($session, $appleId, $password);
			if ($status === SignInStatus::NeedsTwoFactor) {
				$this->auth->requestTrustedDeviceCode($session);
				$this->accounts->savePendingSignIn($account, $session, $appleId, $password);
			} else {
				$this->accounts->completeSignIn($account, $session, $appleId, $password, false);
			}
		} catch (AuthenticationException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (ICloudException $e) {
			return $this->appleError($e);
		}
		return $this->describe();
	}

	/**
	 * Verify the 2FA code. With a phoneId the code came by text message,
	 * otherwise from a trusted device.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 15, period: 900)]
	#[FrontpageRoute(verb: 'POST', url: '/api/web/verify')]
	public function verify(string $code = '', ?int $phoneId = null): JSONResponse {
		$account = $this->account();
		$pending = $this->accounts->loadPendingSignIn($account);
		if ($pending === null) {
			return $this->expired($account);
		}
		$code = preg_replace('/\D/', '', $code) ?? '';
		if ($code === '') {
			return $this->error($this->l->t('Enter the verification code.'), Http::STATUS_BAD_REQUEST);
		}

		$session = $pending['session'];
		try {
			if ($phoneId !== null) {
				$this->auth->verifySmsCode($session, $phoneId, $code);
			} else {
				$this->auth->verifyTrustedDeviceCode($session, $code);
			}
		} catch (WrongCodeException) {
			$this->accounts->savePendingSignIn($account, $session, $pending['appleId'], $pending['password']);
			return $this->error($this->l->t('That code was not accepted. Check it and try again, or request a new one.'), Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (AuthenticationException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (ICloudException $e) {
			return $this->appleError($e);
		}

		$this->accounts->completeSignIn($account, $session, $pending['appleId'], $pending['password'], true);
		return $this->describe();
	}

	/**
	 * Ask Apple to show a new code on the trusted devices.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 900)]
	#[FrontpageRoute(verb: 'POST', url: '/api/web/resend')]
	public function resend(): JSONResponse {
		return $this->withPending(function (array $pending): void {
			$this->auth->requestTrustedDeviceCode($pending['session']);
		});
	}

	/**
	 * Trusted phone numbers (masked by Apple) for the "text me instead" option.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 900)]
	#[FrontpageRoute(verb: 'GET', url: '/api/web/phones')]
	public function phones(): JSONResponse {
		$phones = [];
		$response = $this->withPending(function (array $pending) use (&$phones): void {
			$phones = $this->auth->getTrustedPhoneNumbers($pending['session']);
		});
		if ($response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}
		return new JSONResponse(['phones' => $phones]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 5, period: 900)]
	#[FrontpageRoute(verb: 'POST', url: '/api/web/sms')]
	public function sms(int $phoneId): JSONResponse {
		return $this->withPending(function (array $pending) use ($phoneId): void {
			$this->auth->requestSmsCode($pending['session'], $phoneId);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/web/cancel')]
	public function cancel(): JSONResponse {
		$this->accounts->cancelPendingSignIn($this->account());
		return $this->describe();
	}

	/**
	 * Check the stored session against Apple, renewing it if it expired.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 900)]
	#[FrontpageRoute(verb: 'POST', url: '/api/web/check')]
	public function check(): JSONResponse {
		try {
			$this->accounts->ensureWebSession((string)$this->userId);
		} catch (AuthenticationException $e) {
			return $this->error($e->getMessage(), Http::STATUS_CONFLICT);
		} catch (ICloudException $e) {
			return $this->appleError($e);
		}
		return $this->describe();
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/web')]
	public function disconnect(): JSONResponse {
		$this->accounts->disconnectWeb($this->account());
		return $this->describe();
	}

	/**
	 * Run a step of a pending sign-in, saving the session afterwards (Apple
	 * rotates scnt and cookies on every call).
	 *
	 * @param callable(array{session: WebSession, appleId: string, password: string}): void $step
	 */
	private function withPending(callable $step): JSONResponse {
		$account = $this->account();
		$pending = $this->accounts->loadPendingSignIn($account);
		if ($pending === null) {
			return $this->expired($account);
		}
		try {
			$step($pending);
		} catch (ICloudException $e) {
			return $this->appleError($e);
		} finally {
			$this->accounts->savePendingSignIn($account, $pending['session'], $pending['appleId'], $pending['password']);
		}
		return $this->describe();
	}

	private function account(): Account {
		return $this->accounts->getAccount((string)$this->userId);
	}

	private function describe(): JSONResponse {
		return new JSONResponse($this->accounts->describe($this->account()));
	}

	private function expired(Account $account): JSONResponse {
		$this->accounts->cancelPendingSignIn($account);
		return $this->error($this->l->t('The sign-in expired. Please start again.'), Http::STATUS_GONE);
	}

	private function appleError(ICloudException $e): JSONResponse {
		$this->logger->warning('iCloud sign-in step failed: {message}', ['message' => $e->getMessage()]);
		return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
	}

	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['message' => $message] + $this->accounts->describe($this->account()), $status);
	}
}
