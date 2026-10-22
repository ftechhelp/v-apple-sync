<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Auth;

use GuzzleHttp\Cookie\CookieJar;
use OCA\AppleSync\ICloud\Constants;
use OCA\AppleSync\ICloud\Exception\ICloudException;

/**
 * Everything needed to resume an iCloud web session: Apple's auth headers,
 * tokens, the account's service URLs and the cookie jar.
 *
 * Persisted (encrypted) as JSON between requests and background jobs.
 */
final class WebSession {
	public ?string $scnt = null;
	public ?string $sessionId = null;
	public ?string $sessionToken = null;
	public ?string $trustToken = null;
	public ?string $authAttributes = null;
	public ?string $accountCountry = null;
	public ?string $dsid = null;
	/** @var array<string, string> webservice name => base URL */
	public array $webservices = [];
	public CookieJar $cookies;

	private function __construct(
		public readonly string $clientId,
	) {
		$this->cookies = new CookieJar();
	}

	public static function create(): self {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
		return new self('auth-' . $uuid);
	}

	public static function fromArray(array $data): self {
		$session = new self((string)$data['clientId']);
		foreach (['scnt', 'sessionId', 'sessionToken', 'trustToken', 'authAttributes', 'accountCountry', 'dsid'] as $key) {
			$session->{$key} = isset($data[$key]) ? (string)$data[$key] : null;
		}
		$session->webservices = is_array($data['webservices'] ?? null) ? $data['webservices'] : [];
		$session->cookies = new CookieJar(false, is_array($data['cookies'] ?? null) ? $data['cookies'] : []);
		return $session;
	}

	public function toArray(): array {
		return [
			'clientId' => $this->clientId,
			'scnt' => $this->scnt,
			'sessionId' => $this->sessionId,
			'sessionToken' => $this->sessionToken,
			'trustToken' => $this->trustToken,
			'authAttributes' => $this->authAttributes,
			'accountCountry' => $this->accountCountry,
			'dsid' => $this->dsid,
			'webservices' => $this->webservices,
			'cookies' => $this->cookies->toArray(),
		];
	}

	/**
	 * Base URL of an iCloud service from accountLogin (e.g. "drivews", "ckdatabasews").
	 */
	public function serviceUrl(string $name): string {
		if (!isset($this->webservices[$name])) {
			throw new ICloudException(sprintf('iCloud service "%s" is not available for this account', $name));
		}
		return rtrim($this->webservices[$name], '/');
	}

	/**
	 * Query parameters iCloud expects on every service call.
	 */
	public function serviceParams(): array {
		$params = [
			'clientBuildNumber' => Constants::CLIENT_BUILD_NUMBER,
			'clientMasteringNumber' => Constants::CLIENT_MASTERING_NUMBER,
			'clientId' => $this->clientId,
		];
		if ($this->dsid !== null) {
			$params['dsid'] = $this->dsid;
		}
		return $params;
	}
}
