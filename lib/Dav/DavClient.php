<?php

declare(strict_types=1);

namespace OCA\AppleSync\Dav;

use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Read-only CardDAV/CalDAV client for iCloud, using an app-specific password.
 *
 * Redirects are followed by hand so PROPFIND and REPORT keep their method and
 * body (Guzzle would turn them into GETs). Collections and members are keyed
 * by URL path: Apple can move an account to a different pNN-* host, but the
 * paths stay the same.
 */
class DavClient {
	public const KIND_CONTACTS = 'contacts';
	public const KIND_CALENDARS = 'calendars';

	private const USER_AGENT = 'Nextcloud-apple_sync (CardDAV/CalDAV, read-only)';
	private const MAX_REDIRECTS = 5;

	private ?IClient $client = null;

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Check the credentials against the entry point.
	 *
	 * @throws DavAuthException when Apple rejects them
	 */
	public function verify(DavCredentials $credentials, string $entry): void {
		$this->principal($credentials, $entry);
	}

	/**
	 * Absolute URL of the address book home or calendar home.
	 */
	public function findHome(DavCredentials $credentials, string $entry, string $kind): string {
		[$principalUrl] = $this->principal($credentials, $entry);
		$homeProp = $kind === self::KIND_CONTACTS
			? ['card', MultiStatus::NS_CARDDAV, 'addressbook-home-set']
			: ['cal', MultiStatus::NS_CALDAV, 'calendar-home-set'];
		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:' . $homeProp[0] . '="' . $homeProp[1] . '">'
			. '<d:prop><' . $homeProp[0] . ':' . $homeProp[2] . '/></d:prop></d:propfind>';
		[$url, $responses] = $this->propfind($credentials, $principalUrl, $body, 0);
		foreach ($responses as $response) {
			$href = MultiStatus::href($response['props']['{' . $homeProp[1] . '}' . $homeProp[2]] ?? null);
			if ($href !== null) {
				return self::withSlash(self::resolve($url, $href));
			}
		}
		throw new ICloudException(sprintf('Apple did not report a %s home', $kind === self::KIND_CONTACTS ? 'contacts' : 'calendar'));
	}

	/**
	 * Address books or calendars (with VEVENT) in a home.
	 *
	 * @return list<DavCollection>
	 */
	public function listCollections(DavCredentials $credentials, string $homeUrl, string $kind): array {
		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:cs="' . MultiStatus::NS_CS . '" xmlns:cal="' . MultiStatus::NS_CALDAV . '" xmlns:ical="' . MultiStatus::NS_APPLE . '">'
			. '<d:prop><d:resourcetype/><d:displayname/><cs:getctag/>'
			. ($kind === self::KIND_CALENDARS ? '<cal:supported-calendar-component-set/><ical:calendar-color/>' : '')
			. '</d:prop></d:propfind>';
		[$url, $responses] = $this->propfind($credentials, $homeUrl, $body, 1);
		$wanted = $kind === self::KIND_CONTACTS
			? '{' . MultiStatus::NS_CARDDAV . '}addressbook'
			: '{' . MultiStatus::NS_CALDAV . '}calendar';

		$collections = [];
		foreach ($responses as $response) {
			$props = $response['props'];
			if (!in_array($wanted, MultiStatus::childNames($props['{DAV:}resourcetype'] ?? null), true)) {
				continue;
			}
			$components = MultiStatus::components($props['{' . MultiStatus::NS_CALDAV . '}supported-calendar-component-set'] ?? null);
			if ($kind === self::KIND_CALENDARS && $components !== [] && !in_array('VEVENT', $components, true)) {
				continue; // Reminders lists and the like
			}
			$absolute = self::withSlash(self::resolve($url, $response['href']));
			$collections[] = new DavCollection(
				$absolute,
				self::path($absolute),
				MultiStatus::text($props['{DAV:}displayname'] ?? null) ?? '',
				MultiStatus::text($props['{' . MultiStatus::NS_CS . '}getctag'] ?? null),
				MultiStatus::text($props['{' . MultiStatus::NS_APPLE . '}calendar-color'] ?? null),
				$components,
			);
		}
		return $collections;
	}

	/**
	 * Members of a collection and their etags.
	 *
	 * @return array<string, string> path => etag
	 */
	public function listMembers(DavCredentials $credentials, DavCollection $collection): array {
		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/><d:resourcetype/></d:prop></d:propfind>';
		[$url, $responses] = $this->propfind($credentials, $collection->url, $body, 1);
		$members = [];
		foreach ($responses as $response) {
			$path = self::path(self::resolve($url, $response['href']));
			$etag = MultiStatus::text($response['props']['{DAV:}getetag'] ?? null);
			if ($etag === null || rtrim($path, '/') === rtrim($collection->path, '/')
				|| MultiStatus::childNames($response['props']['{DAV:}resourcetype'] ?? null) !== []) {
				continue;
			}
			$members[$path] = self::normalizeEtag($etag);
		}
		return $members;
	}

	/**
	 * Fetch members' data with addressbook-multiget / calendar-multiget.
	 *
	 * @param list<string> $paths
	 * @return array<string, array{etag: string, data: string}> path => card/object; missing ones are left out
	 */
	public function multiget(DavCredentials $credentials, DavCollection $collection, string $kind, array $paths): array {
		[$prefix, $ns, $report, $dataProp] = $kind === self::KIND_CONTACTS
			? ['card', MultiStatus::NS_CARDDAV, 'addressbook-multiget', 'address-data']
			: ['cal', MultiStatus::NS_CALDAV, 'calendar-multiget', 'calendar-data'];
		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<' . $prefix . ':' . $report . ' xmlns:d="DAV:" xmlns:' . $prefix . '="' . $ns . '">'
			. '<d:prop><d:getetag/><' . $prefix . ':' . $dataProp . '/></d:prop>';
		foreach ($paths as $path) {
			$body .= '<d:href>' . htmlspecialchars($path, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</d:href>';
		}
		$body .= '</' . $prefix . ':' . $report . '>';

		$result = $this->send($credentials, 'REPORT', $collection->url, $body, ['Depth' => '1']);
		$this->expectMultiStatus($result, 'REPORT');
		// Answer under the paths that were asked for, even if Apple encodes them differently.
		$requested = [];
		foreach ($paths as $path) {
			$requested[rawurldecode($path)] = $path;
		}
		$items = [];
		foreach (MultiStatus::parse($result['body']) as $response) {
			$data = $response['props']['{' . $ns . '}' . $dataProp] ?? null;
			$etag = MultiStatus::text($response['props']['{DAV:}getetag'] ?? null);
			if ($data === null || $etag === null) {
				continue;
			}
			$path = self::path(self::resolve($result['url'], $response['href']));
			$items[$requested[rawurldecode($path)] ?? $path] = [
				'etag' => self::normalizeEtag($etag),
				'data' => $data->textContent,
			];
		}
		return $items;
	}

	/**
	 * Strip quotes and the weak marker so etags compare reliably.
	 */
	public static function normalizeEtag(string $etag): string {
		return trim(preg_replace('/^W\//', '', trim($etag)) ?? '', '"');
	}

	public static function resolve(string $base, string $href): string {
		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}
		$parts = parse_url($base);
		$origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($href, '/')) {
			return $origin . $href;
		}
		$dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') ?? '/';
		return $origin . $dir . $href;
	}

	public static function path(string $url): string {
		return parse_url($url, PHP_URL_PATH) ?: '/';
	}

	/**
	 * @return array{0: string, 1: string} principal URL, URL the answer came from
	 */
	private function principal(DavCredentials $credentials, string $entry): array {
		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>';
		[$url, $responses] = $this->propfind($credentials, self::withSlash($entry), $body, 0);
		foreach ($responses as $response) {
			$href = MultiStatus::href($response['props']['{DAV:}current-user-principal'] ?? null);
			if ($href !== null) {
				return [self::resolve($url, $href), $url];
			}
		}
		throw new ICloudException('Apple did not report a DAV principal');
	}

	/**
	 * @return array{0: string, 1: list<array{href: string, status: int, props: array<string, \DOMElement>}>}
	 *                                                                                                          URL after redirects, responses
	 */
	private function propfind(DavCredentials $credentials, string $url, string $body, int $depth): array {
		$result = $this->send($credentials, 'PROPFIND', $url, $body, ['Depth' => (string)$depth]);
		$this->expectMultiStatus($result, 'PROPFIND');
		return [$result['url'], MultiStatus::parse($result['body'])];
	}

	/**
	 * @param array{status: int, body: string, url: string} $result
	 */
	private function expectMultiStatus(array $result, string $method): void {
		if ($result['status'] !== 207) {
			throw new ICloudException(sprintf('iCloud %s %s failed (HTTP %d)', $method, self::path($result['url']), $result['status']), $result['status']);
		}
	}

	/**
	 * @return array{status: int, body: string, url: string}
	 */
	private function send(DavCredentials $credentials, string $method, string $url, string $body, array $headers): array {
		for ($hop = 0; ; $hop++) {
			try {
				$response = $this->client()->request($method, $url, [
					'body' => $body,
					'headers' => $headers + [
						'Content-Type' => 'application/xml; charset=utf-8',
						'User-Agent' => self::USER_AGENT,
					],
					'auth' => [$credentials->appleId, $credentials->password],
					'allow_redirects' => false,
					'http_errors' => false,
					'timeout' => 60,
				]);
			} catch (\Throwable $e) {
				$this->logger->warning('iCloud DAV request failed: {method} {target}: {error}', [
					'method' => $method, 'target' => self::redact($url), 'error' => $e->getMessage(),
				]);
				throw new ICloudException('Could not reach iCloud: ' . $e->getMessage(), 0, $e);
			}
			$status = $response->getStatusCode();
			$this->logger->debug('iCloud DAV {method} {target} -> {status}', [
				'method' => $method, 'target' => self::redact($url), 'status' => $status,
			]);

			if (in_array($status, [301, 302, 307, 308], true) && $hop < self::MAX_REDIRECTS) {
				$location = $response->getHeader('Location');
				if ($location !== '') {
					$url = self::resolve($url, $location);
					continue;
				}
			}
			if ($status === 401) {
				throw new DavAuthException('Apple rejected the app-specific password', $status);
			}
			$content = $response->getBody();
			return [
				'status' => $status,
				'body' => is_resource($content) ? (string)stream_get_contents($content) : (string)$content,
				'url' => $url,
			];
		}
	}

	private function client(): IClient {
		return $this->client ??= $this->clientService->newClient();
	}

	private static function withSlash(string $url): string {
		return str_ends_with($url, '/') ? $url : $url . '/';
	}

	private static function redact(string $url): string {
		$parts = parse_url($url);
		return ($parts['host'] ?? '?') . ($parts['path'] ?? '');
	}
}
