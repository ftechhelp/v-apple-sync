<?php

declare(strict_types=1);

namespace OCA\AppleSync\Controller;

use OCA\AppleSync\Dav\DavAuthException;
use OCA\AppleSync\Dav\DavClient;
use OCA\AppleSync\Dav\DavCredentials;
use OCA\AppleSync\ICloud\Endpoints;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Service\SyncService;
use OCA\AppleSync\Sync\RunOutcome;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncRunner;
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
 * The settings page's sync controls: the CardDAV/CalDAV app-specific
 * password, and per service the switch, target folder, deletion option,
 * "Sync now" and status.
 */
class SyncController extends Controller {
	/** "Sync now" runs inside the web request; the page calls again while work is left. */
	private const STEP_SECONDS = 20;
	private const STEP_BYTES = 300 * 1024 * 1024;

	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $sync,
		private SyncRunner $runner,
		private AccountService $accounts,
		private DavClient $dav,
		private Endpoints $endpoints,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/sync')]
	public function index(): JSONResponse {
		return new JSONResponse($this->sync->describeAll($this->uid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/sync/{service}')]
	public function update(string $service, ?bool $enabled = null, ?string $target = null, ?bool $mirrorDeletions = null): JSONResponse {
		try {
			$state = $this->sync->update($this->uid(), $service, $enabled, $target, $mirrorDeletions);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->sync->describe($state));
	}

	/**
	 * Run one step of the service's sync now. The answer says whether work is
	 * left; background jobs continue it too when the page is closed.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 900, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/sync/{service}/run')]
	public function run(string $service): JSONResponse {
		try {
			$state = $this->sync->state($this->uid(), $service);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if (!$state->getEnabled()) {
			return new JSONResponse(['message' => $this->l->t('Turn this on first.')], Http::STATUS_BAD_REQUEST);
		}
		@set_time_limit(self::STEP_SECONDS * 6);
		$outcome = $this->runner->run($this->uid(), $service, SyncBudget::seconds(self::STEP_SECONDS, self::STEP_BYTES));
		if ($outcome === RunOutcome::Partial) {
			$this->sync->queue($this->uid(), $service);
		}
		return new JSONResponse([
			'outcome' => $outcome->value,
			'service' => $this->sync->describe($this->sync->state($this->uid(), $service)),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/sync/{service}/reset')]
	public function reset(string $service): JSONResponse {
		try {
			$state = $this->sync->reset($this->uid(), $service);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->sync->describe($state));
	}

	/**
	 * Check an app-specific password against Apple and store it.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/dav/connect')]
	public function connectDav(string $appleId = '', string $password = ''): JSONResponse {
		$account = $this->accounts->getAccount($this->uid());
		$appleId = trim($appleId);
		$password = preg_replace('/\s+/', '', $password) ?? '';
		if ($appleId === '' || $password === '') {
			return $this->error($this->l->t('Enter your Apple ID and an app-specific password.'), Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->dav->verify(new DavCredentials($appleId, $password), $this->endpoints->contacts);
		} catch (DavAuthException) {
			return $this->error($this->l->t('Apple did not accept this Apple ID and app-specific password.'), Http::STATUS_BAD_REQUEST);
		} catch (ICloudException $e) {
			$this->logger->warning('iCloud CardDAV check failed: {message}', ['message' => $e->getMessage()]);
			return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
		}
		$this->accounts->connectDav($account, $appleId, $password);
		return new JSONResponse($this->accounts->describe($account));
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/dav')]
	public function disconnectDav(): JSONResponse {
		$account = $this->accounts->getAccount($this->uid());
		$this->accounts->disconnectDav($account);
		return new JSONResponse($this->accounts->describe($account));
	}

	private function uid(): string {
		return (string)$this->userId;
	}

	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['message' => $message] + $this->accounts->describe($this->accounts->getAccount($this->uid())), $status);
	}
}
