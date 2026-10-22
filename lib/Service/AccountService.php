<?php

declare(strict_types=1);

namespace OCA\AppleSync\Service;

use OCA\AppleSync\Dav\DavCredentials;
use OCA\AppleSync\Db\Account;
use OCA\AppleSync\Db\AccountMapper;
use OCA\AppleSync\Db\ItemMapper;
use OCA\AppleSync\Db\SyncStateMapper;
use OCA\AppleSync\ICloud\Auth\AuthService;
use OCA\AppleSync\ICloud\Auth\SignInStatus;
use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Constants;
use OCA\AppleSync\ICloud\Exception\AuthenticationException;
use OCA\AppleSync\ICloud\Exception\ReauthRequiredException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Persistence of the linked Apple account and its iCloud web session.
 */
class AccountService {
	/** How long a sign-in may wait for its 2FA code. */
	private const PENDING_TTL = 15 * 60;

	public function __construct(
		private AccountMapper $accountMapper,
		private SyncStateMapper $stateMapper,
		private ItemMapper $itemMapper,
		private SecretService $secrets,
		private AuthService $auth,
		private NotificationService $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function getAccount(string $userId): Account {
		return $this->accountMapper->findOrCreate($userId);
	}

	public function loadWebSession(Account $account): ?WebSession {
		$data = $this->secrets->decryptJson($account->getWebSessionEnc());
		return $data === null ? null : WebSession::fromArray($data);
	}

	public function saveWebSession(Account $account, WebSession $session): void {
		$account->setWebSessionEnc($this->secrets->encryptJson($session->toArray()));
		$account->setDsid($session->dsid);
		$this->accountMapper->update($account);
	}

	public function getPassword(Account $account): ?string {
		return $this->secrets->decrypt($account->getPasswordEnc());
	}

	/**
	 * Remember a sign-in that is waiting for its 2FA code. Saving again (after
	 * each 2FA step changed the session) restarts the expiry.
	 */
	public function savePendingSignIn(Account $account, WebSession $session, string $appleId, string $password): void {
		$previous = $account->getWebState();
		if ($previous === Account::WEB_NEEDS_2FA) {
			$previous = $this->rawPending($account)['previousState'] ?? $this->fallbackState($account);
		}
		$account->setPendingAuthEnc($this->secrets->encryptJson([
			'session' => $session->toArray(),
			'appleId' => $appleId,
			'password' => $password,
			'expiresAt' => $this->time->getTime() + self::PENDING_TTL,
			'previousState' => $previous,
		]));
		$account->setWebState(Account::WEB_NEEDS_2FA);
		$this->accountMapper->update($account);
	}

	/**
	 * @return array{session: WebSession, appleId: string, password: string}|null null when absent or expired
	 */
	public function loadPendingSignIn(Account $account): ?array {
		$data = $this->rawPending($account);
		if ($data === null || ($data['expiresAt'] ?? 0) < $this->time->getTime()) {
			return null;
		}
		return [
			'session' => WebSession::fromArray($data['session']),
			'appleId' => (string)$data['appleId'],
			'password' => (string)$data['password'],
		];
	}

	/**
	 * Abandon a sign-in waiting for its 2FA code; the account goes back to
	 * whatever state it had before.
	 */
	public function cancelPendingSignIn(Account $account): void {
		$account->setWebState($this->rawPending($account)['previousState'] ?? $this->fallbackState($account));
		$account->setPendingAuthEnc(null);
		$this->accountMapper->update($account);
	}

	/**
	 * Forget the iCloud web credentials and session. Sync state and synced
	 * data stay, so reconnecting doesn't download everything again.
	 */
	public function disconnectWeb(Account $account): void {
		$account->setAppleId(null);
		$account->setPasswordEnc(null);
		$account->setWebSessionEnc(null);
		$account->setPendingAuthEnc(null);
		$account->setDsid(null);
		$account->setTrustExpiresAt(null);
		$account->setLastAuthAt(null);
		$account->setLastError(null);
		$account->setWebState(Account::WEB_DISCONNECTED);
		$this->accountMapper->update($account);
	}

	/**
	 * What the settings page shows. Never contains secrets.
	 */
	public function describe(Account $account): array {
		$state = $account->getWebState();
		$appleId = $account->getAppleId();
		if ($state === Account::WEB_NEEDS_2FA) {
			$pending = $this->rawPending($account);
			if ($pending === null || ($pending['expiresAt'] ?? 0) < $this->time->getTime()) {
				$state = $pending['previousState'] ?? $this->fallbackState($account);
			} else {
				$appleId = (string)$pending['appleId'];
			}
		}
		return [
			'appleId' => $appleId,
			'webState' => $state,
			'davAppleId' => $account->getDavAppleId(),
			'davState' => $account->getDavState(),
			'trustExpiresAt' => $account->getTrustExpiresAt(),
			'lastAuthAt' => $account->getLastAuthAt(),
			'lastError' => $account->getLastError(),
			'hasStoredPassword' => $account->getPasswordEnc() !== null,
		];
	}

	private function rawPending(Account $account): ?array {
		try {
			return $this->secrets->decryptJson($account->getPendingAuthEnc());
		} catch (\Throwable) {
			return null;
		}
	}

	private function fallbackState(Account $account): string {
		return $account->getWebSessionEnc() !== null ? Account::WEB_REAUTH_REQUIRED : Account::WEB_DISCONNECTED;
	}

	/**
	 * Store a fully signed-in session and the credentials needed to renew it.
	 */
	public function completeSignIn(Account $account, WebSession $session, string $appleId, string $password, bool $freshTrust): void {
		$now = $this->time->getTime();
		$account->setAppleId($appleId);
		$account->setPasswordEnc($this->secrets->encrypt($password));
		$account->setPendingAuthEnc(null);
		$account->setWebState(Account::WEB_CONNECTED);
		$account->setLastAuthAt($now);
		$account->setLastError(null);
		if ($freshTrust || $account->getTrustExpiresAt() === null) {
			$account->setTrustExpiresAt($now + Constants::TRUST_TOKEN_LIFETIME);
		}
		$this->saveWebSession($account, $session);
		$this->notifications->dismiss($account->getUserId(), NotificationService::REAUTH_REQUIRED);
	}

	/**
	 * Store CardDAV/CalDAV credentials that were checked against Apple.
	 */
	public function connectDav(Account $account, string $appleId, #[\SensitiveParameter] string $password): void {
		$account->setDavAppleId($appleId);
		$account->setAppPasswordEnc($this->secrets->encrypt($password));
		$account->setDavState(Account::DAV_CONNECTED);
		$this->accountMapper->update($account);
		$this->notifications->dismiss($account->getUserId(), NotificationService::DAV_REJECTED);
	}

	/**
	 * Forget the app-specific password. Synced contacts and calendars stay.
	 */
	public function disconnectDav(Account $account): void {
		$account->setDavAppleId(null);
		$account->setAppPasswordEnc(null);
		$account->setDavState(Account::DAV_DISCONNECTED);
		$this->accountMapper->update($account);
		$this->notifications->dismiss($account->getUserId(), NotificationService::DAV_REJECTED);
	}

	public function davCredentials(Account $account): ?DavCredentials {
		$password = $this->secrets->decrypt($account->getAppPasswordEnc());
		if ($password === null || $account->getDavAppleId() === null) {
			return null;
		}
		return new DavCredentials($account->getDavAppleId(), $password);
	}

	/**
	 * Apple answered 401 during a sync: stop syncing contacts and calendars
	 * until the user enters a new app-specific password.
	 */
	public function markDavRejected(Account $account): void {
		$account->setDavState(Account::DAV_ERROR);
		$this->accountMapper->update($account);
		$this->notifications->notify($account->getUserId(), NotificationService::DAV_REJECTED);
	}

	/**
	 * A usable iCloud web session for background work: validates the stored
	 * cookies, and if they expired signs in again with the stored password and
	 * trust token. Never prompts; throws when the user has to step in.
	 *
	 * @throws ReauthRequiredException
	 */
	public function ensureWebSession(string $userId): WebSession {
		$account = $this->getAccount($userId);
		$session = $this->loadWebSession($account);
		if ($session === null || $account->getWebState() === Account::WEB_DISCONNECTED) {
			throw new ReauthRequiredException('iCloud is not connected');
		}
		if ($account->getWebState() === Account::WEB_REAUTH_REQUIRED) {
			throw new ReauthRequiredException('iCloud needs a new two-factor verification');
		}

		if ($this->auth->validate($session)) {
			$this->saveWebSession($account, $session);
			return $session;
		}

		$password = $this->getPassword($account);
		if ($password === null || $account->getAppleId() === null) {
			$this->markReauthRequired($account, 'No stored credentials');
			throw new ReauthRequiredException('iCloud needs to be connected again');
		}

		$this->logger->info('iCloud session expired for {user}, signing in again', ['user' => $userId]);
		try {
			$status = $this->auth->signIn($session, $account->getAppleId(), $password);
		} catch (AuthenticationException $e) {
			$this->markReauthRequired($account, $e->getMessage());
			throw new ReauthRequiredException($e->getMessage(), $e->getHttpStatus(), $e);
		}
		if ($status === SignInStatus::NeedsTwoFactor) {
			// The routine monthly case: no error to show beyond the state itself.
			$this->markReauthRequired($account, null);
			throw new ReauthRequiredException('iCloud needs a new two-factor verification');
		}

		$account->setLastAuthAt($this->time->getTime());
		$this->saveWebSession($account, $session);
		return $session;
	}

	public function markReauthRequired(Account $account, ?string $reason): void {
		$account->setWebState(Account::WEB_REAUTH_REQUIRED);
		$account->setLastError($reason);
		$this->accountMapper->update($account);
		$this->notifications->notify($account->getUserId(), NotificationService::REAUTH_REQUIRED);
	}

	/**
	 * Forget everything about the user's Apple account (secrets, sessions, sync state).
	 * Synced data in Nextcloud is left alone.
	 */
	public function purge(string $userId): void {
		foreach ([NotificationService::REAUTH_REQUIRED, NotificationService::DAV_REJECTED] as $subject) {
			$this->notifications->dismiss($userId, $subject);
		}
		$this->itemMapper->deleteByUser($userId);
		$this->stateMapper->deleteByUser($userId);
		$this->accountMapper->deleteByUser($userId);
	}
}
