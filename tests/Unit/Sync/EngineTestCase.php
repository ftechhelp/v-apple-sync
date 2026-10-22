<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Unit\Sync;

use OCA\AppleSync\Dav\DavClient;
use OCA\AppleSync\Dav\DavCredentials;
use OCA\AppleSync\Db\Account;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Endpoints;
use OCA\AppleSync\ICloud\Http\ICloudHttp;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Sync\Engine\FileMirror;
use OCA\AppleSync\Sync\SyncBudget;
use OCA\AppleSync\Sync\SyncContext;
use OCA\AppleSync\Sync\SyncEngine;
use OCA\AppleSync\Tests\Support\FakeICloud;
use OCA\AppleSync\Tests\Support\MemoryFileStore;
use OCA\AppleSync\Tests\Support\MemoryItemMapper;
use OCA\AppleSync\Tests\Support\PsrResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Engines against the fake iCloud, with in-memory Nextcloud stores.
 */
abstract class EngineTestCase extends TestCase {
	protected const BASE = 'https://fake.icloud';
	protected const USER = 'alice';

	protected FakeICloud $icloud;
	protected MemoryItemMapper $items;
	protected MemoryFileStore $files;
	protected int $now = 1_800_000_000;

	protected function setUp(): void {
		$this->icloud = new FakeICloud(self::BASE);
		$this->items = new MemoryItemMapper($this->createMock(IDBConnection::class));
		$this->files = new MemoryFileStore();
	}

	abstract protected function engine(): SyncEngine;

	/**
	 * Run the engine once; with $options['mirrorDeletions'] and so on.
	 */
	protected function sync(SyncState $state, ?SyncBudget $budget = null): bool {
		$context = new SyncContext(self::USER, $state, $budget ?? SyncBudget::unlimited(), static function (): void {
		});
		$this->now += 60;
		return $this->engine()->run($context);
	}

	protected function state(string $service, array $options = [], ?string $target = null): SyncState {
		$state = new SyncState();
		$state->setUserId(self::USER);
		$state->setService($service);
		$state->setEnabled(true);
		$state->setOptionsArray($options);
		$state->setTarget($target);
		return $state;
	}

	/**
	 * Requests made to the fake iCloud matching "METHOD /path-prefix".
	 */
	protected function requests(string $prefix): int {
		return count(array_filter($this->icloud->log, static fn (string $line): bool => str_starts_with($line, $prefix)));
	}

	protected function clientService(): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(function (string $method, string $uri, array $options = []): PsrResponse {
			$parts = parse_url($uri);
			parse_str($parts['query'] ?? '', $query);
			$headers = array_change_key_case($options['headers'] ?? [], CASE_LOWER);
			if (isset($options['auth'])) {
				$headers['authorization'] = 'Basic ' . base64_encode($options['auth'][0] . ':' . $options['auth'][1]);
			}
			$body = isset($options['json']) ? json_encode($options['json']) : (string)($options['body'] ?? '');
			[$status, $responseHeaders, $responseBody] = $this->icloud->handle($method, $parts['path'] ?? '/', $query, $headers, $body);
			if (!str_starts_with($uri, self::BASE)) {
				[$status, $responseHeaders, $responseBody] = [404, [], 'wrong host'];
			}
			return new PsrResponse($status, $responseHeaders, $responseBody);
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}

	protected function endpoints(): Endpoints {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn(['contacts' => self::BASE . '/carddav', 'caldav' => self::BASE . '/caldav']);
		return new Endpoints($config);
	}

	protected function dav(): DavClient {
		return new DavClient($this->clientService(), new NullLogger());
	}

	protected function http(): ICloudHttp {
		return new ICloudHttp($this->clientService(), new NullLogger());
	}

	protected function accounts(string $appPassword = FakeICloud::APP_PASSWORD): AccountService {
		$session = WebSession::create();
		$session->dsid = FakeICloud::DSID;
		$session->webservices = [
			'drivews' => self::BASE . '/drivews',
			'docws' => self::BASE . '/docws',
			'ckdatabasews' => self::BASE . '/ckdatabasews',
		];
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getAccount')->willReturn(new Account());
		$accounts->method('davCredentials')->willReturn(new DavCredentials('me@icloud.com', $appPassword));
		$accounts->method('ensureWebSession')->willReturn($session);
		return $accounts;
	}

	protected function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		return $time;
	}

	protected function mirror(): FileMirror {
		return new FileMirror($this->files, $this->items);
	}
}
