<?php

declare(strict_types=1);

namespace OCA\AppleSync\Settings;

use OCA\AppleSync\AppInfo\Application;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Service\SyncService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private AccountService $accounts,
		private SyncService $sync,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		$account = $this->accounts->getAccount((string)$this->userId);
		$this->initialState->provideInitialState('account', $this->accounts->describe($account));
		$this->initialState->provideInitialState('sync', $this->sync->describeAll((string)$this->userId));
		Util::addScript(Application::APP_ID, Application::APP_ID . '-personal');
		return new TemplateResponse(Application::APP_ID, 'personal');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
