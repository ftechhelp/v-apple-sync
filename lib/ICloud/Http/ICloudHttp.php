<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Http;

use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Constants;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * HTTP transport for the iCloud web API.
 *
 * - keeps cookies in the WebSession's jar
 * - captures Apple's session headers (scnt, session id/token, trust token...)
 * - never logs bodies, headers, cookies or query strings, only method/host/path/status
 */
class ICloudHttp {
	/** Response header => WebSession property */
	private const CAPTURED_HEADERS = [
		'scnt' => 'scnt',
		'X-Apple-ID-Session-Id' => 'sessionId',
		'X-Apple-Session-Token' => 'sessionToken',
		'X-Apple-TwoSV-Trust-Token' => 'trustToken',
		'X-Apple-ID-Account-Country' => 'accountCountry',
		'X-Apple-Auth-Attributes' => 'authAttributes',
	];

	private const MAX_RETRY_WAIT = 60;

	private ?IClient $client = null;

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{headers?: array<string, string>, json?: mixed, body?: string, stream?: bool, timeout?: int, retries?: int} $options
	 *                                                                                                                     retries: extra attempts on 429/503 (default 0)
	 */
	public function request(WebSession $session, string $method, string $url, array $options = []): IResponse {
		$retries = $options['retries'] ?? 0;
		unset($options['retries']);

		$options['headers'] = ($options['headers'] ?? []) + ['User-Agent' => Constants::USER_AGENT];
		$options['cookies'] = $session->cookies;
		$options['http_errors'] = false;
		$options['timeout'] ??= 60;

		for ($attempt = 0; ; $attempt++) {
			try {
				$response = $this->client()->request($method, $url, $options);
			} catch (\Throwable $e) {
				$this->logger->warning('iCloud request failed: {method} {target}: {error}', [
					'method' => $method, 'target' => self::redact($url), 'error' => $e->getMessage(),
				]);
				throw new ICloudException('Could not reach iCloud: ' . $e->getMessage(), 0, $e);
			}

			$status = $response->getStatusCode();
			$this->logger->debug('iCloud {method} {target} -> {status}', [
				'method' => $method, 'target' => self::redact($url), 'status' => $status,
			]);
			$this->captureHeaders($session, $response);

			if (($status === 429 || $status === 503) && $attempt < $retries) {
				$wait = min(self::MAX_RETRY_WAIT, max(1, (int)$response->getHeader('Retry-After') ?: 2 ** ($attempt + 1)));
				sleep($wait);
				continue;
			}
			return $response;
		}
	}

	/**
	 * Call an iCloud web service (drivews, docws, ckdatabasews...) with the
	 * query parameters and headers www.icloud.com sends.
	 *
	 * @param array<string, string> $query extra query parameters
	 */
	public function service(WebSession $session, string $method, string $url, array $query = [], array $options = []): IResponse {
		$url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($session->serviceParams() + $query);
		$options['headers'] = ($options['headers'] ?? []) + [
			'Accept' => 'application/json',
			'Origin' => Constants::HOME_ENDPOINT,
			'Referer' => Constants::HOME_ENDPOINT . '/',
		];
		$options['retries'] ??= 2;
		return $this->request($session, $method, $url, $options);
	}

	/**
	 * Open a file download from iCloud's content servers as a stream.
	 *
	 * @return resource
	 */
	public function download(WebSession $session, string $url) {
		$response = $this->request($session, 'GET', $url, [
			'stream' => true,
			'timeout' => 300,
			'retries' => 2,
			'headers' => ['Origin' => Constants::HOME_ENDPOINT, 'Referer' => Constants::HOME_ENDPOINT . '/'],
		]);
		$status = $response->getStatusCode();
		if ($status !== 200) {
			throw new ICloudException(sprintf('iCloud refused the download (HTTP %d)', $status), $status);
		}
		$body = $response->getBody();
		if (!is_resource($body)) {
			$stream = fopen('php://temp', 'w+b');
			fwrite($stream, (string)$body);
			rewind($stream);
			return $stream;
		}
		return $body;
	}

	/**
	 * Decode a JSON response body; empty bodies decode to [].
	 */
	public static function json(IResponse $response): array {
		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		if ($body === '' || $body === null) {
			return [];
		}
		$data = json_decode((string)$body, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Apple's error message from a JSON error response, if any.
	 */
	public static function errorMessage(array $data): ?string {
		foreach (['serviceErrors', 'service_errors', 'validationErrors'] as $key) {
			if (!empty($data[$key][0]['message'])) {
				return (string)$data[$key][0]['message'];
			}
		}
		foreach (['error', 'reason', 'errorMessage'] as $key) {
			if (!empty($data[$key]) && is_string($data[$key])) {
				return $data[$key];
			}
		}
		return null;
	}

	/**
	 * Apple's error codes from a JSON error response (e.g. "-21669").
	 *
	 * @return list<string>
	 */
	public static function errorCodes(array $data): array {
		$codes = [];
		foreach (['serviceErrors', 'service_errors', 'validationErrors'] as $key) {
			foreach ($data[$key] ?? [] as $error) {
				if (isset($error['code'])) {
					$codes[] = (string)$error['code'];
				}
			}
		}
		return $codes;
	}

	private function captureHeaders(WebSession $session, IResponse $response): void {
		foreach (self::CAPTURED_HEADERS as $header => $property) {
			$value = $response->getHeader($header);
			if ($value !== '') {
				$session->{$property} = $value;
			}
		}
	}

	private function client(): IClient {
		return $this->client ??= $this->clientService->newClient();
	}

	/**
	 * Host and path only: query strings can carry dsid and tokens. Long path
	 * segments (signed download tokens) are cut too.
	 */
	private static function redact(string $url): string {
		$parts = parse_url($url);
		return ($parts['host'] ?? '?') . preg_replace('#/[^/]{32,}#', '/…', $parts['path'] ?? '');
	}
}
